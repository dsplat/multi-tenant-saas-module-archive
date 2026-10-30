<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 目录批量移动的持久化操作清单（每个 provider 文件一行）。
 *
 * 状态机：
 *   pending -> moved -> (批次完成) completed_at
 *   pending -> failed
 *   moved   -> rolled_back
 *
 * completed_at 只表示「整个批次已终结」：成功移动需在目录路径事务提交时统一写入；
 * 半移动/补偿失败的条目 completed_at 保持 NULL，由恢复入口按条目重试或回滚。
 */
class DocMoveOperation extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_MOVED = 'moved';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $table = 'doc_move_operations';

    protected $primaryKey = 'operation_id';

    protected $fillable = [
        'tenant_id',
        'catalog_id',
        'batch_id',
        'provider',
        'from_path',
        'to_path',
        'provider_file_ref',
        'position',
        'status',
        'attempts',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'catalog_id' => 'integer',
            'position' => 'integer',
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(DocCatalog::class, 'catalog_id', 'catalog_id');
    }

    public function isMoved(): bool
    {
        return $this->status === self::STATUS_MOVED;
    }

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_ROLLED_BACK || $this->completed_at !== null;
    }
}
