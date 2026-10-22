<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Exceptions;

/**
 * 路径规范化失败（路径遍历、绝对路径、控制字符、空段、超长等）。
 */
final class InvalidDocPathException extends ArchiveException
{
    public static function because(string $message): self
    {
        return new self('invalid_path', $message, 422);
    }
}
