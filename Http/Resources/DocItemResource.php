<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Archive\Models\DocItem;

/**
 * 文档元数据输出；不返回 provider token / 永久下载地址 / 正文。
 *
 * @mixin DocItem
 */
class DocItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'doc_item_id' => (int) $this->doc_item_id,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'catalog_id' => (int) $this->catalog_id,
            'catalog_path' => $this->relationLoaded('catalog') ? $this->catalog?->path : null,
            'title' => (string) $this->title,
            'doc_type' => (string) $this->doc_type,
            'mime_type' => (string) $this->mime_type,
            'size' => (int) $this->size,
            'checksum_sha256' => (string) $this->checksum_sha256,
            'current_revision' => (int) $this->current_revision,
            'provider' => (string) $this->provider,
            'provider_path' => (string) $this->provider_path,
            'source' => (string) $this->source,
            'status' => (string) $this->status,
            'visibility' => (string) $this->visibility,
            'retention_until' => $this->retention_until?->toIso8601String(),
            'legal_hold' => (bool) $this->legal_hold,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
