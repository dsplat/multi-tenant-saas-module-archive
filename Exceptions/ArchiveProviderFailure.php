<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Exceptions;

use MultiTenantSaas\Modules\Archive\Exceptions\DocProviderException as ProviderFailure;

/**
 * provider 异常归一化：调用方只看到稳定 reason 与脱敏消息，不透出厂商原始响应。
 */
final class ArchiveProviderFailure extends ArchiveException
{
    public static function from(ProviderFailure $exception): self
    {
        $status = match ($exception->reason) {
            ProviderFailure::NOT_FOUND => 404,
            ProviderFailure::CONFLICT => 409,
            ProviderFailure::PERMISSION_DENIED => 403,
            ProviderFailure::RATE_LIMITED => 429,
            ProviderFailure::INVALID_PATH => 422,
            ProviderFailure::TOO_LARGE => 413,
            ProviderFailure::UNSUPPORTED => 422,
            default => 503,
        };

        return new self($exception->reason, $exception->getMessage(), $status, $exception);
    }
}
