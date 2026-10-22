<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Dto;

use MultiTenantSaas\Context\ExecutionIdentity;

/**
 * 档案中心执行主体（服务层授权的显式输入）。
 *
 * 能力点由 Web 中间件 / 工具运行时先行判定；服务层仍按动作复核，
 * 避免「只依赖列表页隐藏按钮」的越权。project_ids 为空表示不限项目
 * （由宿主身份策略决定，仅限受信调用方）。
 */
final readonly class DocActor
{
    /**
     * @param  list<int>  $projectIds
     * @param  list<string>  $roles
     */
    public function __construct(
        public ?int $subjectId = null,
        public string $subjectType = ExecutionIdentity::TYPE_SERVICE,
        public array $projectIds = [],
        public array $roles = [],
        public bool $canView = true,
        public bool $canUpload = false,
        public bool $canDownload = false,
        public bool $canDelete = false,
        public bool $canExport = false,
        public bool $canManageCatalogs = false,
        public bool $canManageRefs = false,
        public bool $isAdmin = false,
    ) {}

    public static function system(): self
    {
        return new self(
            subjectId: null,
            subjectType: ExecutionIdentity::TYPE_SERVICE,
            canView: true,
            canUpload: true,
            canDownload: true,
            canDelete: true,
            canExport: true,
            canManageCatalogs: true,
            canManageRefs: true,
            isAdmin: true,
        );
    }

    /**
     * 从当前执行身份构造；能力标志由调用入口按已通过的 capability 显式授予。
     *
     * @param  array<string, bool>  $abilities
     */
    public static function fromIdentity(?ExecutionIdentity $identity, array $abilities = []): self
    {
        if ($identity === null) {
            return self::system();
        }

        $roles = array_values(array_map('strval', array_filter(
            (array) ($identity->attributes['roles'] ?? []),
            fn ($role) => is_scalar($role),
        )));

        $projectIds = array_values(array_map('intval', array_filter(
            (array) ($identity->attributes['project_ids'] ?? []),
            fn ($projectId) => is_numeric($projectId),
        )));

        $isAdmin = (bool) ($identity->attributes['is_admin'] ?? false)
            || array_intersect($roles, ['tenant_admin', 'super_admin']) !== [];

        return new self(
            subjectId: is_numeric($identity->subjectId) ? (int) $identity->subjectId : null,
            subjectType: $identity->type,
            projectIds: $projectIds,
            roles: $roles,
            canView: (bool) ($abilities['view'] ?? true),
            canUpload: (bool) ($abilities['upload'] ?? false),
            canDownload: (bool) ($abilities['download'] ?? false),
            canDelete: (bool) ($abilities['delete'] ?? false),
            canExport: (bool) ($abilities['export'] ?? false),
            canManageCatalogs: (bool) ($abilities['manage_catalogs'] ?? false),
            canManageRefs: (bool) ($abilities['manage_refs'] ?? false),
            isAdmin: $isAdmin,
        );
    }

    public function hasRole(string ...$roles): bool
    {
        return array_intersect($this->roles, $roles) !== [];
    }
}
