<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Dto;

/**
 * provider 侧文件元数据（不含正文）。
 */
final readonly class DocProviderFile
{
    public function __construct(
        public string $ref,
        public string $path,
        public int $size,
        public ?string $mimeType = null,
        public ?string $checksumSha256 = null,
        public ?string $commit = null,
        public ?string $updatedAt = null,
    ) {}
}
