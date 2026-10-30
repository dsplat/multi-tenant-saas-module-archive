<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderRegistryContract;
use MultiTenantSaas\Modules\Archive\Dto\DocActor;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveException;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveProviderFailure;
use MultiTenantSaas\Modules\Archive\Exceptions\DocProviderException;
use MultiTenantSaas\Modules\Archive\Models\DocMoveOperation;
use Throwable;

/**
 * 目录批量移动的持久化操作清单。
 *
 * 设计要点：
 * - 先写后调：所有 pending 意图在一个短事务中提交后，才开始 provider 移动；
 *   不把网络调用包在 DB 事务里（避免长事务/锁），崩溃时清单仍在。
 * - 账本唯一事实源：补偿/恢复只读本表，进程内数组不得作为唯一依据。
 * - 批次终结：成功移动后由目录路径事务统一写 completed_at；未终结批次
 *   （pending/failed/moved 且 completed_at 为 NULL）由 incompleteBatches 暴露。
 * - 错误脱敏：只落受控 reason（业务/Provider reason 或异常类名），不落原始响应。
 */
final class DocMoveOperationService
{
    public const MAX_FILES_CONFIG = 'archive.move.max_files';

    public const DEFAULT_MAX_FILES = 100;

    public function __construct(
        private readonly DocProviderRegistryContract $providers,
    ) {}

    public function maxFiles(): int
    {
        $configured = (int) config(self::MAX_FILES_CONFIG, self::DEFAULT_MAX_FILES);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_FILES;
    }

    /**
     * 写入整批 pending 意图；短事务提交后返回 batch_id。
     *
     * @param  list<array{provider_file_ref: string, from_path: string, to_path: string}>  $plan
     */
    public function beginBatch(int $tenantId, int $catalogId, string $provider, array $plan): string
    {
        $batchId = 'mv-' . Str::uuid()->toString();

        DB::transaction(function () use ($tenantId, $catalogId, $provider, $plan, $batchId): void {
            foreach ($plan as $position => $entry) {
                DocMoveOperation::query()->create([
                    'tenant_id' => $tenantId,
                    'catalog_id' => $catalogId,
                    'batch_id' => $batchId,
                    'provider' => $provider,
                    'from_path' => $entry['from_path'],
                    'to_path' => $entry['to_path'],
                    'provider_file_ref' => $entry['provider_file_ref'],
                    'position' => $position,
                    'status' => DocMoveOperation::STATUS_PENDING,
                    'attempts' => 0,
                ]);
            }
        });

        return $batchId;
    }

    /**
     * @return Collection<int, DocMoveOperation>
     */
    public function operationsForBatch(int $tenantId, string $batchId): Collection
    {
        return DocMoveOperation::query()
            ->where('tenant_id', $tenantId)
            ->where('batch_id', $batchId)
            ->orderBy('position')
            ->orderBy('operation_id')
            ->get();
    }

    /**
     * 每次真正发起 provider 调用前持久化尝试次数（崩溃后仍可见）。
     */
    public function markAttempt(DocMoveOperation $operation): void
    {
        $operation->attempts = (int) $operation->attempts + 1;
        $operation->save();
    }

    public function markMoved(DocMoveOperation $operation, ?string $providerFileRef): void
    {
        $operation->status = DocMoveOperation::STATUS_MOVED;
        $operation->error = null;

        if ($providerFileRef !== null && $providerFileRef !== '') {
            $operation->provider_file_ref = $providerFileRef;
        }

        $operation->save();
    }

    public function markFailed(DocMoveOperation $operation, Throwable $exception, string $context = 'move_failed'): void
    {
        $operation->status = DocMoveOperation::STATUS_FAILED;
        $operation->error = $this->summarize($exception, $context);
        $operation->save();
    }

    public function markRolledBack(DocMoveOperation $operation): void
    {
        $operation->status = DocMoveOperation::STATUS_ROLLED_BACK;
        $operation->error = null;
        $operation->completed_at = now();
        $operation->save();
    }

