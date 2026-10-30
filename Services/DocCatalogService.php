<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Access\DataScopeContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AuditLoggerContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderRegistryContract;
use MultiTenantSaas\Modules\Archive\Dto\DocActor;
use MultiTenantSaas\Modules\Archive\Events\ArchiveCatalogCreated;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveException;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveProviderFailure;
use MultiTenantSaas\Modules\Archive\Exceptions\DocProviderException;
use MultiTenantSaas\Modules\Archive\Models\DocCatalog;
use MultiTenantSaas\Modules\Archive\Models\DocItem;
use MultiTenantSaas\Modules\Archive\Models\DocMoveOperation;
use Throwable;

/**
 * 文档目录服务。
 *
 * 目录只保存层级与 provider 路径；正文仍由 provider 持有。
 * 目录重命名/移动会同步 provider 中已存在的文件；provider 不支持移动时
 * 显式报错，绝不静默留下孤儿文件。
 */
final class DocCatalogService
{
    public function __construct(
        private readonly DocPathNormalizer $paths,
        private readonly DocProviderRegistryContract $providers,
        private readonly AuditLoggerContract $audit,
        private readonly DocMoveOperationService $moveOperations,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, DocCatalog>
     */
    public function list(int $tenantId, array $filters, DocActor $actor): Collection
    {
        $this->assertTenant($tenantId);

        $query = DocCatalog::query()->where('tenant_id', $tenantId);

        if ($scope = DataScopeContext::ambient()) {
            $scope->applyTo($query);
        }

        if (isset($filters['project_id']) && $filters['project_id'] !== '') {
            $query->where('project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['parent_id'])) {
            $query->where('parent_id', (int) $filters['parent_id']);
        }

        if (! empty($filters['catalog_type'])) {
            $query->where('catalog_type', (string) $filters['catalog_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        } else {
            $query->where('status', DocCatalog::STATUS_ACTIVE);
        }

        if (! empty($filters['keyword'])) {
            $keyword = (string) $filters['keyword'];
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        if ($actor->projectIds !== []) {
            $query->where(function ($builder) use ($actor): void {
                $builder->whereIn('project_id', $actor->projectIds)->orWhereNull('project_id');
            });
        }

        return $query
            ->withCount(['children', 'items' => fn ($builder) => $builder->where('status', '!=', DocItem::STATUS_DELETED)])
            ->orderBy('path')
            ->orderBy('sort')
            ->get();
    }

    /**
     * 目录树（嵌套 children），供管理端展示。
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function tree(int $tenantId, array $filters, DocActor $actor): array
    {
        $catalogs = $this->list($tenantId, $filters, $actor);

        $grouped = $catalogs->groupBy(fn (DocCatalog $catalog) => (int) ($catalog->parent_id ?? 0));
        $build = function (int $parentId) use (&$build, $grouped): array {
            $nodes = [];

            foreach ($grouped->get($parentId, []) as $catalog) {
                $node = $catalog->toArray();
                $node['children'] = $build((int) $catalog->catalog_id);
                $nodes[] = $node;
            }

            return $nodes;
        };

        return $build(0);
    }

    public function find(int $tenantId, int|string $catalogId, DocActor $actor): DocCatalog
    {
        $this->assertTenant($tenantId);

        $catalog = DocCatalog::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($catalogId)
            ->first();

        if (! $catalog instanceof DocCatalog) {
            throw ArchiveException::notFound('目录不存在');
        }

        if ($actor->projectIds !== [] && $catalog->project_id !== null
            && ! in_array((int) $catalog->project_id, $actor->projectIds, true)) {
            throw ArchiveException::forbidden('project_scope_denied', '目录不在当前项目范围内');
        }

        return $catalog;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(int $tenantId, array $input, DocActor $actor): DocCatalog
    {
        $this->assertTenant($tenantId);
        app(DocAccessService::class)->assertCanManageCatalogs($actor);

        $projectId = $this->resolveProjectId($input, $actor);
        $scopeType = $this->resolveScopeType($input, $projectId);

        $parent = null;
        if (! empty($input['parent_id'])) {
            $parent = $this->find($tenantId, (int) $input['parent_id'], $actor);

            if ($parent->isArchived()) {
                throw ArchiveException::conflict('parent_archived', '父目录已归档');
            }

            if ((int) ($parent->project_id ?? 0) !== (int) ($projectId ?? 0)
                || (string) $parent->scope_type !== $scopeType) {
                throw ArchiveException::unprocessable('parent_scope_mismatch', '父目录与目标项目/范围不一致');
            }
        }

        $name = isset($input['name']) ? $this->paths->validateName((string) $input['name']) : null;

        if (isset($input['path']) && trim((string) $input['path']) !== '') {
            $path = $this->paths->normalize((string) $input['path']);
            $name ??= $this->paths->basename($path);
        } else {
            if ($name === null) {
                throw ArchiveException::unprocessable('name_required', '目录名称不能为空');
            }

            $path = $parent instanceof DocCatalog
                ? $this->paths->join((string) $parent->path, $name)
                : $this->paths->normalize($name);
        }

        // 创建后统一再规范化一次，避免调用方绕过 path/name 组合规则。
        $path = $this->paths->normalize($path);

        $this->assertPathAvailable($tenantId, $projectId, $path);

        $providerName = (string) ($input['provider'] ?? $parent?->provider ?? config('archive.default_provider', 'gitlab'));
        $provider = $this->provider($tenantId, $providerName);

        try {
            $provider->createDirectory($path);
        } catch (DocProviderException $exception) {
            throw ArchiveProviderFailure::from($exception);
        }

        try {
            $catalog = DB::transaction(fn (): DocCatalog => DocCatalog::query()->create([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'scope_type' => $scopeType,
                'parent_id' => $parent?->catalog_id,
                'name' => $name ?? $this->paths->basename($path),
                'path' => $path,
                'catalog_type' => (string) ($input['catalog_type'] ?? 'other'),
                'sort' => (int) ($input['sort'] ?? 0),
                'provider' => $providerName,
                'provider_ref' => $input['provider_ref'] ?? null,
                'status' => DocCatalog::STATUS_ACTIVE,
                'template_key' => $input['template_key'] ?? null,
                'metadata' => (array) ($input['metadata'] ?? []),
            ]));
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ArchiveException::conflict('catalog_exists', '同路径目录已存在');
            }

            throw $exception;
        }

        $this->audit->log('create', 'doc_catalog', (int) $catalog->catalog_id, null, [
            'path' => $catalog->path,
            'project_id' => $catalog->project_id,
            'template_key' => $catalog->template_key,
        ]);

        event(new ArchiveCatalogCreated(
            $tenantId,
            $catalog->catalog_id,
            $catalog->project_id !== null ? (int) $catalog->project_id : null,
            (string) $catalog->path,
            $catalog->template_key,
        ));

        return $catalog;
    }

    /**
     * 重命名 / 移动目录；路径变化会同步子树与 provider 文件。
     *
     * @param  array<string, mixed>  $input
     */
    public function update(int $tenantId, int|string $catalogId, array $input, DocActor $actor): DocCatalog
    {
        $this->assertTenant($tenantId);
        app(DocAccessService::class)->assertCanManageCatalogs($actor);

        $catalog = $this->find($tenantId, $catalogId, $actor);

        $newParent = $catalog->parent;
        if (array_key_exists('parent_id', $input)) {
            $newParent = null;

            if ($input['parent_id'] !== null && $input['parent_id'] !== '') {
                $newParent = $this->find($tenantId, (int) $input['parent_id'], $actor);

                if ((int) ($newParent->project_id ?? 0) !== (int) ($catalog->project_id ?? 0)) {
                    throw ArchiveException::unprocessable('parent_scope_mismatch', '不允许跨项目移动目录');
                }

                if ($newParent->isArchived()) {
                    throw ArchiveException::conflict('parent_archived', '父目录已归档');
                }

                if ((int) $newParent->catalog_id === (int) $catalog->catalog_id
                    || str_starts_with((string) $newParent->path, (string) $catalog->path . '/')) {
                    throw ArchiveException::conflict('catalog_cycle', '不能把目录移动到自身或子孙目录下');
                }
            }
        }

        $name = array_key_exists('name', $input)
            ? $this->paths->validateName((string) $input['name'])
            : (string) $catalog->name;

        if (isset($input['path']) && trim((string) $input['path']) !== '') {
            $newPath = $this->paths->normalize((string) $input['path']);
            $name = $this->paths->basename($newPath);
        } else {
            $newPath = $newParent instanceof DocCatalog
                ? $this->paths->join((string) $newParent->path, $name)
                : $this->paths->normalize($name);
        }

        $newPath = $this->paths->normalize($newPath);
        $oldPath = (string) $catalog->path;

        $subtree = DocCatalog::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($builder) use ($oldPath): void {
                $builder->where('path', $oldPath)
                    ->orWhereRaw("path LIKE ? ESCAPE '!'", [$this->prefixPattern($oldPath)]);
            })
            ->lockForUpdate()
            ->get();

        $subtreeIds = $subtree->map(fn (DocCatalog $node) => (int) $node->catalog_id)->all();

        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $moveResult = ['batch_id' => null, 'planned' => 0];

        if ($newPath !== $oldPath) {
            $this->assertSubtreePathAvailable($tenantId, (int) ($catalog->project_id ?? 0), $subtreeIds, $oldPath, $newPath);

            if ($dryRun) {
                $this->moveProviderFiles($tenantId, $catalog, $oldPath, $newPath, true);

                return $catalog->refresh();
            }

            $moveResult = $this->moveProviderFiles($tenantId, $catalog, $oldPath, $newPath, false);
        }

        if ($dryRun) {
            return $catalog->refresh();
        }

        try {
            $catalog = DB::transaction(function () use (
                $catalog,
                $subtree,
                $oldPath,
                $newPath,
                $newParent,
                $name,
                $input,
                $moveResult,
                $tenantId
            ): DocCatalog {
                foreach ($subtree as $node) {
                    $nodePath = $this->paths->relocate($oldPath, $newPath, (string) $node->path);

                    if ((int) $node->catalog_id === (int) $catalog->catalog_id) {
                        $node->name = $name;
                        $node->parent_id = $newParent?->catalog_id;
                        $node->catalog_type = (string) ($input['catalog_type'] ?? $node->catalog_type);
                        $node->sort = (int) ($input['sort'] ?? $node->sort);
                        $node->provider = (string) ($input['provider'] ?? $node->provider);
                        $node->provider_ref = $input['provider_ref'] ?? $node->provider_ref;
                        $node->metadata = array_merge((array) ($node->metadata ?? []), (array) ($input['metadata'] ?? []));
                    }

                    $node->path = $nodePath;
                    $node->save();
                }

                if (array_key_exists('status', $input)) {
                    $status = (string) $input['status'];
                    if (! in_array($status, [DocCatalog::STATUS_ACTIVE, DocCatalog::STATUS_ARCHIVED], true)) {
                        throw ArchiveException::unprocessable('invalid_status', '目录状态非法');
                    }
                    $catalog->status = $status;
                    $catalog->save();
                }

                if ($newPath !== $oldPath) {
                    $this->updateItemPaths($catalog->tenant_id !== null ? (int) $catalog->tenant_id : 0, $oldPath, $newPath);
                }

                // 只有目录/文档路径事务真正提交，才把批次标记为终结；
                // 半移动（含本事务失败）保持 completed_at NULL 供恢复入口发现。
                if (is_string($moveResult['batch_id'] ?? null)) {
                    $this->moveOperations->completeBatch($tenantId, $moveResult['batch_id']);
                }

                return $catalog->refresh();
            });
        } catch (QueryException $exception) {
            $this->rollbackMovedBatch($tenantId, $moveResult);

            if ($this->isUniqueViolation($exception)) {
                throw ArchiveException::conflict('catalog_exists', '目标路径已存在');
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->rollbackMovedBatch($tenantId, $moveResult);

            throw $exception;
        }

        $this->audit->log('update', 'doc_catalog', (int) $catalog->catalog_id, [
            'path' => $oldPath,
        ], [
            'path' => $catalog->path,
            'name' => $catalog->name,
            'parent_id' => $catalog->parent_id,
        ]);

        return $catalog;
    }

    /**
     * 列出本租户未终结的目录移动批次与条目（恢复入口）。
     *
     * 未终结包含 pending / failed / moved 且批次未 completed 的记录；调用方
     * 可据此按 operation_id 调用 retryMoveOperation / rollbackMoveOperation。
     *
     * @return list<array<string, mixed>>
     */
    public function incompleteMoveBatches(int $tenantId, DocActor $actor): array
    {
        $this->assertTenant($tenantId);

        return $this->moveOperations->incompleteBatches($tenantId, $actor);
    }

    /**
     * 按条目重试 provider 移动（幂等：已 moved / rolled_back 不重复调用 provider）。
     */
    public function retryMoveOperation(int $tenantId, int|string $operationId, DocActor $actor): DocMoveOperation
    {
        $this->assertTenant($tenantId);

        return $this->moveOperations->retry($tenantId, $operationId, $actor);
    }

    /**
     * 按条目回滚 provider 移动（幂等：已 rolled_back 不重复调用 provider）。
     */
    public function rollbackMoveOperation(int $tenantId, int|string $operationId, DocActor $actor): DocMoveOperation
    {
        $this->assertTenant($tenantId);

        return $this->moveOperations->rollback($tenantId, $operationId, $actor);
    }

    /**
     * 软归档目录；非空（仍有子目录或未删除文档）时拒绝。
     */
    public function delete(int $tenantId, int|string $catalogId, DocActor $actor): DocCatalog
    {
        $this->assertTenant($tenantId);
        app(DocAccessService::class)->assertCanManageCatalogs($actor);

        $catalog = $this->find($tenantId, $catalogId, $actor);

        $hasChildren = DocCatalog::query()
            ->where('tenant_id', $tenantId)
            ->where('parent_id', $catalog->catalog_id)
            ->exists();

        $hasItems = DocItem::query()
            ->where('tenant_id', $tenantId)
            ->where('catalog_id', $catalog->catalog_id)
            ->where('status', '!=', DocItem::STATUS_DELETED)
            ->exists();

        if ($hasChildren || $hasItems) {
            throw ArchiveException::conflict('catalog_not_empty', '目录非空，不能删除');
        }

        if (! $catalog->isArchived()) {
            $catalog->status = DocCatalog::STATUS_ARCHIVED;
            $catalog->metadata = array_merge((array) ($catalog->metadata ?? []), [
                'archived_at' => now()->toIso8601String(),
            ]);
            $catalog->save();
        }

        // 不物理删除 provider 目录，保留正文可追溯性。
        $this->audit->log('delete', 'doc_catalog', (int) $catalog->catalog_id, [
            'path' => $catalog->path,
        ], [
            'status' => $catalog->status,
        ]);

        return $catalog;
    }

    /**
     * 幂等确保目录存在（模板/自动归档内部使用）。
     *
     * @param  array<string, mixed>  $metadata
     * @return array{catalog: DocCatalog, created: bool}
     */
    public function ensurePath(
        int $tenantId,
        ?int $projectId,
        string $path,
        string $catalogType,
        string $provider,
        array $metadata,
        ?string $templateKey,
        DocActor $actor,
    ): array {
        $path = $this->paths->normalize($path);

        $existing = DocCatalog::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('path', $path)
            ->first();

        if ($existing instanceof DocCatalog) {
            $metadata = array_merge((array) ($existing->metadata ?? []), $metadata);
            $existing->metadata = $metadata;

            if ($existing->isArchived()) {
                $existing->status = DocCatalog::STATUS_ACTIVE;
            }

            $existing->save();

            return ['catalog' => $existing, 'created' => false];
        }

        // 先补齐祖先目录，保证模板树完整。
        $segments = explode('/', $path);
        $ancestorPath = null;
        $parent = null;

        for ($i = 0; $i < count($segments) - 1; $i++) {
            $ancestorPath = $ancestorPath === null ? $segments[$i] : $ancestorPath . '/' . $segments[$i];

            $parent = $this->ensurePath(
                $tenantId,
                $projectId,
                $ancestorPath,
                $catalogType,
                $provider,
                $metadata,
                $templateKey,
                $actor,
            )['catalog'];
        }

        $catalog = $this->create($tenantId, [
            'project_id' => $projectId,
            'parent_id' => $parent?->catalog_id,
            'path' => $path,
            'name' => (string) end($segments),
            'catalog_type' => $catalogType,
            'provider' => $provider,
            'template_key' => $templateKey,
            'metadata' => $metadata,
        ], $actor);

        return ['catalog' => $catalog, 'created' => true];
    }

    private function assertPathAvailable(int $tenantId, ?int $projectId, string $path, ?int $exceptId = null): void
    {
        $query = DocCatalog::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('path', $path);

        if ($exceptId !== null) {
            $query->where('catalog_id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ArchiveException::conflict('catalog_exists', '同路径目录已存在');
        }
    }

    /**
     * @param  list<int>  $subtreeIds
     */
    private function assertSubtreePathAvailable(
        int $tenantId,
        int $projectId,
        array $subtreeIds,
        string $oldPath,
        string $newPath,
    ): void {
        $conflicts = DocCatalog::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId === 0 ? null : $projectId)
            ->whereNotIn('catalog_id', $subtreeIds)
            ->where(function ($builder) use ($newPath): void {
                $builder->where('path', $newPath)
                    ->orWhereRaw("path LIKE ? ESCAPE '!'", [$this->prefixPattern($newPath)]);
            })
            ->exists();

        if ($conflicts) {
            throw ArchiveException::conflict('catalog_exists', '目标路径与既有目录冲突');
        }
    }

    /**
     * 移动子树内已存在的 provider 文件；不支持移动/超过规模上限时显式失败。
     *
     * 先落 pending 账本再逐项调用 provider：provider 是外部副作用，不能与本地
     * DB 事务原子提交；先写后调保证崩溃后仍能列出半移动范围。
     *
     * @return array{batch_id: string|null, planned: int}
     */
    private function moveProviderFiles(
        int $tenantId,
        DocCatalog $catalog,
        string $oldPath,
        string $newPath,
        bool $dryRun,
    ): array {
        $items = DocItem::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($builder) use ($oldPath): void {
                $builder->where('provider_path', $oldPath)
                    ->orWhereRaw("provider_path LIKE ? ESCAPE '!'", [$this->prefixPattern($oldPath)]);
            })
            ->get();

        if ($items->isEmpty()) {
            return ['batch_id' => null, 'planned' => 0];
        }

        $providerName = (string) $catalog->provider;
        $provider = $this->provider($tenantId, $providerName);

        $capabilities = $this->providerCall(fn () => $provider->capabilities());

        if (! ($capabilities['supports_move'] ?? false)) {
            throw ArchiveException::conflict(
                'provider_move_unsupported',
                '当前文档 provider 不支持移动，请先手工迁移目录内容后再重命名',
            );
        }

        $plan = [];

        foreach ($items as $item) {
            if ($item->provider_file_ref === null || $item->provider_path === null) {
                continue;
            }

            $targetPath = $this->paths->relocate($oldPath, $newPath, (string) $item->provider_path);

            if ($targetPath === (string) $item->provider_path) {
                continue;
            }

            $plan[] = [
                'provider_file_ref' => (string) $item->provider_file_ref,
                'from_path' => (string) $item->provider_path,
                'to_path' => $targetPath,
            ];
        }

        if ($plan === []) {
            return ['batch_id' => null, 'planned' => 0];
        }

        $limit = $this->moveOperations->maxFiles();

        if (count($plan) > $limit) {
            throw ArchiveException::unprocessable(
                'move_limit_exceeded',
                sprintf('单次目录移动最多 %d 个文件，本次需移动 %d 个，请拆分目录后重试', $limit, count($plan)),
            );
        }

        if ($dryRun) {
            return ['batch_id' => null, 'planned' => count($plan)];
        }

        $batchId = $this->moveOperations->beginBatch($tenantId, (int) $catalog->catalog_id, $providerName, $plan);

        foreach ($this->moveOperations->operationsForBatch($tenantId, $batchId) as $operation) {
            $this->moveOperations->markAttempt($operation);

            try {
                $file = $this->providerCall(fn () => $provider->moveFile(
                    (string) $operation->provider_file_ref,
                    (string) $operation->to_path,
                ));

                $this->moveOperations->markMoved($operation, $file->ref);
            } catch (Throwable $exception) {
                $this->moveOperations->markFailed($operation, $exception);
                $this->moveOperations->rollbackBatch($tenantId, $batchId);

                throw $exception;
            }
        }

        return ['batch_id' => $batchId, 'planned' => count($plan)];
    }

    /**
     * DB 事务失败后的补偿：以账本为准（内存数组不再作为唯一事实源）。
     *
     * @param  array{batch_id: string|null, planned: int}  $moveResult
     */
    private function rollbackMovedBatch(int $tenantId, array $moveResult): void
    {
        $batchId = $moveResult['batch_id'] ?? null;

        if (is_string($batchId)) {
            $this->moveOperations->rollbackBatch($tenantId, $batchId);
        }
    }

    private function updateItemPaths(int $tenantId, string $oldPath, string $newPath): void
    {
        $items = DocItem::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($builder) use ($oldPath): void {
                $builder->where('provider_path', $oldPath)
                    ->orWhereRaw("provider_path LIKE ? ESCAPE '!'", [$this->prefixPattern($oldPath)]);
            })
            ->get();

        foreach ($items as $item) {
            $item->provider_path = $this->paths->relocate($oldPath, $newPath, (string) $item->provider_path);
            $item->save();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveProjectId(array $input, DocActor $actor): ?int
    {
        $projectId = isset($input['project_id']) && $input['project_id'] !== ''
            ? (int) $input['project_id']
            : null;

        if ($projectId !== null && $actor->projectIds !== []
            && ! in_array($projectId, $actor->projectIds, true)) {
            throw ArchiveException::forbidden('project_scope_denied', '目录不属于当前项目范围');
        }

        return $projectId;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveScopeType(array $input, ?int $projectId): string
    {
        $scopeType = (string) ($input['scope_type'] ?? ($projectId === null ? DocCatalog::SCOPE_TENANT : DocCatalog::SCOPE_PROJECT));

        if (! in_array($scopeType, [DocCatalog::SCOPE_TENANT, DocCatalog::SCOPE_PROJECT], true)) {
            throw ArchiveException::unprocessable('invalid_scope_type', '目录范围类型非法');
        }

        if ($scopeType === DocCatalog::SCOPE_PROJECT && $projectId === null) {
            throw ArchiveException::unprocessable('project_required', '项目目录必须指定 project_id');
        }

        if ($scopeType === DocCatalog::SCOPE_TENANT && $projectId !== null) {
            throw ArchiveException::unprocessable('project_not_allowed', '租户目录不允许指定 project_id');
        }

        return $scopeType;
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

    private function prefixPattern(string $path): string
    {
        // 用 '!' 作 LIKE 转义符：SQL 里 ESCAPE '\' 在 MySQL 是未闭合字符串（sqlite 却合法），
        // 会让「前缀子树查询」在生产 MySQL 直接语法错误（nightly 2026-10-26 实测）。
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $path) . '/%';
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = strtoupper($exception->getMessage());

        return str_contains($message, 'UNIQUE') || str_contains($message, 'DUPLICATE');
    }

    private function assertTenant(int $tenantId): void
    {
        TenantContext::assertTenantId($tenantId);
    }
}
