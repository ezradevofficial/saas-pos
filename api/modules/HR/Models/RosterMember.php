<?php

namespace Modules\HR\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR-17: an employee on a roster, with a day offset into its rotation. Changes are audited on the roster. */
class RosterMember extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hr_roster_members';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['offset_days' => 'integer', 'from_date' => 'immutable_date', 'to_date' => 'immutable_date'];
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
