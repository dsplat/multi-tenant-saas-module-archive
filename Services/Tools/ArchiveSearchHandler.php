<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Archive\Services\DocItemService;

/**
 * L1：搜索档案文档（仅元数据，不返回正文）。
 */
final class ArchiveSearchHandler implements ToolHandlerContract
{
    use ResolvesDocActor;

    public function __construct(private readonly DocItemService $items) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $actor = $this->docActor(['view' => true]);

        $page = $this->items->paginate($tenantId, [
            'keyword' => isset($arguments['keyword']) ? (string) $arguments['keyword'] : null,
            'project_id' => $arguments['project_id'] ?? null,
            'catalog_id' => $arguments['catalog_id'] ?? null,
            'doc_type' => $arguments['doc_type'] ?? null,
            'status' => $arguments['status'] ?? null,
            'per_page' => $arguments['per_page'] ?? 20,
        ], $actor);

        return [
            'data' => array_map(fn ($item): array => [
                'doc_item_id' => $item->doc_item_id,
                'title' => $item->title,
                'doc_type' => $item->doc_type,
                'mime_type' => $item->mime_type,
                'size' => (int) $item->size,
                'project_id' => $item->project_id,
                'catalog_id' => $item->catalog_id,
                'current_revision' => (int) $item->current_revision,
                'updated_at' => $item->updated_at?->toIso8601String(),
            ], $page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ];
    }
}
