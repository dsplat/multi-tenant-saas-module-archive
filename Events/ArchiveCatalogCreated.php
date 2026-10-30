<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 目录创建事件。
 */
class ArchiveCatalogCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int|string $catalogId,
        public readonly ?int $projectId,
        public readonly string $path,
        public readonly ?string $templateKey = null,
    ) {}
}
