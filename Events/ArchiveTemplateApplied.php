<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 目录模板应用完成（含部分失败明细，宿主可据此告警/重试）。
 */
class ArchiveTemplateApplied
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<array{path: string, reason: string, message: string}>  $failures
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $projectId,
        public readonly string $templateKey,
        public readonly string $idempotencyKey,
        public readonly int $created,
        public readonly int $reused,
        public readonly array $failures = [],
    ) {}
}
