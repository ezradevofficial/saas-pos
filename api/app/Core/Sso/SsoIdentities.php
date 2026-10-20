<?php

namespace App\Core\Sso;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoIdentity;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-11, ADR 023: a user's links to IdP subjects. Unlinking archives a
 * link (TEN-06), audited as `auth.sso.unlink`; repointing a connection at
 * another IdP archives all its links, audited once on the connection as
 * `core.sso.identities_archive`.
 */
class SsoIdentities
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @return Collection<int, SsoIdentity> $user's live links, with their connection's name */
    public function of(User $user): Collection
    {
        return SsoIdentity::query()->where('user_id', $user->id)->whereNull('archived_at')->orderBy('created_at')->get();
    }

    /** @return array<string, mixed> */
    public static function present(SsoIdentity $identity, ?SsoConnection $connection = null): array
    {
        $connection ??= SsoConnection::query()->find($identity->connection_id);

        return [
            'id' => $identity->id,
            'connection_id' => $identity->connection_id,
            'connection_name' => $connection?->name,
            'provider' => $connection?->provider,
            'email' => $identity->email,
            'last_sign_in_at' => $identity->last_sign_in_at?->toIso8601String(),
            'created_at' => $identity->created_at?->toIso8601String(),
        ];
    }

    public function unlink(SsoIdentity $identity, string $by): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($identity, $by) {
            $identity = SsoIdentity::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();

            if ($identity->archived_at !== null) {
                return;
            }

            $identity->forceFill(['archived_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('auth.sso.unlink', $identity, ['user_id' => $identity->user_id, 'connection_id' => $identity->connection_id, 'email' => $identity->email], ['archived' => true, 'by' => $by]);
        });
    }

    /**
     * Archive every live link of $connection (its IdP changed). Runs inside
     * the caller's transaction.
     *
     * @param  list<string>  $changed  the identity-bearing settings that changed
     */
    public function archiveForConnection(SsoConnection $connection, array $changed): int
    {
        $count = SsoIdentity::query()->where('connection_id', $connection->id)->whereNull('archived_at')
            ->update(['archived_at' => CarbonImmutable::now()]);

        if ($count > 0) {
            $this->auditor->record('core.sso.identities_archive', $connection, null, ['archived' => $count, 'changed' => $changed]);
        }

        return $count;
    }
}
