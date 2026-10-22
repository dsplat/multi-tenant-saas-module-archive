<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\Concerns\ResolvesDocActor;
use MultiTenantSaas\Modules\Archive\Http\Resources\DocRefResource;
use MultiTenantSaas\Modules\Archive\Services\DocRefService;

/**
 * 文档引用账本。
 */
class DocRefController extends BaseController
{
    use ResolvesDocActor;

    public function __construct(private readonly DocRefService $refs) {}

    public function index(string $docItemId): JsonResponse
    {
        $refs = $this->refs->listForDocument(
            $this->tenantId(),
            $docItemId,
            $this->docActor(['view' => true, 'manage_refs' => true]),
        );

        return $this->successResponse([
            'data' => $refs->map(fn ($ref): array => (new DocRefResource($ref))->resolve())->all(),
            'total' => $refs->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doc_item_id' => 'required|integer|min:1',
            'ref_type' => 'required|string|max:50',
            'ref_id' => 'required|string|max:100',
            'relation' => 'nullable|string|in:attachment,evidence,deliverable,other',
        ]);

        $ref = $this->refs->create(
            $this->tenantId(),
            $validated,
            $this->docActor(['view' => true, 'manage_refs' => true]),
        );

        return $this->createdResponse((new DocRefResource($ref))->resolve(), '引用已记录');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->refs->delete(
            $this->tenantId(),
            $id,
            $this->docActor(['view' => true, 'manage_refs' => true]),
        );

        return $this->deletedResponse('引用已删除');
    }
}
