<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services\Tools;

use MultiTenantSaas\Access\DataScopeContext;
use MultiTenantSaas\Contracts\DataScopeCapableContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Archive\Services\DocItemService;

/**
 * L1：文档摘要片段（服务端截断，不把全文交给模型）。
 */
final class ArchiveDocSummaryHandler implements DataScopeCapableContract, ToolHandlerContract
{
    private ?DataScopeContext $dataScope = null;

    /**
     * 档案查询层（DocItemService::baseQuery/find）在构建期消费
     * DataScopeContext::ambient()；这里保留显式接收点，确保 ToolRegistry
     * 的 restricted 决策不会被当成“已限行”标记跳过。
     */
    public function acceptDataScope(DataScopeContext $scope): void
    {
        $this->dataScope = $scope;
    }

    use ResolvesDocActor;

    public function __construct(private readonly DocItemService $items) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $actor = $this->docActor(['view' => true, 'download' => true]);
        $docItemId = $arguments['doc_item_id'] ?? $arguments['document_id'] ?? null;

        if ($docItemId === null || $docItemId === '') {
            return [
                'error' => true,
                'message' => '缺少 doc_item_id',
            ];
        }

        return $this->items->summary($tenantId, $docItemId, $actor);
    }
}
