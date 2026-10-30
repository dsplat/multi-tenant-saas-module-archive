<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\Concerns\ResolvesDocActor;
use MultiTenantSaas\Modules\Archive\Http\Resources\DocCatalogResource;
use MultiTenantSaas\Modules\Archive\Services\DocCatalogService;

/**
 * 档案目录：列表 / 树 / 新建 / 重命名移动 / 归档。
 */
class CatalogController extends BaseController
{
    use ResolvesDocActor;

    public function __construct(private readonly DocCatalogService $catalogs) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|min:1',
            'parent_id' => 'nullable|integer|min:1',
            'catalog_type' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,archived',
            'keyword' => 'nullable|string|max:100',
        ]);

        $items = $this->catalogs->list(
            $this->tenantId(),
            $validated,
            $this->docActor(['view' => true]),
        );

        return $this->successResponse([
            'data' => $items->map(fn ($catalog): array => (new DocCatalogResource($catalog))->resolve())->all(),
            'total' => $items->count(),
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|min:1',
            'catalog_type' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,archived',
        ]);

        return $this->successResponse([
            'data' => $this->catalogs->tree(
                $this->tenantId(),
                $validated,
                $this->docActor(['view' => true]),
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|min:1',
            'scope_type' => 'nullable|string|in:project,tenant',
            'parent_id' => 'nullable|integer|min:1',
            'name' => 'required_without:path|nullable|string|max:100',
            'path' => 'nullable|string|max:500',
            'catalog_type' => 'nullable|string|max:50',
            'sort' => 'nullable|integer',
            'provider' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',
        ]);

        $catalog = $this->catalogs->create(
            $this->tenantId(),
            $validated,
            $this->docActor(['view' => true, 'manage_catalogs' => true]),
        );

        return $this->createdResponse((new DocCatalogResource($catalog))->resolve(), '目录已创建');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|integer|min:1',
            'name' => 'nullable|string|max:100',
            'path' => 'nullable|string|max:500',
            'catalog_type' => 'nullable|string|max:50',
            'sort' => 'nullable|integer',
            'provider' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,archived',
            'metadata' => 'nullable|array',
            // 仅校验/估算，不移动 provider 文件、不改目录路径。
            'dry_run' => 'sometimes|boolean',
        ]);

        $catalog = $this->catalogs->update(
            $this->tenantId(),
            $id,
            $validated,
            $this->docActor(['view' => true, 'manage_catalogs' => true]),
        );

        return $this->successResponse(
            (new DocCatalogResource($catalog))->resolve(),
            filter_var($validated['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '预检通过，未执行移动' : '目录已更新',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $catalog = $this->catalogs->delete(
            $this->tenantId(),
            $id,
            $this->docActor(['view' => true, 'manage_catalogs' => true]),
        );

        return $this->successResponse((new DocCatalogResource($catalog))->resolve(), '目录已归档');
    }
}
