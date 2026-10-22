<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 文档目录（Archive 只保存目录元数据，正文在 provider）。
 */
class DocCatalog extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const SCOPE_PROJECT = 'project';

    public const SCOPE_TENANT = 'tenant';

    protected $table = 'doc_catalogs';

    protected $primaryKey = 'catalog_id';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'scope_type',
        'parent_id',
        'name',
        'path',
        'catalog_type',
        'sort',
        'provider',
        'provider_ref',
        'status',
        'template_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'project_id' => 'integer',
            'parent_id' => 'integer',
            'sort' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'catalog_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'catalog_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocItem::class, 'catalog_id', 'catalog_id');
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
