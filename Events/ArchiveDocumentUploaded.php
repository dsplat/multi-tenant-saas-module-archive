<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 文档首次上传成功。
 */
class ArchiveDocumentUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int|string $docItemId,
        public readonly ?int $projectId,
        public readonly int|string $catalogId,
        public readonly int $revision,
        public readonly string $checksumSha256,
    ) {}
}
