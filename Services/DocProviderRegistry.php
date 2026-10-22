<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use MultiTenantSaas\Modules\Archive\Contracts\DocProviderContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderRegistryContract;
use MultiTenantSaas\Modules\Archive\Exceptions\DocProviderException;

final class DocProviderRegistry implements DocProviderRegistryContract
{
    /** @var array<string, callable(int, int|null): DocProviderContract> */
    private array $factories = [];

    /** @var array<string, DocProviderContract> */
    private array $resolved = [];

    public function register(string $name, callable $factory): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('provider 名称不能为空');
        }

        $this->factories[$name] = $factory;
        $this->resolved = array_filter(
            $this->resolved,
            fn (string $key) => ! str_starts_with($key, $name . ':'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    public function resolve(int $tenantId, string $name, ?int $connectionId = null): DocProviderContract
    {
        $key = $name . ':' . $tenantId . ':' . ($connectionId ?? 'default');

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $factory = $this->factories[$name] ?? throw DocProviderException::make(
            DocProviderException::UNSUPPORTED,
            "未注册文档 provider：{$name}",
        );

        $provider = $factory($tenantId, $connectionId);

        if (! $provider instanceof DocProviderContract) {
            throw DocProviderException::make(
                DocProviderException::UNAVAILABLE,
                "文档 provider 工厂返回类型非法：{$name}",
            );
        }

        return $this->resolved[$key] = $provider;
    }

    public function names(): array
    {
        return array_keys($this->factories);
    }
}
