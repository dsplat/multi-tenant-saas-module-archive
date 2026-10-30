<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 档案目录批量移动：持久化操作清单（BL-185 E3 Archive）。
 *
 * 一次目录移动共享一个 batch_id；每个 provider 文件一行。provider 写入是
 * 外部副作用，无法与本地 DB 事务原子提交，因此移动前先落 pending 意图，
 * 每步回写 moved/failed，成功批次在目录路径事务内统一 complete（completed_at），
 * 补偿/恢复以本表为唯一事实源，不再依赖进程内 $moved 数组。
 *
 * 字段说明（除任务要求的公共字段外的 additive 元数据）：
 * - provider_file_ref：写入时捕获的 provider 文件标识；provider 移动成功后更新为
 *   返回的新标识，回滚据此定位文件（不依赖可能已变化的 doc_items 行）。
 * - position：批内顺序，用于稳定列出与逆序回滚。
 * - completed_at：批次终结标记；NULL 表示该批次仍可能处于半移动状态，
 *   供恢复入口筛选「未终结」批次（避免把 moved 但 DB 未提交误判为完成）。
 *
 * 幂等：建表走 hasTable；存量同名表按 hasColumn/hasIndex 逐项补齐，可重复执行。
 */
return new class extends Migration
{
    private const TABLE = 'doc_move_operations';

    private const TENANT_BATCH_INDEX = 'doc_move_operations_tenant_batch_index';

    private const STATUS_INDEX = 'doc_move_operations_status_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createTable();

            return;
        }

        $this->addColumnIfMissing('tenant_id', fn (Blueprint $table) => $table->unsignedBigInteger('tenant_id')->nullable());
        $this->addColumnIfMissing('catalog_id', fn (Blueprint $table) => $table->unsignedBigInteger('catalog_id')->nullable());
        $this->addColumnIfMissing('batch_id', fn (Blueprint $table) => $table->string('batch_id', 64)->nullable());
        $this->addColumnIfMissing('provider', fn (Blueprint $table) => $table->string('provider', 50)->nullable());
        $this->addColumnIfMissing('from_path', fn (Blueprint $table) => $table->string('from_path', 500)->nullable());
        $this->addColumnIfMissing('to_path', fn (Blueprint $table) => $table->string('to_path', 500)->nullable());
        $this->addColumnIfMissing('provider_file_ref', fn (Blueprint $table) => $table->string('provider_file_ref', 255)->nullable());
        $this->addColumnIfMissing('position', fn (Blueprint $table) => $table->unsignedInteger('position')->default(0));
        $this->addColumnIfMissing('status', fn (Blueprint $table) => $table->string('status', 20)->default('pending'));
        $this->addColumnIfMissing('attempts', fn (Blueprint $table) => $table->unsignedInteger('attempts')->default(0));
        $this->addColumnIfMissing('error', fn (Blueprint $table) => $table->string('error', 500)->nullable());
        $this->addColumnIfMissing('completed_at', fn (Blueprint $table) => $table->timestamp('completed_at')->nullable());

        if (! Schema::hasIndex(self::TABLE, self::TENANT_BATCH_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['tenant_id', 'batch_id'], self::TENANT_BATCH_INDEX);
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::STATUS_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('status', self::STATUS_INDEX);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->unsignedBigInteger('operation_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('catalog_id');
            $table->string('batch_id', 64)->comment('同一次目录移动共享');
            $table->string('provider', 50);
            $table->string('from_path', 500);
            $table->string('to_path', 500);
            $table->string('provider_file_ref', 255)->nullable()->comment('provider 文件标识；移动成功后为当前位置标识');
            $table->unsignedInteger('position')->default(0)->comment('批内顺序，逆序回滚依据');
            $table->string('status', 20)->default('pending')->comment('pending|moved|failed|rolled_back');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error', 500)->nullable()->comment('脱敏摘要，只放受控 reason');
            $table->timestamp('completed_at')->nullable()->comment('批次终结时间；NULL 表示可能半移动');
            $table->timestamps();

            $table->index(['tenant_id', 'batch_id'], self::TENANT_BATCH_INDEX);
            $table->index('status', self::STATUS_INDEX);
        });
    }

    private function addColumnIfMissing(string $column, Closure $definition): void
    {
        if (Schema::hasColumn(self::TABLE, $column)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }
};
