<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AuditLoggerContract;
use MultiTenantSaas\Modules\Archive\Dto\DocActor;
use MultiTenantSaas\Modules\Archive\Dto\TemplateApplyResult;
use MultiTenantSaas\Modules\Archive\Events\ArchiveTemplateApplied;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveException;
use MultiTenantSaas\Modules\Archive\Exceptions\InvalidDocPathException;

/**
 * 项目档案目录模板。
 *
 * - 占位符：{{project_code}} / {{project_name}} / {{year}} / {{tenant}}
 * - 同路径目录复用（归档的重新激活），不删除历史目录
 * - 单个节点失败只记录并按节点重试，不阻塞其它节点
 */
final class DocTemplateService
{
    public function __construct(
        private readonly DocCatalogService $catalogs,
        private readonly DocPathNormalizer $paths,
        private readonly AuditLoggerContract $audit,
    ) {}

    /**
     * @param  array{project_code?: string, project_name?: string, year?: int|string, tenant?: string}  $context
     */
    public function apply(
        int $tenantId,
        int $projectId,
        string $templateKey,
        string $idempotencyKey,
        DocActor $actor,
        array $context = [],
    ): TemplateApplyResult {
        TenantContext::assertTenantId($tenantId);
        app(DocAccessService::class)->assertCanManageCatalogs($actor);

        if ($actor->projectIds !== [] && ! in_array($projectId, $actor->projectIds, true)) {
            throw ArchiveException::forbidden('project_scope_denied', '项目不在当前执行范围内');
        }

        $templates = (array) config('archive.templates', []);
        $template = $templates[$templateKey] ?? null;

        if (! is_array($template)) {
            throw ArchiveException::unprocessable('template_not_found', '目录模板不存在');
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw ArchiveException::unprocessable('idempotency_key_required', '模板应用必须提供幂等键');
        }

        if (mb_strlen($idempotencyKey) > 100) {
            throw ArchiveException::unprocessable('idempotency_key_invalid', '幂等键过长（最多 100 字符）');
        }

        $provider = (string) config('archive.default_provider', 'gitlab');
        $metadata = [
            'template_key' => $templateKey,
            'template_idempotency' => $idempotencyKey,
            'template_project_id' => $projectId,
            'template_applied_at' => now()->toIso8601String(),
        ];

        $created = 0;
        $reused = 0;
        $catalogs = [];
        $failures = [];

        foreach ((array) ($template['catalogs'] ?? []) as $definition) {
            $rawPath = (string) ($definition['path'] ?? '');
            $catalogType = (string) ($definition['catalog_type'] ?? 'other');

            try {
                $rendered = $this->render($rawPath, $tenantId, $projectId, $context);
                $rendered = $this->paths->normalize($rendered);

                $result = $this->catalogs->ensurePath(
                    $tenantId,
                    $projectId,
                    $rendered,
                    $catalogType,
                    $provider,
                    $metadata,
                    $templateKey,
                    $actor,
                );

                $catalog = $result['catalog'];
                $catalogs[] = [
                    'catalog_id' => $catalog->catalog_id,
                    'path' => (string) $catalog->path,
                    'reused' => ! $result['created'],
                ];

                if ($result['created']) {
                    $created++;
                } else {
                    $reused++;
                }
            } catch (InvalidDocPathException $exception) {
                $failures[] = [
                    'path' => $rawPath,
                    'reason' => $exception->reason,
                    'message' => $exception->getMessage(),
                ];
            } catch (ArchiveException $exception) {
                $failures[] = [
                    'path' => $rawPath,
                    'reason' => $exception->reason,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $result = new TemplateApplyResult(
            projectId: $projectId,
            templateKey: $templateKey,
            idempotencyKey: $idempotencyKey,
            created: $created,
            reused: $reused,
            catalogs: $catalogs,
            failures: $failures,
        );

        event(new ArchiveTemplateApplied(
            $tenantId,
            $projectId,
            $templateKey,
            $idempotencyKey,
            $created,
            $reused,
            $failures,
        ));

        $this->audit->log('template_apply', 'doc_catalog', null, null, [
            'project_id' => $projectId,
            'template_key' => $templateKey,
            'idempotency_key' => $idempotencyKey,
            'created' => $created,
            'reused' => $reused,
            'failures' => count($failures),
        ]);

        return $result;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function availableTemplates(): array
    {
        $templates = (array) config('archive.templates', []);
        $result = [];

        foreach ($templates as $key => $template) {
            if (! is_array($template)) {
                continue;
            }

            $result[] = [
                'key' => (string) $key,
                'label' => (string) ($template['label'] ?? $key),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function render(string $templatePath, int $tenantId, int $projectId, array $context): string
    {
        $tenantName = $context['tenant'] ?? null;

        if (! is_string($tenantName) || trim($tenantName) === '') {
            $tenantName = (string) (TenantContext::getTenant()?->name ?? $tenantId);
        }

        $replacements = [
            '{{project_code}}' => $this->sanitizePlaceholder(
                (string) ($context['project_code'] ?? ('P' . $projectId)),
                'P' . $projectId,
            ),
            '{{project_name}}' => $this->sanitizePlaceholder(
                (string) ($context['project_name'] ?? ('项目' . $projectId)),
                '项目' . $projectId,
            ),
            '{{year}}' => $this->sanitizePlaceholder((string) ($context['year'] ?? date('Y')), (string) date('Y')),
            '{{tenant}}' => $this->sanitizePlaceholder((string) $tenantName, (string) $tenantId),
        ];

        return strtr($templatePath, $replacements);
    }

    private function sanitizePlaceholder(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = str_replace(['/', '\\'], '-', $value);
        $value = str_replace('..', '_', $value);
        $value = trim($value, ". \t\n\r\0\x0B");

        return $value === '' ? $fallback : $value;
    }
}
