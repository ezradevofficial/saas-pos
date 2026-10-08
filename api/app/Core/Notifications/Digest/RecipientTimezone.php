<?php

namespace App\Core\Notifications\Digest;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;

/**
 * NOT-05: the time zone a user's digest is timed in. Users have no time
 * zone or default company of their own, so it is taken from where they
 * work: their first role assignment (the oldest), at a location or branch
 * the branch's time zone (else its company's), at a company that
 * company's. A user whose first assignment is tenant-wide, or who has
 * none, gets the tenant's first company (the oldest active one). With no
 * company at all, UTC. Read in the current tenant (row-level security).
 */
class RecipientTimezone
{
    public const FALLBACK = 'UTC';

    /** @var array<string, string> */
    private array $cache = [];

    public function for(User $user): string
    {
        return $this->cache[$user->tenant_id.'|'.$user->getKey()] ??= $this->resolve($user);
    }

    private function resolve(User $user): string
    {
        $assignment = DB::table('role_assignments')
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')->orderBy('id')
            ->first(['scope_type', 'scope_id']);

        $zone = match ($assignment?->scope_type) {
            Scope::LOCATION => DB::table('locations')
                ->join('branches', 'branches.id', '=', 'locations.branch_id')
                ->join('companies', 'companies.id', '=', 'branches.company_id')
                ->where('locations.id', $assignment->scope_id)
                ->value(DB::raw('coalesce(branches.timezone, companies.timezone)')),
            Scope::BRANCH => DB::table('branches')
                ->join('companies', 'companies.id', '=', 'branches.company_id')
                ->where('branches.id', $assignment->scope_id)
                ->value(DB::raw('coalesce(branches.timezone, companies.timezone)')),
            Scope::COMPANY => DB::table('companies')->where('id', $assignment->scope_id)->value('timezone'),
            default => null,
        };

        $zone ??= DB::table('companies')->whereNull('archived_at')->orderBy('created_at')->orderBy('id')->value('timezone');

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : self::FALLBACK;
    }
}
