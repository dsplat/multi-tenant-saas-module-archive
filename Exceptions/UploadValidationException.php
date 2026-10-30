<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Exceptions;

/**
 * 上传校验失败（大小 / MIME / 扩展名 / 校验和）。
 */
final class UploadValidationException extends ArchiveException
{
    public static function tooLarge(int $size, int $max): self
    {
        return new self('too_large', "文件超过大小限制（{$size} > {$max} 字节）", 413);
    }

    public static function mime(string $mime): self
    {
        return new self('mime_not_allowed', "不允许的 MIME 类型：{$mime}", 422);
    }

    public static function extension(string $extension): self
    {
        return new self('extension_not_allowed', "不允许的文件扩展名：{$extension}", 422);
    }

    public static function checksum(): self
    {
        return new self('checksum_mismatch', '文件校验和与声明不一致', 422);
    }

    public static function invalid(string $message): self
    {
        return new self('upload_invalid', $message, 422);
    }
}
