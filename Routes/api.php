<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Archive\Http\Controllers\CatalogController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\DocRefController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\DocumentController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\ExportController;
use MultiTenantSaas\Modules\Archive\Http\Controllers\TemplateController;

/*
|--------------------------------------------------------------------------
| 档案中心 — API 路由（租户自助）
|--------------------------------------------------------------------------
| 基类 ModuleServiceProvider 统一附加：api + auth:sanctum + throttle:api
| + tenant.identify + VerifyOperatorTenant，前缀 api/v1。
| 这里先 operator.identify 装载执行身份，再逐路由走 capability 授权；
| 不写认证中间件，也不从请求参数推导租户/主体。
*/

Route::prefix(config('archive.route_prefix', ''))->middleware('operator.identify')->group(function (): void {
    // 目录
    Route::get('archive/catalogs', [CatalogController::class, 'index'])
        ->middleware('capability:common.archive.catalog.view');
    Route::get('archive/catalogs/tree', [CatalogController::class, 'tree'])
        ->middleware('capability:common.archive.catalog.view');
    Route::post('archive/catalogs', [CatalogController::class, 'store'])
        ->middleware('capability:common.archive.catalog.manage');
    Route::put('archive/catalogs/{id}', [CatalogController::class, 'update'])
        ->middleware('capability:common.archive.catalog.manage');
    Route::delete('archive/catalogs/{id}', [CatalogController::class, 'destroy'])
        ->middleware('capability:common.archive.catalog.manage');

    // 模板
    Route::get('archive/templates', [TemplateController::class, 'index'])
        ->middleware('capability:common.archive.catalog.view');
    Route::post('archive/templates/apply', [TemplateController::class, 'apply'])
        ->middleware('capability:common.archive.template.apply');

    // 文档
    Route::get('archive/documents', [DocumentController::class, 'index'])
        ->middleware('capability:common.archive.document.view');
    Route::post('archive/documents', [DocumentController::class, 'store'])
        ->middleware('capability:common.archive.document.upload');
    Route::get('archive/documents/{id}', [DocumentController::class, 'show'])
        ->middleware('capability:common.archive.document.view');
    Route::get('archive/documents/{id}/download', [DocumentController::class, 'download'])
        ->middleware('capability:common.archive.document.download');
    Route::get('archive/documents/{id}/preview', [DocumentController::class, 'preview'])
        ->middleware('capability:common.archive.document.download');
    Route::delete('archive/documents/{id}', [DocumentController::class, 'destroy'])
        ->middleware('capability:common.archive.document.delete');

    // 引用账本
    Route::get('archive/documents/{docItemId}/refs', [DocRefController::class, 'index'])
        ->middleware('capability:common.archive.ref.manage');
    Route::post('archive/refs', [DocRefController::class, 'store'])
        ->middleware('capability:common.archive.ref.manage');
    Route::delete('archive/refs/{id}', [DocRefController::class, 'destroy'])
        ->middleware('capability:common.archive.ref.manage');

    // 导出
    Route::post('archive/export', [ExportController::class, 'store'])
        ->middleware('capability:common.archive.export');
});
