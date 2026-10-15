<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** HR-27: a document's description (the file itself through its link, audited). */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'category' => $this->category,
            'title' => $this->title,
            'expires_on' => Refs::date($this->expires_on),
            'file_name' => $this->file_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => Refs::at($this->created_at),
            'archived_at' => Refs::at($this->archived_at),
        ];
    }
}
