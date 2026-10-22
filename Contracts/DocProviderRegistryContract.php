<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Contracts;

/**
 * 文档 provider 注册表。
 *
 * Archive 不依赖具体 provider 模块；Gitlab 等实现方在 boot 时注册工厂。
 * 未注册的 provider 一律拒绝（fail closed），不回退到本地文件系统。
 */
interface DocProviderRegistryContract
{
    /**
     * @param  callable(int $tenantId, int|null $connectionId): DocProviderContract  $factory
     */
    public function register(string $name, callable $factory): void;

    public function has(string $name): bool;

    public function resolve(int $tenantId, string $name, ?int $connectionId = null): DocProviderContract;

    /**
     * @return list<string>
     */
    public function names(): array;
}
