<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services\Tools;

use MultiTenantSaas\Context\ExecutionIdentityManager;
use MultiTenantSaas\Modules\Archive\Dto\DocActor;

/**
 * 工具处理器从执行身份构造档案操作主体。
 *
 * 工具参数不可信，项目范围只取服务端身份；工具运行时已按 requiredCapability
 * 判定能力点，这里把对应动作显式授予服务层复核。
 */
trait ResolvesDocActor
{
    /**
     * @param  array<string, bool>  $abilities
     */
    private function docActor(array $abilities): DocActor
    {
        $manager = $this->identityManager();
        $identity = $manager->current();

        return DocActor::fromIdentity($identity, $abilities);
    }

    private function identityManager(): ExecutionIdentityManager
    {
        if (! app()->bound(ExecutionIdentityManager::class)) {
            app()->singleton(ExecutionIdentityManager::class);
        }

        return app(ExecutionIdentityManager::class);
    }
}
