<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * 档案中心业务异常基类。
 *
 * reason 为稳定的可编程错误码；message 只放脱敏摘要，不透出 provider 原始响应。
 */
class ArchiveException extends HttpException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        int $statusCode = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $message, $previous);
    }

    public static function notFound(string $message = '文档不存在'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function forbidden(string $reason = 'forbidden', string $message = '无权访问该档案资源'): self
    {
        return new self($reason, $message, 403);
    }

    public static function conflict(string $reason, string $message): self
    {
        return new self($reason, $message, 409);
    }

    public static function unprocessable(string $reason, string $message): self
    {
        return new self($reason, $message, 422);
    }
}
