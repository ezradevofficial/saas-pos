<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/** REST-09: which permission a ticket status change needs: `serve` only for the move to served, by someone who cannot update. */
final class TicketPermission
{
    public const UPDATE = 'restaurant.ticket.update';

    public const SERVE = 'restaurant.ticket.serve';

    public static function for(User $user, mixed $status, ?Scope $scope = null): string
    {
        $resolver = app(ScopeResolver::class);

        return $status === 'served' && ! $resolver->can($user, self::UPDATE, $scope) && $resolver->can($user, self::SERVE, $scope) ? self::SERVE : self::UPDATE;
    }
}
