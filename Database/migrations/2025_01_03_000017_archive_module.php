<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 档案中心：建表（结构迁移，与数据迁移成对）
 *
 * P0 只落三类元数据表，不建 doc_revisions / 不存正文：
 *   doc_catalogs  目录树（provider 路径权威）
 *   doc_items     文档元数据（正文由 provider 持有）
 *   doc_refs      业务对象到文档的多态引用账本
 *
 * 幂等设计（模块迁移可能在任何环境的既有库上重放）：
 *   - hasTable 守卫：下游项目若已手工建过同名表，跳过建表
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCatalogs();
        $this->createItems();
        $this->createRefs();
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_refs');
        Schema::dropIfExists('doc_items');
        Schema::dropIfExists('doc_catalogs');
    }

    private function createCatalogs(): void
    {
        if (Schema::hasTable('doc_catalogs')) {
            return;
        }

        Schema::create('doc_catalogs', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_type', 20)->default('tenant')->comment('project|tenant');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('path', 500);
            $table->string('catalog_type', 50)->default('other');
            $table->integer('sort')->default(0);
            $table->string('provider', 50);
            $table->string('provider_ref', 255)->nullable();
            $table->string('status', 20)->default('active')->comment('active|archived');
            $table->string('template_key', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'path'], 'doc_catalogs_tenant_project_path_unique');
            $table->index(['tenant_id', 'project_id', 'parent_id'], 'doc_catalogs_tenant_project_parent_index');
            $table->index(['tenant_id', 'catalog_type'], 'doc_catalogs_tenant_type_index');
        });
    }

    private function createItems(): void
    {
        if (Schema::hasTable('doc_items')) {
            return;
        }

        Schema::create('doc_items', function (Blueprint $table) {
            $table->unsignedBigInteger('doc_item_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('catalog_id');
            $table->string('title', 255);
            $table->string('doc_type', 50);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size')->default(0);
            $table->char('checksum_sha256', 64);
            $table->unsignedInteger('current_revision')->default(1);
            $table->string('provider', 50);
            $table->string('provider_file_ref', 255)->nullable();
            $table->string('provider_commit', 100)->nullable();
            $table->string('provider_path', 500);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('source', 20)->default('manual')->comment('manual|system|import');
            $table->string('status', 20)->default('active')->comment('active|archived|deleted');
            $table->string('visibility', 20)->default('project')->comment('project|managers|approvers|private');
            $table->timestamp('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'project_id', 'catalog_id'], 'doc_items_tenant_project_catalog_index');
            $table->index(['tenant_id', 'checksum_sha256'], 'doc_items_tenant_checksum_index');
            $table->index(['tenant_id', 'status'], 'doc_items_tenant_status_index');
        });
    }

    private function createRefs(): void
    {
        if (Schema::hasTable('doc_refs')) {
            return;
        }

        Schema::create('doc_refs', function (Blueprint $table) {
            $table->unsignedBigInteger('doc_ref_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('doc_item_id');
            $table->string('ref_type', 50);
            $table->string('ref_id', 100);
            $table->string('relation', 20)->default('attachment')->comment('attachment|evidence|deliverable|other');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['doc_item_id', 'ref_type', 'ref_id'], 'doc_refs_item_type_ref_unique');
            $table->index(['tenant_id', 'ref_type', 'ref_id'], 'doc_refs_tenant_type_ref_index');
        });
    }
};
