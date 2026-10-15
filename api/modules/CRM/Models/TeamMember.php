<?php

namespace Modules\CRM\Models;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-04: a user in a team.
 *
 * @property string $team_id
 * @property string $user_id
 */
class TeamMember extends CrmModel
{
    protected $table = 'crm_team_members';

    protected string $auditResource = 'team_member';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
