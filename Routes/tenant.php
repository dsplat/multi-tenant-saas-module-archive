<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Archive\Http\Controllers\ArchiveController;

/*
|--------------------------------------------------------------------------
| 档案中心 — 租户管理路由
|--------------------------------------------------------------------------
| 供本租户自助使用：基类附加 api + auth:sanctum + throttle:api + tenant.identify + VerifyOperatorTenant + module.enabled，前缀 api/v1。
|
| 本文件按存在性被基类加载；不需要这个路由面时，直接删除本文件即可。
|
| 示例（取消注释即生效）：
| Route::prefix('archive')->group(function () {
|     Route::get('/', [ArchiveController::class, 'index'])
|         ->middleware('rbac.permission:archive.view');
| });
*/
