<?php

namespace App\Core\Identity\Http\Lists;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The names of the users a reader may see (RBAC-04: users with a role
 * where the reader holds `core.user.view`, every user from tenant scope),
 * plus the reader themselves, read once. Exports name another user (an
 * owner, an inviter) only through this; anyone else is "Someone you can’t
 * see" (EXP-01).
 */
final class VisibleUserNames
{
    /** @var array<string, string>|null */
    private ?array $names = null;

    public function __construct(private readonly User $reader) {}

    /** The user's name, "Someone you can’t see" when out of sight, null without a user. */
    public function label(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return $this->names()[$userId] ?? __('core.list.someone_hidden');
    }

    /** @return array<string, string> */
    private function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $visible = app(ScopeResolver::class)->visibleIds($this->reader, 'core.user.view');
        $query = User::query();

        if (! $visible->all) {
            $query->whereHas('assignments', fn (Builder $q) => ScopeNames::constrain($q, $visible));
        }

        return $this->names = [...$query->pluck('name', 'id')->all(), $this->reader->id => $this->reader->name];
    }
}
