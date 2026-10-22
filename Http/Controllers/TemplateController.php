<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\Concerns\ResolvesDocActor;
use MultiTenantSaas\Modules\Archive\Services\DocTemplateService;

/**
 * 项目档案目录模板。
 */
class TemplateController extends BaseController
{
    use ResolvesDocActor;

    public function __construct(private readonly DocTemplateService $templates) {}

    public function index(): JsonResponse
    {
        $templates = $this->templates->availableTemplates();

        return $this->successResponse([
            'data' => $templates,
            'total' => count($templates),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer|min:1',
            'template_key' => 'required|string|max:50',
            'idempotency_key' => 'required|string|max:100',
            'project_code' => 'nullable|string|max:100',
            'project_name' => 'nullable|string|max:100',
            'year' => 'nullable|integer|min:2000|max:2100',
            'tenant' => 'nullable|string|max:100',
        ]);

        $result = $this->templates->apply(
            $this->tenantId(),
            (int) $validated['project_id'],
            (string) $validated['template_key'],
            (string) $validated['idempotency_key'],
            $this->docActor(['view' => true, 'manage_catalogs' => true]),
            array_filter([
                'project_code' => $validated['project_code'] ?? null,
                'project_name' => $validated['project_name'] ?? null,
                'year' => $validated['year'] ?? null,
                'tenant' => $validated['tenant'] ?? null,
            ], fn ($value) => $value !== null),
        );

        return $this->successResponse([
            'project_id' => $result->projectId,
            'template_key' => $result->templateKey,
            'idempotency_key' => $result->idempotencyKey,
            'created' => $result->created,
            'reused' => $result->reused,
            'catalogs' => $result->catalogs,
            'failures' => $result->failures,
        ], $result->hasFailures() ? '模板部分应用，失败节点可重试' : '模板已应用');
    }
}
