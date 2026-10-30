<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive;

use MultiTenantSaas\Access\ResourceRegistry;
use MultiTenantSaas\Authorization\CapabilityDefinition;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Contracts\AuthorizationCapabilityRegistryContract;
use MultiTenantSaas\Contracts\CapabilityRequirementResolverContract;
use MultiTenantSaas\Contracts\SceneRegistryContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderRegistryContract;
use MultiTenantSaas\Modules\Archive\Services\DocProviderRegistry;
use MultiTenantSaas\Modules\Archive\Services\Tools\ArchiveDocSummaryHandler;
use MultiTenantSaas\Modules\Archive\Services\Tools\ArchiveMetadataSuggestHandler;
use MultiTenantSaas\Modules\Archive\Services\Tools\ArchiveSearchHandler;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * 档案中心（P0）。
 *
 * 只保存目录 / 文档元数据 / 引用账本，正文由 DocProviderContract 实现方
 * （P0 为 Gitlab 文档仓）持有；未注册 provider 一律 fail closed。
 */
class ArchiveServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'archive';

    /**
     * capability => 默认 permission。
     *
     * @var array<string, string>
     */
    private const CAPABILITIES = [
        'common.archive.catalog.view' => 'archive.view',
        'common.archive.catalog.manage' => 'archive.manage',
        'common.archive.document.view' => 'archive.view',
        'common.archive.document.upload' => 'archive.upload',
        'common.archive.document.download' => 'archive.download',
        'common.archive.document.delete' => 'archive.manage',
        'common.archive.ref.manage' => 'archive.manage',
        'common.archive.template.apply' => 'archive.manage',
        'common.archive.export' => 'archive.export',
        // 兼容旧脚手架能力键：只做别名，不新增权限名。
        'common.archive.list' => 'archive.view',
        'common.archive.read' => 'archive.view',
        'common.archive.create' => 'archive.upload',
        'common.archive.update' => 'archive.manage',
        'common.archive.delete' => 'archive.manage',
    ];

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(DocProviderRegistryContract::class, fn () => new DocProviderRegistry);
    }

    protected function bootModule(): void
    {
        $this->registerAuthorizationCapabilities();
        $this->registerResources();
        $this->registerTools();
        $this->registerScenes();
    }

    private function registerAuthorizationCapabilities(): void
    {
        if (! $this->app->bound(AuthorizationCapabilityRegistryContract::class)
            || ! $this->app->bound(CapabilityRequirementResolverContract::class)) {
            return;
        }

        $registry = $this->app->make(AuthorizationCapabilityRegistryContract::class);
        $requirements = $this->app->make(CapabilityRequirementResolverContract::class);

        foreach (self::CAPABILITIES as $key => $permission) {
            $registry->register(new CapabilityDefinition(
                key: $key,
                subjectTypes: [ExecutionIdentity::TYPE_OPERATOR],
                targetScope: CapabilityDefinition::TARGET_TENANT,
                admissionPolicy: [ExecutionIdentity::TYPE_OPERATOR => ['member']],
            ));
        }

        // 权限映射保持字面量：AdminConsolePermissionMapTest 静态解析 capability→permission。
        $requirements->register('common.archive.catalog.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.view']);
        $requirements->register('common.archive.catalog.manage', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.manage']);
        $requirements->register('common.archive.document.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.view']);
        $requirements->register('common.archive.document.upload', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.upload']);
        $requirements->register('common.archive.document.download', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.download']);
        $requirements->register('common.archive.document.delete', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.manage']);
        $requirements->register('common.archive.ref.manage', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.manage']);
        $requirements->register('common.archive.template.apply', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.manage']);
        $requirements->register('common.archive.export', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.export']);
        $requirements->register('common.archive.list', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.view']);
        $requirements->register('common.archive.read', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.view']);
        $requirements->register('common.archive.create', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.upload']);
        $requirements->register('common.archive.update', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.manage']);
        $requirements->register('common.archive.delete', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'archive.manage']);
    }

    /**
     * 受保护资源声明：策略必需、敏感、可过滤列。
     */
    private function registerResources(): void
    {
        if (! $this->app->bound(ResourceRegistry::class)) {
            return;
        }

        $registry = $this->app->make(ResourceRegistry::class);

        $registry->register(
            name: 'doc_catalogs',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: ['tenant_id', 'project_id', 'parent_id', 'catalog_type', 'status', 'path'],
            returnableColumns: ['catalog_id', 'tenant_id', 'project_id', 'parent_id', 'name', 'path', 'catalog_type', 'status'],
            supportedActions: ['read', 'aggregate', 'export'],
        );

        $registry->register(
            name: 'doc_items',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: ['tenant_id', 'project_id', 'catalog_id', 'doc_type', 'status', 'visibility', 'checksum_sha256'],
            returnableColumns: [
                'doc_item_id', 'tenant_id', 'project_id', 'catalog_id', 'title', 'doc_type', 'mime_type',
                'size', 'checksum_sha256', 'current_revision', 'provider', 'status', 'visibility',
            ],
            supportedActions: ['read', 'aggregate', 'export'],
        );

        $registry->register(
            name: 'doc_refs',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: ['tenant_id', 'project_id', 'doc_item_id', 'ref_type', 'ref_id', 'relation'],
            returnableColumns: ['doc_ref_id', 'tenant_id', 'project_id', 'doc_item_id', 'ref_type', 'ref_id', 'relation'],
            supportedActions: ['read', 'aggregate', 'export'],
        );
    }

    private function registerTools(): void
    {
        if (! $this->app->bound(ToolRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            slug: 'archive_search',
            name: '搜索档案',
            description: '按关键词/项目/目录/类型搜索档案文档元数据（不返回正文）',
            handlerClass: ArchiveSearchHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'keyword' => ['type' => 'string', 'description' => '标题关键词'],
                    'project_id' => ['type' => 'integer', 'description' => '项目 ID（可选）'],
                    'catalog_id' => ['type' => 'integer', 'description' => '目录 ID（可选）'],
                    'doc_type' => ['type' => 'string', 'description' => '文档类型（可选）'],
                    'per_page' => ['type' => 'integer', 'description' => '每页数量', 'default' => 20],
                ],
                'required' => [],
            ],
            category: 'archive',
            risk: Tool::RISK_L1,
            audience: Tool::AUDIENCE_OPERATOR,
            dataResource: 'doc_items',
            dataAction: Tool::DATA_ACTION_READ,
            requiredCapability: 'common.archive.document.view',
        );

        $registry->register(
            slug: 'archive_doc_summary',
            name: '文档摘要片段',
            description: '读取文档开头的截断片段用于摘要（不返回全文）',
            handlerClass: ArchiveDocSummaryHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'doc_item_id' => ['type' => 'integer', 'description' => '文档 ID'],
                ],
                'required' => ['doc_item_id'],
            ],
            category: 'archive',
            risk: Tool::RISK_L1,
            audience: Tool::AUDIENCE_OPERATOR,
            dataResource: 'doc_items',
            dataAction: Tool::DATA_ACTION_READ,
            requiredCapability: 'common.archive.document.download',
        );

        $registry->register(
            slug: 'archive_metadata_suggest',
            name: '档案元数据建议',
            description: '根据标题/文件名建议文档类型、目录类型与标签（只建议，不落库）',
            handlerClass: ArchiveMetadataSuggestHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => '文档标题'],
                    'filename' => ['type' => 'string', 'description' => '文件名（可选）'],
                    'doc_type' => ['type' => 'string', 'description' => '已有类型（可选）'],
                ],
                'required' => ['title'],
            ],
            category: 'archive',
            risk: Tool::RISK_L1,
            audience: Tool::AUDIENCE_OPERATOR,
            dataAction: Tool::DATA_ACTION_READ,
            requiredCapability: 'common.archive.document.upload',
        );
    }

    private function registerScenes(): void
    {
        if (! $this->app->bound(SceneRegistryContract::class)) {
            return;
        }

        $this->app->make(SceneRegistryContract::class)
            ->registerScene('archive', '档案中心', 'FolderOpened', '项目档案目录、文档元数据与引用账本', 100);
    }
}
