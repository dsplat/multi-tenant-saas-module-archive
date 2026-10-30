<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 归一化的 provider 异常。
 *
 * message 只允许放脱敏摘要；厂商原始响应（可能含 token/URL 参数）不得透传。
 */
class DocProviderException extends RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const CONFLICT = 'conflict';

    public const PERMISSION_DENIED = 'permission_denied';

    public const RATE_LIMITED = 'rate_limited';

    public const UNAVAILABLE = 'provider_unavailable';

    public const INVALID_PATH = 'invalid_path';

    public const TOO_LARGE = 'too_large';

    public const UNSUPPORTED = 'unsupported';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function make(string $reason, string $message, ?Throwable $previous = null): self
    {
        return new self($reason, $message, $previous);
    }
}
