<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 文档项元数据；P0 一个逻辑文档一行，正文/历史版本以 provider 为准。
 */
class DocItem extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_DELETED = 'deleted';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_IMPORT = 'import';

    public const VISIBILITY_PROJECT = 'project';

    public const VISIBILITY_MANAGERS = 'managers';

    public const VISIBILITY_APPROVERS = 'approvers';

    public const VISIBILITY_PRIVATE = 'private';

    protected $table = 'doc_items';

    protected $primaryKey = 'doc_item_id';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'catalog_id',
        'title',
        'doc_type',
        'mime_type',
        'size',
        'checksum_sha256',
        'current_revision',
        'provider',
        'provider_file_ref',
        'provider_commit',
        'provider_path',
        'uploaded_by',
        'source',
        'status',
        'visibility',
        'retention_until',
        'legal_hold',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'project_id' => 'integer',
            'catalog_id' => 'integer',
            'size' => 'integer',
            'current_revision' => 'integer',
            'uploaded_by' => 'integer',
            'legal_hold' => 'boolean',
            'retention_until' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(DocCatalog::class, 'catalog_id', 'catalog_id');
    }

    public function refs(): HasMany
    {
        return $this->hasMany(DocRef::class, 'doc_item_id', 'doc_item_id');
    }

    public function isDeleted(): bool
    {
        return $this->status === self::STATUS_DELETED;
    }
}
