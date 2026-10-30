<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 业务对象到文档的多态引用账本（只存引用，不存业务对象内容）。
 */
class DocRef extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const RELATION_ATTACHMENT = 'attachment';

    public const RELATION_EVIDENCE = 'evidence';

    public const RELATION_DELIVERABLE = 'deliverable';

    public const RELATION_OTHER = 'other';

    public const UPDATED_AT = null;

    protected $table = 'doc_refs';

    protected $primaryKey = 'doc_ref_id';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'doc_item_id',
        'ref_type',
        'ref_id',
        'relation',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'project_id' => 'integer',
            'doc_item_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(DocItem::class, 'doc_item_id', 'doc_item_id');
    }
}
