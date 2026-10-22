<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 系统自动归档（周报/评审报告/变更单/交付记录等）。
 */
class ArchiveAutoArchived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int|string $docItemId,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $docType,
        public readonly int $revision,
        public readonly string $action,
    ) {}
}
