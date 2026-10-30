<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\Concerns\ResolvesDocActor;
use MultiTenantSaas\Modules\Archive\Services\DocItemService;

/**
 * 档案导出：P0 同步返回元数据清单（不打包正文）。
 */
class ExportController extends BaseController
{
    use ResolvesDocActor;

    public function __construct(private readonly DocItemService $items) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|min:1',
            'catalog_id' => 'nullable|integer|min:1',
            'doc_type' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,archived',
            'keyword' => 'nullable|string|max:100',
        ]);

        $manifest = $this->items->exportManifest(
            $this->tenantId(),
            $validated,
            $this->docActor(['export' => true]),
        );

        return $this->successResponse($manifest, '导出清单已生成');
    }
}
