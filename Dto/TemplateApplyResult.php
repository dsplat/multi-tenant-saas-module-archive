<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Dto;

/**
 * 目录模板应用结果。
 */
final readonly class TemplateApplyResult
{
    /**
     * @param  list<array{catalog_id: int|string, path: string, reused: bool}>  $catalogs
     * @param  list<array{path: string, reason: string, message: string}>  $failures
     */
    public function __construct(
        public int $projectId,
        public string $templateKey,
        public string $idempotencyKey,
        public int $created,
        public int $reused,
        public array $catalogs,
        public array $failures,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
