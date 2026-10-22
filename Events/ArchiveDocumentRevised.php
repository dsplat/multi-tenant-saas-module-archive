<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 文档新修订写入成功。
 */
class ArchiveDocumentRevised
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int|string $docItemId,
        public readonly int $revision,
        public readonly string $checksumSha256,
        public readonly ?string $providerCommit = null,
    ) {}
}
