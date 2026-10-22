<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AuditLoggerContract;
use MultiTenantSaas\Modules\Archive\Dto\DocActor;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveException;
use MultiTenantSaas\Modules\Archive\Models\DocItem;
use MultiTenantSaas\Modules\Archive\Models\DocRef;

/**
 * 文档引用账本：业务对象（项目/合同/审批单…）到文档的多态引用。
 *
 * 只记录引用关系，不复制业务数据；唯一键保证同一引用幂等。
 */
final class DocRefService
{
    public function __construct(
        private readonly DocAccessService $access,
        private readonly AuditLoggerContract $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(int $tenantId, array $input, DocActor $actor): DocRef
    {
        TenantContext::assertTenantId($tenantId);
        $this->access->assertCanManageRefs($actor);

        $docItemId = (int) ($input['doc_item_id'] ?? 0);
        $refType = trim((string) ($input['ref_type'] ?? ''));
        $refId = trim((string) ($input['ref_id'] ?? ''));
        $relation = (string) ($input['relation'] ?? DocRef::RELATION_ATTACHMENT);

        if ($docItemId <= 0) {
            throw ArchiveException::unprocessable('doc_item_required', '必须指定文档');
        }

        if ($refType === '' || mb_strlen($refType) > 50) {
            throw ArchiveException::unprocessable('ref_type_invalid', '引用类型不能为空且不超过 50 字符');
        }

        if ($refId === '' || mb_strlen($refId) > 100) {
            throw ArchiveException::unprocessable('ref_id_invalid', '引用 ID 不能为空且不超过 100 字符');
        }

        if (! in_array($relation, [
            DocRef::RELATION_ATTACHMENT,
            DocRef::RELATION_EVIDENCE,
            DocRef::RELATION_DELIVERABLE,
            DocRef::RELATION_OTHER,
        ], true)) {
            throw ArchiveException::unprocessable('relation_invalid', '引用关系类型非法');
        }

        $item = DocItem::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($docItemId)
            ->first();

        if (! $item instanceof DocItem || $item->isDeleted()) {
            throw ArchiveException::notFound('文档不存在');
        }

        if ($actor->projectIds !== [] && $item->project_id !== null
            && ! in_array((int) $item->project_id, $actor->projectIds, true)) {
            throw ArchiveException::forbidden('project_scope_denied', '文档不在当前项目范围内');
        }

        $this->access->assertCanView($item, $actor);

        $existing = DocRef::query()
            ->where('tenant_id', $tenantId)
            ->where('doc_item_id', $item->doc_item_id)
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();

        if ($existing instanceof DocRef) {
            return $existing;
        }

        try {
            $ref = DocRef::query()->create([
                'tenant_id' => $tenantId,
                'project_id' => $item->project_id,
                'doc_item_id' => $item->doc_item_id,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'relation' => $relation,
                'created_by' => $actor->subjectId,
            ]);
        } catch (QueryException $exception) {
            $message = strtoupper($exception->getMessage());

            if (str_contains($message, 'UNIQUE') || str_contains($message, 'DUPLICATE')) {
                $existing = DocRef::query()
                    ->where('tenant_id', $tenantId)
                    ->where('doc_item_id', $item->doc_item_id)
                    ->where('ref_type', $refType)
                    ->where('ref_id', $refId)
                    ->first();

                if ($existing instanceof DocRef) {
                    return $existing;
                }
            }

            throw $exception;
        }

        $this->audit->log('create', 'doc_ref', (int) $ref->doc_ref_id, null, [
            'doc_item_id' => $item->doc_item_id,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'relation' => $relation,
        ]);

        return $ref;
    }

    public function delete(int $tenantId, int|string $docRefId, DocActor $actor): void
    {
        TenantContext::assertTenantId($tenantId);
        $this->access->assertCanManageRefs($actor);

        $ref = DocRef::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($docRefId)
            ->first();

        if (! $ref instanceof DocRef) {
            throw ArchiveException::notFound('引用不存在');
        }

        $snapshot = $ref->toArray();
        $ref->delete();

        $this->audit->log('delete', 'doc_ref', (int) $docRefId, $snapshot, null);
    }

    /**
     * @return Collection<int, DocRef>
     */
    public function listForDocument(int $tenantId, int|string $docItemId, DocActor $actor): Collection
    {
        TenantContext::assertTenantId($tenantId);
        $this->access->assertCanView(
            DocItem::query()->where('tenant_id', $tenantId)->whereKey($docItemId)->first()
                ?? throw ArchiveException::notFound('文档不存在'),
            $actor,
        );

        return DocRef::query()
            ->where('tenant_id', $tenantId)
            ->where('doc_item_id', $docItemId)
            ->orderBy('created_at')
            ->get();
    }
}
