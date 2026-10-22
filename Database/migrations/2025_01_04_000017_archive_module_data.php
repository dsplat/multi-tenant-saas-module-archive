<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 档案中心：RBAC 权限注册（数据迁移，与结构迁移成对）
 *
 * 幂等设计（模块迁移可能在任何环境的既有库上重放）：
 *   - hasTable 守卫：permissions 表不存在时跳过
 *   - 权限 name 唯一键守卫：已存在的权限复用，不重复插入
 * 权限随模块迁移注册，模块卸载时由 down() 一并回收。
 */
return new class extends Migration
{
    /**
     * 与 Routes/api.php 的 capability 默认 permission 映射一致。
     * 旧脚手架的 archive.create/update/delete 不再使用，保留不删以免破坏既有角色。
     */
    private const PERMISSIONS = [
        ['name' => 'archive.view', 'display_name' => '查看档案中心'],
        ['name' => 'archive.manage', 'display_name' => '管理档案目录与删除'],
        ['name' => 'archive.upload', 'display_name' => '上传档案文档'],
        ['name' => 'archive.download', 'display_name' => '下载/预览档案文档'],
        ['name' => 'archive.export', 'display_name' => '导出档案清单'],
    ];

    public function up(): void
    {
        $this->registerPermissions();
        $this->attachToTenantAdmin();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->delete();
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            $exists = DB::table('permissions')->where('name', $permission['name'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('permissions')->insert([
                'permission_id' => $idGenerator->generate(),
                'name' => $permission['name'],
                'display_name' => $permission['display_name'],
                'group' => 'archive',
                'description' => $permission['display_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * tenant_admin 策略是「全部权限减 denylist」，新模块的权限应自动获得。
     * 存量库升级时迁移先于 seeder 跑，若不在此处挂接，tenant_admin 会缺权限
     * 直到下一次手动 re-seed——这是脚手架「可发现性」的根因。
     */
    private function attachToTenantAdmin(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions') || ! Schema::hasTable('permissions')) {
            return;
        }

        $role = DB::table('roles')->where('name', 'tenant_admin')->first();
        if (! $role) {
            return;
        }

        $permIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('permission_id')
            ->all();

        foreach ($permIds as $permId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $role->role_id)
                ->where('permission_id', $permId)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->role_id,
                    'permission_id' => $permId,
                ]);
            }
        }
    }
};
