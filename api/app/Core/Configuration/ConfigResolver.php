<?php

namespace App\Core\Configuration;

use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\Models\ConfigVersion;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * LAY-06: which published configuration applies to a user at a place.
 * The most specific published version wins, along the chain
 *
 *   user → role(s) → location → branch → company → tenant
 *
 * limited to the scope types the kind allows. The place is optional (no
 * place: only the user, their roles and the tenant are looked at).
 *
 * Role rule (docs/adr/010): the roles considered are the user's active
 * roles whose assignment covers the place (any assignment when there is
 * no place). They are tried in order of the assignment's scope, narrowest
 * first (location, branch, company, tenant), then oldest assignment first;
 * the first role with a published version wins. So "Cashier at this
 * outlet" beats "Accountant for the company", and between two roles held
 * at the same level the one held longer wins.
 *
 * The payload returned has gone through the kind's merger (LAY-07). When
 * nothing is published along the chain, the kind's defaults (if any)
 * are returned with no source. A published payload the merger cannot
 * read (malformed, or a merger that throws) is logged and the kind's
 * defaults are returned instead: resolving never fails a screen.
 */
class ConfigResolver
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    /**
     * @return array{payload: ?array, version: ?ConfigVersion, document: ?ConfigDocument}
     */
    public function resolve(ConfigKind $kind, string $key, User $user, ?Place $place = null): array
    {
        $candidates = $this->chain($kind, $user, $place);

        if ($candidates !== []) {
            $documents = ConfigDocument::query()
                ->with('published')
                ->where('kind', $kind->key)
                ->where('key', $key)
                ->whereHas('published')
                ->where(function ($q) use ($candidates) {
                    foreach ($candidates as [$type, $id]) {
                        $q->orWhere(fn ($c) => $c->where('scope_type', $type)->where('scope_id', $id));
                    }
                })
                ->get()
                ->keyBy(fn (ConfigDocument $d) => $d->scope_type.':'.($d->scope_id ?? ''));

            foreach ($candidates as [$type, $id]) {
                $document = $documents->get($type.':'.($id ?? ''));

                if ($document !== null) {
                    try {
                        $payload = $document->published->payload;

                        return [
                            'payload' => $kind->merge(is_array($payload) ? $payload : throw new UnexpectedValueException('The payload is not an object.'), $key),
                            'version' => $document->published,
                            'document' => $document,
                        ];
                    } catch (Throwable $e) {
                        Log::warning('Published configuration could not be resolved; using the kind\'s defaults.', [
                            'kind' => $kind->key, 'key' => $key, 'document_id' => $document->id,
                            'version_id' => $document->published->id, 'error' => $e->getMessage(),
                        ]);

                        return ['payload' => $this->defaults($kind, $key), 'version' => null, 'document' => null];
                    }
                }
            }
        }

        return ['payload' => $this->defaults($kind, $key), 'version' => null, 'document' => null];
    }

    /**
     * The published version that applies at $place without a user (a till,
     * NFR-04): the most specific of location → branch → company → tenant
     * the kind allows. Not merged: the caller merges it with what the place
     * holds (LAY-07). Null when nothing is published along the chain.
     */
    public function publishedAt(ConfigKind $kind, string $key, Place $place): ?ConfigVersion
    {
        $candidates = array_values(array_filter([
            [ConfigDocument::LOCATION, $place->locationId],
            [ConfigDocument::BRANCH, $place->branchId],
            [ConfigDocument::COMPANY, $place->companyId],
            [ConfigDocument::TENANT, null],
        ], fn (array $pair) => ($pair[0] === ConfigDocument::TENANT || $pair[1] !== null) && $kind->allows($pair[0])));

        if ($candidates === []) {
            return null;
        }

        $documents = ConfigDocument::query()
            ->with('published')
            ->where('kind', $kind->key)
            ->where('key', $key)
            ->whereHas('published')
            ->where(function ($q) use ($candidates) {
                foreach ($candidates as [$type, $id]) {
                    $q->orWhere(fn ($c) => $c->where('scope_type', $type)->where('scope_id', $id));
                }
            })
            ->get()
            ->keyBy(fn (ConfigDocument $d) => $d->scope_type.':'.($d->scope_id ?? ''));

        foreach ($candidates as [$type, $id]) {
            $document = $documents->get($type.':'.($id ?? ''));

            if ($document !== null) {
                return $document->published->setRelation('document', $document);
            }
        }

        return null;
    }

    /**
     * Every published document along the chain that applies to the user,
     * most specific first, each merged with the catalogue (LAY-07). A list
     * view offers the views of all of them: the user's own, their roles',
     * the place's and the tenant's (LAY-04). A layer the merger can't read
     * is logged and left out.
     *
     * @return list<array{payload: array, version: ConfigVersion, document: ConfigDocument}>
     */
    public function layers(ConfigKind $kind, string $key, User $user, ?Place $place = null): array
    {
        $candidates = $this->chain($kind, $user, $place);

        if ($candidates === []) {
            return [];
        }

        $documents = ConfigDocument::query()
            ->with('published')
            ->where('kind', $kind->key)
            ->where('key', $key)
            ->whereHas('published')
            ->where(function ($q) use ($candidates) {
                foreach ($candidates as [$type, $id]) {
                    $q->orWhere(fn ($c) => $c->where('scope_type', $type)->where('scope_id', $id));
                }
            })
            ->get()
            ->keyBy(fn (ConfigDocument $d) => $d->scope_type.':'.($d->scope_id ?? ''));

        $layers = [];

        foreach ($candidates as [$type, $id]) {
            $document = $documents->get($type.':'.($id ?? ''));

            if ($document === null) {
                continue;
            }

            try {
                $payload = $document->published->payload;
                $layers[] = [
                    'payload' => $kind->merge(is_array($payload) ? $payload : throw new UnexpectedValueException('The payload is not an object.'), $key),
                    'version' => $document->published,
                    'document' => $document,
                ];
            } catch (Throwable $e) {
                Log::warning('Published configuration layer could not be resolved; leaving it out.', [
                    'kind' => $kind->key, 'key' => $key, 'document_id' => $document->id,
                    'version_id' => $document->published->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $layers;
    }

    /** The kind's defaults; null (the client's built-in layout) when even they fail. */
    public function defaults(ConfigKind $kind, string $key = ConfigKind::DEFAULT_KEY): ?array
    {
        try {
            return $kind->defaultPayload($key);
        } catch (Throwable $e) {
            Log::warning('Configuration defaults could not be built.', ['kind' => $kind->key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The (scope_type, scope_id) pairs to try, most specific first.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public function chain(ConfigKind $kind, User $user, ?Place $place = null): array
    {
        $chain = [[ConfigDocument::USER, $user->id]];

        foreach ($this->roleIds($user, $place) as $roleId) {
            $chain[] = [ConfigDocument::ROLE, $roleId];
        }

        if ($place !== null) {
            foreach ([ConfigDocument::LOCATION => $place->locationId, ConfigDocument::BRANCH => $place->branchId, ConfigDocument::COMPANY => $place->companyId] as $type => $id) {
                if ($id !== null) {
                    $chain[] = [$type, $id];
                }
            }
        }

        $chain[] = [ConfigDocument::TENANT, null];

        return array_values(array_filter($chain, fn (array $pair) => $kind->allows($pair[0])));
    }

    /**
     * The user's active roles covering $place (any, without a place), in
     * the documented order: narrowest assignment scope first, then the
     * oldest assignment.
     *
     * @return list<string>
     */
    public function roleIds(User $user, ?Place $place = null): array
    {
        $active = $this->scopes->roleIds($user, $place?->scope());

        if ($active === []) {
            return [];
        }

        $chain = $place === null ? null : ($this->scopes->chainOf($place->scope()) ?? []);
        $rank = array_flip([Scope::LOCATION, Scope::BRANCH, Scope::COMPANY, Scope::TENANT]);

        return RoleAssignment::query()
            ->where('user_id', $user->id)
            ->whereIn('role_id', $active)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['role_id', 'scope_type', 'scope_id', 'created_at'])
            ->filter(fn (RoleAssignment $a) => $chain === null || in_array("{$a->scope_type}:{$a->scope_id}", $chain, true))
            ->sortBy(fn (RoleAssignment $a) => $rank[$a->scope_type] ?? 9, SORT_REGULAR)
            ->pluck('role_id')
            ->unique()
            ->values()
            ->all();
    }
}
