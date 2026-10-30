<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

use MultiTenantSaas\Modules\Archive\Dto\DocActor;
use MultiTenantSaas\Modules\Archive\Exceptions\ArchiveException;
use MultiTenantSaas\Modules\Archive\Models\DocItem;

/**
 * 文档可见性 / 项目范围 / 动作决策（服务层第二道闸）。
 *
 * 能力点由中间件判定；这里保证直接调用服务（工具、Job）也无法绕过
 * 租户、项目范围与 visibility。
 */
final class DocAccessService
{
    public function assertCanView(DocItem $item, DocActor $actor): void
    {
        if (! $actor->canView) {
            throw ArchiveException::forbidden('document_view_denied', '无权查看该文档');
        }

        if (! $this->inProjectScope($item, $actor)) {
            throw ArchiveException::forbidden('project_scope_denied', '文档不在当前项目范围内');
        }

        switch ($item->visibility) {
            case DocItem::VISIBILITY_MANAGERS:
                if (! $actor->isAdmin && ! $actor->hasRole('tenant_admin', 'manager', 'project_manager')) {
                    throw ArchiveException::forbidden('visibility_denied', '该文档仅管理层可见');
                }
                break;
            case DocItem::VISIBILITY_APPROVERS:
                if (! $actor->isAdmin && ! $actor->hasRole('approver', 'tenant_admin')) {
                    throw ArchiveException::forbidden('visibility_denied', '该文档仅审批人可见');
                }
                break;
            case DocItem::VISIBILITY_PRIVATE:
                if (! $actor->isAdmin && (int) $item->uploaded_by !== (int) $actor->subjectId) {
                    throw ArchiveException::forbidden('visibility_denied', '该文档仅上传者可见');
                }
                break;
            case DocItem::VISIBILITY_PROJECT:
            default:
                break;
        }
    }

    public function assertCanDownload(DocItem $item, DocActor $actor): void
    {
        $this->assertCanView($item, $actor);

        if (! $actor->canDownload) {
            throw ArchiveException::forbidden('download_denied', '缺少下载/预览权限');
        }
    }

    public function assertCanUpload(DocActor $actor): void
    {
        if (! $actor->canUpload) {
            throw ArchiveException::forbidden('upload_denied', '缺少上传权限');
        }
    }

    public function assertCanDelete(DocItem $item, DocActor $actor, bool $force): void
    {
        $this->assertCanView($item, $actor);

        if (! $actor->canDelete) {
            throw ArchiveException::forbidden('delete_denied', '缺少删除权限');
        }

        if ($force && ! $actor->isAdmin) {
            throw ArchiveException::forbidden('force_delete_denied', '强制删除需要管理员权限');
        }
    }

    public function assertCanExport(DocActor $actor): void
    {
        if (! $actor->canExport) {
            throw ArchiveException::forbidden('export_denied', '缺少导出权限');
        }
    }

    public function assertCanManageCatalogs(DocActor $actor): void
    {
        if (! $actor->canManageCatalogs) {
            throw ArchiveException::forbidden('catalog_manage_denied', '缺少目录管理权限');
        }
    }

    public function assertCanManageRefs(DocActor $actor): void
    {
        if (! $actor->canManageRefs) {
            throw ArchiveException::forbidden('ref_manage_denied', '缺少引用管理权限');
        }
    }

    private function inProjectScope(DocItem $item, DocActor $actor): bool
    {
        if ($actor->projectIds === []) {
            return true;
        }

        if ($item->project_id === null) {
            return true;
        }

        return in_array((int) $item->project_id, $actor->projectIds, true);
    }
}
