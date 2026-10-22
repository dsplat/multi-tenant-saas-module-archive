<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Dto;

use MultiTenantSaas\Modules\Archive\Models\DocItem;

/**
 * 上传/自动归档结果：区分新建、新修订与幂等重放，供事件与调用方决策。
 */
final readonly class DocUploadResult
{
    public const ACTION_CREATED = 'created';

    public const ACTION_REVISED = 'revised';

    public const ACTION_REPLAYED = 'replayed';

    public function __construct(
        public DocItem $item,
        public string $action,
        public bool $idempotent = false,
    ) {}

    public function isCreated(): bool
    {
        return $this->action === self::ACTION_CREATED;
    }

    public function isRevised(): bool
    {
        return $this->action === self::ACTION_REVISED;
    }
}
