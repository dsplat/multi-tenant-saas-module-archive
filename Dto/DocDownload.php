<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Dto;

/**
 * 下载代理结果。stream 由调用方负责关闭。
 *
 * rangeSupported=false 表示当前 provider 不支持 Range，调用方应明确告知客户端。
 */
final readonly class DocDownload
{
    /**
     * @param  resource|string  $stream
     */
    public function __construct(
        public mixed $stream,
        public int $size,
        public string $mimeType,
        public string $filename,
        public ?string $checksumSha256,
        public bool $rangeSupported = false,
    ) {}
}
