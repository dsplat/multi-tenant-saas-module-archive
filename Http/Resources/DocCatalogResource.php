<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Archive\Models\DocCatalog;

/**
 * @mixin DocCatalog
 */
class DocCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'catalog_id' => (int) $this->catalog_id,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'parent_id' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'scope_type' => (string) $this->scope_type,
            'name' => (string) $this->name,
            'path' => (string) $this->path,
            'catalog_type' => (string) $this->catalog_type,
            'sort' => (int) $this->sort,
            'provider' => (string) $this->provider,
            'status' => (string) $this->status,
            'template_key' => $this->template_key,
            'metadata' => $this->metadata,
            'children_count' => isset($this->children_count) ? (int) $this->children_count : null,
            'items_count' => isset($this->items_count) ? (int) $this->items_count : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
