<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Dto;

/**
 * 预览结果。
 *
 * mode=html：content 为服务端已转义的 HTML（Markdown 预览）。
 * mode=stream：stream 为 PDF/图片流，由调用方内联输出。
 */
final readonly class DocPreview
{
    public const MODE_HTML = 'html';

    public const MODE_STREAM = 'stream';

    /**
     * @param  resource|string|null  $stream
     */
    public function __construct(
        public string $mode,
        public string $contentType,
        public string $filename,
        public mixed $stream = null,
        public ?string $html = null,
        public int $size = 0,
    ) {}
}
