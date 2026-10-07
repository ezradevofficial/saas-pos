<?php

namespace Tests\Feature\Isolation;

use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Tenancy\TenantContext;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Feature\Core\Tenancy\RlsCoverageTest;
use Tests\Support\TenantFixture;
use Tests\Support\TwoTenants;
use Tests\TestCase;

/**
 * TEN-01, the sprint exit criterion: a user of tenant A provably cannot see
 * or change anything of tenant B through the API, its lists, its exports or
 * the database itself. Tables and routes are discovered at run time, so a
 * new table or route is covered (or fails loudly) without editing this file.
 *
 * Run alone with `php artisan test --testsuite=Isolation`.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshTenantDatabase;

    /**
     * Routes that take no bearer token, with why they cannot leak another
     * tenant's data. Keyed "METHOD uri".
     */
    public const PUBLIC_ROUTES = [
        'POST api/v1/auth/sign-up' => 'creates a new tenant; reads nothing of an existing one',
        'POST api/v1/auth/verify' => 'needs the challenge id and its one-time code',
        'POST api/v1/auth/verify/resend' => 'needs the challenge id; answers only a masked destination',
        'POST api/v1/auth/sign-in' => 'needs the login and password',
        'POST api/v1/auth/two-factor/challenge' => 'needs the challenge id and its one-time code',
        'POST api/v1/auth/password/forgot' => 'answers the same whether or not the login exists',
        'POST api/v1/auth/password/reset' => 'needs the one-time reset code',
        'GET api/v1/auth/invitations/{token}' => 'the 40-character invitation token is the credential',
        'POST api/v1/auth/invitations/{token}/accept' => 'the 40-character invitation token is the credential',
        'POST api/v1/devices/pair' => 'the one-time pairing code is the credential',
    ];

    /**
     * Route parameter name => which of B's ids to put there. A route with a
     * parameter missing here fails the suite: add the parameter (and, in
     * TwoTenants, a row of that type in both tenants).
     */
    public const PARAMETERS = [
        'company' => 'company',
        'branch' => 'branch',
        'location' => 'location',
        'device' => 'device',
        'user' => 'user',
        'role' => 'role',
        'invitation' => 'invitation',
        'assignment' => 'assignment',
        'id' => 'session', // DELETE auth/sessions/{id}
    ];

    private TwoTenants $tenants;

    /** @var array<string, list<string>> */
    private array $identifiers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenants = TwoTenants::build($this);
        app(TenantContext::class)->set(null);
    }

    // ---- Database ---------------------------------------------------------

    public function test_the_suite_runs_as_the_rls_bound_app_role(): void
    {
        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertSame('app', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_in_tenant_a_context_no_table_shows_a_row_of_tenant_b(): void
    {
        $a = $this->tenants->a->tenantId;
        $b = $this->tenants->b->tenantId;

        foreach ($this->tenantTables() as $table) {
            $inB = $this->asTenant($b, fn () => $this->rows("select count(*) from \"{$table}\""));
            $this->assertGreaterThan(0, $inB, "{$table}: tenant B has no rows, so isolation is not proven; extend tests/Support/TwoTenants.php");

            $this->asTenant($a, function () use ($table, $a, $b) {
                $this->assertSame(0, $this->rows("select count(*) from \"{$table}\" where tenant_id = ?", [$b]), "{$table}: tenant A sees rows of tenant B");

                $all = $this->rows("select count(*) from \"{$table}\"");
                $this->assertGreaterThan(0, $all, "{$table}: tenant A has no rows, so isolation is not proven; extend tests/Support/TwoTenants.php");
                $this->assertSame($this->rows("select count(*) from \"{$table}\" where tenant_id = ?", [$a]), $all, "{$table}: tenant A sees rows that are not its own");
            });
        }

        // tenants is keyed on id rather than tenant_id.
        $this->asTenant($a, function () use ($a, $b) {
            $this->assertSame(0, $this->rows('select count(*) from tenants where id = ?', [$b]), 'tenants: tenant A sees tenant B');
            $this->assertSame(1, $this->rows('select count(*) from tenants where id = ?', [$a]));
            $this->assertSame(1, $this->rows('select count(*) from tenants'), 'tenants: tenant A sees another tenant');
        });
    }

    public function test_without_a_tenant_context_every_tenant_table_is_empty(): void
    {
        app(TenantContext::class)->set(null);
        $this->assertSame('', DB::selectOne("select coalesce(current_setting('app.tenant_id', true), '') as id")->id);

        foreach ([...$this->tenantTables(), 'tenants'] as $table) {
            $this->assertSame(0, $this->rows("select count(*) from \"{$table}\""), "{$table}: rows are visible without a tenant context");
        }
    }

    // ---- Routes -----------------------------------------------------------

    public function test_every_api_route_is_authenticated_or_allow_listed_as_public(): void
    {
        $seen = [];

        foreach ($this->apiRoutes() as $route) {
            foreach ($this->methods($route) as $method) {
                $key = "{$method} {$route->uri()}";
                $seen[] = $key;

                if (! isset(self::PUBLIC_ROUTES[$key])) {
                    $this->assertContains('auth:sanctum', $route->gatherMiddleware(), "{$key} has no bearer authentication and is not in PUBLIC_ROUTES");
                }
            }
        }

        foreach (array_keys(self::PUBLIC_ROUTES) as $key) {
            $this->assertContains($key, $seen, "PUBLIC_ROUTES lists {$key}, which no longer exists");
        }
    }

    public function test_every_route_with_ids_refuses_tenant_b_ids_and_changes_nothing_of_b(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $before = $this->snapshot($b->tenantId);
        $called = 0;

        foreach ($this->apiRoutes() as $route) {
            if ($route->parameterNames() === [] || $this->isPublic($route)) {
                continue;
            }

            foreach ($this->methods($route) as $method) {
                $uri = $this->uriWith($route, $b);
                $response = $this->json($method, $uri, $this->hijackBody($b), $a->bearer());
                $called++;

                $this->assertContains($response->status(), [403, 404], "{$method} {$uri} with tenant B's ids answered {$response->status()} to tenant A: {$response->getContent()}");
                $this->assertBodyHasNothingOf($b, $response, "{$method} {$uri}");

                // Control: the same GET with A's own ids works, so the 404 above is isolation, not a bad URL.
                if ($method === 'GET') {
                    $this->json('GET', $this->uriWith($route, $a), [], $a->bearer())->assertOk();
                }
            }
        }

        $this->assertGreaterThan(0, $called);
        $this->assertSame($before, $this->snapshot($b->tenantId), "tenant B's rows changed after tenant A called its routes with B's ids");
    }

    public function test_list_routes_show_nothing_of_tenant_b_to_the_owner_the_branch_manager_or_a_device(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $okForOwner = 0;

        foreach ($this->apiRoutes() as $route) {
            if ($route->parameterNames() !== [] || $this->isPublic($route) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            foreach (['owner', 'manager', 'device'] as $who) {
                foreach ([[], ['status' => 'all', 'per_page' => 200], ['format' => 'csv']] as $query) {
                    $uri = '/'.$route->uri().($query === [] ? '' : '?'.http_build_query($query));
                    $response = $this->get($uri, $a->bearer($who));

                    $this->assertBodyHasNothingOf($b, $response, "GET {$uri} as A's {$who}");

                    if ($who === 'owner' && $query === [] && $response->getStatusCode() === 200) {
                        $okForOwner++;
                    }
                }
            }
        }

        $this->assertGreaterThan(5, $okForOwner, 'the owner should be able to read most lists; the check above proves nothing otherwise');
    }

    // ---- Exports ----------------------------------------------------------

    public function test_the_access_review_csv_of_tenant_a_contains_nothing_of_tenant_b(): void
    {
        $a = $this->tenants->a;

        $response = $this->get('/api/v1/access-review?format=csv', $a->bearer())->assertOk();
        $csv = $response->streamedContent();

        // Control: A's own people are in it.
        $this->assertStringContainsString('owner-a@example.com', $csv);
        $this->assertStringContainsString('manager-a@example.com', $csv);

        foreach ($this->identifiersOf($this->tenants->b) as $value) {
            $this->assertStringNotContainsString($value, $csv, "the access review export of tenant A contains {$value} of tenant B");
        }

        foreach (['Tenant B', 'Owner B', 'Manager B', 'Company B', 'Branch B', 'Outlet B', 'Clerk B'] as $name) {
            $this->assertStringNotContainsString($name, $csv);
        }
    }

    // ---- Global tables ----------------------------------------------------

    /**
     * personal_access_tokens and verification_challenges carry a tenant_id
     * but are deliberately global (read before the tenant is known; see
     * RlsCoverageTest::GLOBAL_TABLES). Authenticated code reaches them only
     * through the signed-in user's own rows: prove it with B's rows.
     */
    public function test_global_tables_are_reached_only_through_the_signed_in_users_own_rows(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;

        // Sessions: B's token id is neither listed nor revocable by A.
        $this->getJson('/api/v1/auth/sessions', $a->bearer())->assertOk()
            ->assertJsonMissing(['id' => $b->id('session')]);
        $this->deleteJson("/api/v1/auth/sessions/{$b->id('session')}", [], $a->bearer())->assertNotFound();
        $this->assertTrue(DB::table('personal_access_tokens')->where('id', $b->id('session'))->exists());

        // Challenges: B starts SMS two-factor; A cannot spend B's code.
        $this->postJson('/api/v1/me/two-factor/sms', [], $b->bearer())->assertOk();
        $code = $this->lastVerificationCode();
        $challenge = VerificationChallenge::where('tenant_id', $b->tenantId)
            ->where('purpose', VerificationChallenge::PURPOSE_TWO_FACTOR)->sole();

        $refused = $this->postJson('/api/v1/me/two-factor/sms/confirm', ['code' => $code], $a->bearer());
        $this->assertContains($refused->status(), [403, 404, 422], 'tenant A confirmed with tenant B\'s two-factor code');
        $this->assertNull($challenge->fresh()->consumed_at, "tenant A consumed tenant B's challenge");

        // Control: B's own code still works.
        $this->postJson('/api/v1/me/two-factor/sms/confirm', ['code' => $code], $b->bearer())->assertOk();
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Every public table with a tenant_id, read from the catalogue at run
     * time so a new table is covered automatically. The global tables are
     * left out here (they have no RLS by design) and are covered by
     * test_global_tables_are_reached_only_through_the_signed_in_users_own_rows.
     *
     * @return list<string>
     */
    private function tenantTables(): array
    {
        $tables = collect(DB::select("
            select c.table_name from information_schema.columns c
            join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema = 'public' and c.column_name = 'tenant_id' and t.table_type = 'BASE TABLE'
            order by c.table_name
        "))->pluck('table_name')->diff(RlsCoverageTest::GLOBAL_TABLES)->values()->all();

        $this->assertNotEmpty($tables);

        return $tables;
    }

    /** @return list<RoutingRoute> */
    private function apiRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/'))
            ->values()->all();
    }

    /** @return list<string> */
    private function methods(RoutingRoute $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD']));
    }

    private function isPublic(RoutingRoute $route): bool
    {
        foreach ($this->methods($route) as $method) {
            if (isset(self::PUBLIC_ROUTES["{$method} {$route->uri()}"])) {
                return true;
            }
        }

        return false;
    }

    private function uriWith(RoutingRoute $route, TenantFixture $tenant): string
    {
        $uri = '/'.$route->uri();

        foreach ($route->parameterNames() as $name) {
            $this->assertArrayHasKey($name, self::PARAMETERS, sprintf(
                'Route %s has the parameter {%s}, which the isolation suite cannot fill: add it to %s::PARAMETERS and give both tenants a row of that type in tests/Support/TwoTenants.php.',
                $route->uri(), $name, self::class,
            ));
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $tenant->id(self::PARAMETERS[$name]), $uri);
        }

        return $uri;
    }

    /** A body every mutating route would accept, pointing at more of B where a route takes ids. */
    private function hijackBody(TenantFixture $b): array
    {
        return [
            'name' => 'Hijacked by A',
            'code' => 'HIJACK',
            'type' => 'outlet',
            'country' => 'KE',
            'role_id' => $b->id('role'),
            'scope_type' => 'tenant',
            'permissions' => ['core.location.view'],
        ];
    }

    /** B's ids (every row of every tenant table, its tokens and challenges) and contacts. */
    private function identifiersOf(TenantFixture $tenant): array
    {
        return $this->identifiers[$tenant->tenantId] ??= $this->asTenant($tenant->tenantId, function () use ($tenant) {
            $ids = [$tenant->tenantId, ...array_values($tenant->ids), ...$tenant->contacts];

            foreach ($this->tenantTables() as $table) {
                if ($this->hasColumn($table, 'id')) {
                    array_push($ids, ...DB::table($table)->pluck('id')->map(fn ($id) => (string) $id));
                }
            }

            foreach (RlsCoverageTest::GLOBAL_TABLES as $table) {
                array_push($ids, ...DB::table($table)->where('tenant_id', $tenant->tenantId)->pluck('id')->map(fn ($id) => (string) $id));
            }

            return array_values(array_unique($ids));
        });
    }

    private function assertBodyHasNothingOf(TenantFixture $tenant, TestResponse $response, string $what): void
    {
        $body = $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();

        foreach ($this->identifiersOf($tenant) as $value) {
            $this->assertStringNotContainsString($value, $body, "{$what} ({$response->getStatusCode()}) exposes {$value} of tenant B");
        }
    }

    /**
     * A fingerprint of every row tenant B owns: each tenant table read in
     * B's context, plus B's rows of the global tables.
     *
     * @return array<string, string>
     */
    private function snapshot(string $tenantId): array
    {
        return $this->asTenant($tenantId, function () use ($tenantId) {
            $hashes = [];

            foreach ([...$this->tenantTables(), 'tenants'] as $table) {
                $hashes[$table] = DB::selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) as h from \"{$table}\" t")->h;
            }

            foreach (RlsCoverageTest::GLOBAL_TABLES as $table) {
                $hashes[$table] = DB::selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) as h from \"{$table}\" t where tenant_id = ?", [$tenantId])->h;
            }

            return $hashes;
        });
    }

    private function lastVerificationCode(): string
    {
        $codes = [];

        foreach (Notification::sentNotifications() as $byId) {
            foreach ($byId as $byClass) {
                foreach ($byClass[VerificationCode::class] ?? [] as $sent) {
                    $codes[] = $sent['notification']->code;
                }
            }
        }

        $this->assertNotEmpty($codes);

        return end($codes);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::selectOne(
            "select exists(select 1 from information_schema.columns where table_schema = 'public' and table_name = ? and column_name = ?) as e",
            [$table, $column],
        )->e;
    }

    private function rows(string $sql, array $bindings = []): int
    {
        return (int) DB::selectOne(str_replace('count(*)', 'count(*) as n', $sql), $bindings)->n;
    }

    private function asTenant(string $tenantId, callable $fn): mixed
    {
        return app(TenantContext::class)->run($tenantId, $fn);
    }
}
