<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 文档软删除/归档事件。
 */
class ArchiveDocumentDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int|string $docItemId,
        public readonly string $status,
        public readonly bool $forced = false,
    ) {}
}
