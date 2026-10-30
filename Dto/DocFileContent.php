<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Dto;

/**
 * provider 返回的文件流。调用方负责关闭流。
 */
final readonly class DocFileContent
{
    /**
     * @param  resource|string  $stream
     */
    public function __construct(
        public mixed $stream,
        public int $size,
        public ?string $mimeType = null,
        public ?string $checksumSha256 = null,
    ) {}
}
