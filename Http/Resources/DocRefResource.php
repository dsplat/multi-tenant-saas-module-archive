<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Archive\Models\DocRef;

/**
 * @mixin DocRef
 */
class DocRefResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'doc_ref_id' => (int) $this->doc_ref_id,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'doc_item_id' => (int) $this->doc_item_id,
            'ref_type' => (string) $this->ref_type,
            'ref_id' => (string) $this->ref_id,
            'relation' => (string) $this->relation,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
