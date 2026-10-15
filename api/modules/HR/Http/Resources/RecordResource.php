<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\Appraisal;
use Modules\HR\Models\Checklist;
use Modules\HR\Models\ChecklistItem;
use Modules\HR\Models\DisciplinaryCase;

/** HR-28, HR-29, HR-30: checklists, disciplinary cases and appraisals. */
class RecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return match (true) {
            $row instanceof Checklist => [
                'id' => $row->id, 'employee' => Refs::employee($row->employee), 'kind' => $row->kind, 'name' => $row->name, 'status' => $row->status,
                'completed_at' => Refs::at($row->completed_at), 'created_at' => Refs::at($row->created_at),
                'items' => $row->items->map(fn (ChecklistItem $item) => [
                    'id' => $item->id, 'position' => $item->position, 'title' => $item->title, 'due_on' => Refs::date($item->due_on), 'role_key' => $item->role_key,
                    'done_at' => Refs::at($item->done_at), 'done_by' => $item->done_by, 'note' => $item->note,
                ])->all(),
            ],
            $row instanceof DisciplinaryCase => [
                'id' => $row->id, 'number' => $row->number, 'employee' => Refs::employee($row->employee), 'category' => $row->category,
                'incident_date' => Refs::date($row->incident_date), 'description' => $row->description, 'action_taken' => $row->action_taken,
                'status' => $row->status, 'outcome' => $row->outcome, 'closed_at' => Refs::at($row->closed_at), 'created_at' => Refs::at($row->created_at),
            ],
            $row instanceof Appraisal => [
                'id' => $row->id, 'cycle' => $row->cycle === null ? null : ['id' => $row->cycle->id, 'name' => $row->cycle->name, 'status' => $row->cycle->status],
                'employee' => Refs::employee($row->employee), 'reviewer' => Refs::user($row->reviewer), 'goals' => $row->goals ?? [],
                'self_rating' => $row->self_rating, 'self_comments' => $row->self_comments, 'manager_rating' => $row->manager_rating,
                'manager_comments' => $row->manager_comments, 'overall_rating' => $row->overall_rating === null ? null : (string) $row->overall_rating,
                'status' => $row->status, 'completed_at' => Refs::at($row->completed_at), 'acknowledged_at' => Refs::at($row->acknowledged_at),
            ],
            default => [],
        };
    }
}
