<?php

namespace App\Core\Approvals\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a request's history (APR-03, APR-04): requested, approved,
 * rejected, returned, commented, info_requested, attached, reminded,
 * escalated, reassigned, blocked, auto_approved, auto_rejected,
 * auto_failed, closed. Append-only (database trigger).
 *
 * @property string $id
 * @property string $request_id
 * @property ?string $assignment_id
 * @property string $type
 * @property ?string $user_id
 * @property ?string $on_behalf_of
 * @property ?string $comment
 * @property array<string, mixed> $data
 */
class ApprovalAction extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = ['request_id', 'assignment_id', 'type', 'user_id', 'on_behalf_of', 'comment', 'data', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
