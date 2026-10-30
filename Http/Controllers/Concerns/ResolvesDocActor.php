<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Controllers\Concerns;

use MultiTenantSaas\Context\ExecutionIdentityManager;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Archive\Dto\DocActor;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 档案控制器的租户 / 操作主体解析。
 *
 * operator 身份由 operator.identify 装载；这里只读取，绝不从请求参数推导。
 * 能力点已由 capability 中间件判定，abilities 仅把该动作显式传给服务层复核。
 */
trait ResolvesDocActor
{
    protected function tenantId(): int
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null || ! ctype_digit((string) $tenantId) || (int) $tenantId <= 0) {
            throw new AccessDeniedHttpException('缺少租户上下文');
        }

        return (int) $tenantId;
    }

    /**
     * @param  array<string, bool>  $abilities
     */
    protected function docActor(array $abilities): DocActor
    {
        if (! app()->bound(ExecutionIdentityManager::class)) {
            app()->singleton(ExecutionIdentityManager::class);
        }

        return DocActor::fromIdentity(app(ExecutionIdentityManager::class)->current(), $abilities);
    }
}
