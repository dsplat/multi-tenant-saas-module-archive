<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Archive\Services\DocItemService;

/**
 * L1：上传前元数据建议（只返回建议，不落库）。
 */
final class ArchiveMetadataSuggestHandler implements ToolHandlerContract
{
    use ResolvesDocActor;

    public function __construct(private readonly DocItemService $items) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $actor = $this->docActor(['upload' => true]);

        return $this->items->metadataSuggest($tenantId, [
            'title' => $arguments['title'] ?? null,
            'filename' => $arguments['filename'] ?? null,
            'doc_type' => $arguments['doc_type'] ?? null,
        ], $actor);
    }
}