    public function markRollbackFailed(DocMoveOperation $operation, Throwable $exception): void
    {
        // 文件仍在目标位置：保持 moved，错误留在账本供后续人工/再次回滚。
        $operation->error = $this->summarize($exception, 'rollback_failed');
        $operation->save();
    }

    /**
     * 目录路径事务提交后统一终结批次；所有条目必须已 moved 才算完成。
     */
    public function completeBatch(int $tenantId, string $batchId): void
    {
        DocMoveOperation::query()
            ->where('tenant_id', $tenantId)
            ->where('batch_id', $batchId)
            ->where('status', DocMoveOperation::STATUS_MOVED)
            ->update([
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * 立即补偿：按批内逆序回滚所有 moved 条目；失败只记录，不掩盖原始异常。
     *
     * @return list<DocMoveOperation> 回滚失败的条目
     */
    public function rollbackBatch(int $tenantId, string $batchId): array
    {
        $operations = DocMoveOperation::query()
            ->where('tenant_id', $tenantId)
            ->where('batch_id', $batchId)
            ->where('status', DocMoveOperation::STATUS_MOVED)
            ->orderByDesc('position')
            ->orderByDesc('operation_id')
            ->get();

        $failures = [];

        foreach ($operations as $operation) {
            try {
                $this->rollbackMoved($tenantId, $operation);
            } catch (Throwable $exception) {
                $failures[] = $operation;
                Log::warning('archive.catalog_move_rollback_failed', [
                    'tenant_id' => $tenantId,
                    'batch_id' => $batchId,
                    'operation_id' => $operation->operation_id,
                    'from' => $operation->from_path,
                    'to' => $operation->to_path,
                    'error' => $this->summarize($exception, 'rollback_failed'),
                ]);
            }
        }

        return $failures;
    }

    /**
     * 列出某租户未终结的移动批次及其全部条目。
     *
     * 未终结 = 批次内存在 completed_at 为 NULL 且状态为 pending/failed/moved 的条目。
     * 返回该批次的全部条目（含已 rolled_back 的），便于判断半移动范围。
     *
     * @return list<array{
     *     batch_id: string,
     *     catalog_id: int,
     *     provider: string,
     *     total: int,
     *     counts: array<string, int>,
     *     entries: Collection<int, DocMoveOperation>,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>
     */
    public function incompleteBatches(int $tenantId, DocActor $actor): array
    {
        $this->assertTenant($tenantId);
        app(DocAccessService::class)->assertCanManageCatalogs($actor);

        $batchIds = DocMoveOperation::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('completed_at')
            ->whereIn('status', [
                DocMoveOperation::STATUS_PENDING,
                DocMoveOperation::STATUS_FAILED,
                DocMoveOperation::STATUS_MOVED,
            ])
            ->distinct()
            ->pluck('batch_id')
            ->all();

        if ($batchIds === []) {
            return [];
        }

        $operations = DocMoveOperation::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('batch_id', $batchIds)
            ->orderBy('batch_id')
            ->orderBy('position')
            ->orderBy('operation_id')
            ->get();

        $batches = [];

        foreach ($operations->groupBy('batch_id') as $batchId => $entries) {
            $counts = [
                DocMoveOperation::STATUS_PENDING => 0,
                DocMoveOperation::STATUS_MOVED => 0,
                DocMoveOperation::STATUS_FAILED => 0,
                DocMoveOperation::STATUS_ROLLED_BACK => 0,
            ];

            foreach ($entries as $entry) {
                $counts[$entry->status] = ($counts[$entry->status] ?? 0) + 1;
            }

            $first = $entries->first();
            $last = $entries->last();

            $batches[] = [
                'batch_id' => (string) $batchId,
                'catalog_id' => $first !== null ? (int) $first->catalog_id : 0,
                'provider' => $first !== null ? (string) $first->provider : '',
                'total' => $entries->count(),
                'counts' => $counts,
                'entries' => $entries->values(),
                'created_at' => $first?->created_at,
                'updated_at' => $last?->updated_at,
            ];
        }

        return $batches;
    }

    /**
     * 按条目重试：只处理 pending/failed；已 moved 直接返回，不重复移动。
     */
    public function retry(int $tenantId, int|string $operationId, DocActor $actor): DocMoveOperation
    {
        $this->assertTenant($tenantId);
        app(DocAccessService::class)->assertCanManageCatalogs($actor);

        $operation = $this->find($tenantId, $operationId);

        if (in_array($operation->status, [DocMoveOperation::STATUS_MOVED, DocMoveOperation::STATUS_ROLLED_BACK], true)
            || $operation->completed_at !== null) {
            return $operation;
        }

        $this->markAttempt($operation);

        try {
            $provider = $this->provider($tenantId, (string) $operation->provider);
            $file = $this->providerCall(fn () => $provider->moveFile(
                $this->sourceRef($operation),
                (string) $operation->to_path,
            ));

            $this->markMoved($operation, $file->ref);
        } catch (Throwable $exception) {
            $this->markFailed($operation, $exception, 'retry_failed');

            throw $exception;
        }

        return $operation->refresh();
    }

    /**
     * 按条目回滚：文件移回 from_path。
     *
     * - moved：用移动成功后的 provider 标识移回；
     * - pending/failed：尝试以当前标识移回（若从未移动，等价 self-move/no-op）；
     * - rolled_back / completed：幂等返回或拒绝重复回滚。
     */
    public function rollback(int $tenantId, int|string $operationId, DocActor $actor): DocMoveOperation
    {
        $this->assertTenant($tenantId);
        app(DocAccessService::class)->assertCanManageCatalogs($actor);

        $operation = $this->find($tenantId, $operationId);

        if ($operation->status === DocMoveOperation::STATUS_ROLLED_BACK) {
            return $operation;
        }

        if ($operation->completed_at !== null && $operation->isMoved()) {
            throw ArchiveException::conflict('move_operation_completed', '该批次已完成，不能按条目回滚');
        }

        return $this->rollbackMoved($tenantId, $operation);
    }

    private function rollbackMoved(int $tenantId, DocMoveOperation $operation): DocMoveOperation
    {
        $this->markAttempt($operation);

        try {
            $provider = $this->provider($tenantId, (string) $operation->provider);
            $this->providerCall(fn () => $provider->moveFile(
                $this->sourceRef($operation),
                (string) $operation->from_path,
            ));

            $this->markRolledBack($operation);
        } catch (Throwable $exception) {
            $this->markRollbackFailed($operation, $exception);

            throw $exception;
        }

        return $operation->refresh();
    }

    private function find(int $tenantId, int|string $operationId): DocMoveOperation
    {
        $operation = DocMoveOperation::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($operationId)
            ->first();

        if (! $operation instanceof DocMoveOperation) {
            throw ArchiveException::notFound('移动操作不存在');
        }

        return $operation;
    }

    private function sourceRef(DocMoveOperation $operation): string
    {
        $ref = (string) ($operation->provider_file_ref ?? '');

        if ($ref !== '') {
            return $ref;
        }

        return $operation->isMoved() ? (string) $operation->to_path : (string) $operation->from_path;
    }

    /**
     * 脱敏摘要：只保留受控 reason / 异常类名，绝不写原始响应或 SQL。
     */
    private function summarize(Throwable $exception, string $context): string
    {
        $reason = match (true) {
            $exception instanceof ArchiveException => $exception->reason,
            $exception instanceof DocProviderException => $exception->reason,
            default => class_basename($exception),
        };

        return mb_substr($context . ':' . $reason, 0, 500);
    }

    private function provider(int $tenantId, string $name): DocProviderContract
    {
        try {
            return $this->providers->resolve($tenantId, $name);
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }
    }

    private function providerCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }
    }

    private function assertTenant(int $tenantId): void
    {
        TenantContext::assertTenantId($tenantId);
    }
}
