<?php

namespace Tests\Feature\Isolation;

use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\MasterData\History\HistoryTypes;
use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\GlobalTables;
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
        'tenant_currency' => 'tenant_currency',
        'tax_code' => 'tax_code',
        'tax_category' => 'tax_category',
        'price_list' => 'price_list',
        'party' => 'party',
        'record' => 'party', // GET history/{type}/{record}, with type = party
        'id' => 'session', // DELETE auth/sessions/{id}
    ];

    /**
     * Route parameters that name global reference data, never a tenant row,
     * with the value to call them with. A route whose parameters are all
     * global answers the same to every tenant: it is called with this value,
     * must succeed, and must show nothing of tenant B.
     */
    public const GLOBAL_PARAMETERS = [
        'country_pack' => 'KE', // GET country-packs/{country_pack}: the published pack (CP-01)
        'type' => 'party', // GET history/{type}/{record}: a record type name from an allow-list (MD-07); the record is B's
    ];

    /**
     * Body fields named `*_id` and which of B's ids to send in them.
     * `scope_id` follows its sibling `scope_type` (SCOPE_IDS).
     */
    public const REFERENCE_FIELDS = [
        'role_id' => 'role',
        'company_id' => 'company',
        'branch_id' => 'branch',
        'location_id' => 'location',
        'device_id' => 'device',
        'user_id' => 'user',
        'invitation_id' => 'invitation',
        'assignment_id' => 'assignment',
        'tax_code_id' => 'tax_code',
        'price_list_id' => 'price_list',
        'assign_to_company_id' => 'company',
        'scope_id' => null,
    ];

    /**
     * MD-07: every history type (HistoryTypes::names()) => which of B's ids
     * is a record of that type. A type registered without an entry here
     * fails the suite.
     */
    public const HISTORY_TYPES = [
        'party' => 'party',
        'tax_code' => 'tax_code',
        'tax_category' => 'tax_category',
        'price_list' => 'price_list',
        'company' => 'company',
        'branch' => 'branch',
        'location' => 'location',
        'user' => 'user',
        'role' => 'role',
    ];

    /** scope_type => which of B's ids goes in scope_id. */
    public const SCOPE_IDS = ['tenant' => 'tenant', 'company' => 'company', 'branch' => 'branch', 'location' => 'location'];

    /** Fields ending in `_id` that are not references to rows, with why. */
    public const NOT_REFERENCES = [
        'tax_id' => "a company's tax registration number, free text",
    ];

    /**
     * The queries every list route is called with (routes with ids too, with
     * B's ids and, as a control, A's). The filters match rows both tenants
     * have (TwoTenants enters USD/KES shop rates in each).
     */
    public const LIST_QUERIES = [
        [],
        ['status' => 'all', 'per_page' => 200],
        ['format' => 'csv'],
        ['pair' => 'USD/KES', 'from' => '2000-01-01', 'to' => '2100-12-31', 'kind' => 'shop'],
        // MD-01: both tenants have a VIP customer named "Customer A|B".
        ['search' => 'Customer', 'role' => 'customer', 'tag' => 'vip'],
    ];

    /** Query parameters LIST_QUERIES covers; `page` only pages through the same rows. */
    public const LIST_QUERY_PARAMETERS = ['status', 'per_page', 'page', 'format', 'pair', 'from', 'to', 'kind', 'search', 'role', 'tag'];

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

            if ($this->hasOnlyGlobalParameters($route)) {
                $this->assertSame(['GET'], $this->methods($route), "{$route->uri()} changes global data through the tenant API");

                foreach (self::LIST_QUERIES as $query) {
                    $uri = $this->uriWith($route, $a).($query === [] ? '' : '?'.http_build_query($query));
                    $response = $this->json('GET', $uri, [], $a->bearer());
                    $query === [] ? $response->assertOk() : $this->assertContains($response->status(), [200, 422], "GET {$uri} answered {$response->status()}");
                    $this->assertBodyHasNothingOf($b, $response, "GET {$uri}");
                    $called++;
                }

                continue;
            }

            foreach ($this->methods($route) as $method) {
                // A GET is also called with every list query (filters included).
                foreach ($method === 'GET' ? self::LIST_QUERIES : [[]] as $query) {
                    $suffix = $query === [] ? '' : '?'.http_build_query($query);
                    $uri = $this->uriWith($route, $b).$suffix;
                    $response = $this->json($method, $uri, $method === 'GET' ? [] : $this->hijackBody($b), $a->bearer());
                    $called++;

                    // 404 exactly: A's Owner holds every permission, so a 403 here would hide whether the id was resolved.
                    $this->assertSame(404, $response->status(), "{$method} {$uri} with tenant B's ids answered {$response->status()} to tenant A: {$response->getContent()}");
                    $this->assertBodyHasNothingOf($b, $response, "{$method} {$uri}");

                    // Control: the same GET with A's own ids works (a route may refuse
                    // another list's filter, but never as not found), so the 404 above
                    // is isolation, not a bad URL.
                    if ($method === 'GET') {
                        $control = $this->json('GET', $this->uriWith($route, $a).$suffix, [], $a->bearer());
                        $query === [] ? $control->assertOk() : $this->assertContains($control->status(), [200, 422], "GET {$this->uriWith($route, $a)}{$suffix} answered {$control->status()}");
                        $this->assertBodyHasNothingOf($b, $control, "GET {$this->uriWith($route, $a)}{$suffix}");
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $called);
        $this->assertSame($before, $this->snapshot($b->tenantId), "tenant B's rows changed after tenant A called its routes with B's ids");

        // Control: the filter query really selects A's rows on the rate history.
        $filtered = $this->json('GET', "/api/v1/companies/{$a->id('company')}/exchange-rates?".http_build_query(self::LIST_QUERIES[3]), [], $a->bearer())->assertOk();
        $this->assertCount(2, $filtered->json('data'));

        // Control: the party search really selects A's customer (and nothing of B, checked above).
        $parties = $this->json('GET', '/api/v1/parties?'.http_build_query(self::LIST_QUERIES[4]), [], $a->bearer())->assertOk();
        $this->assertSame([$a->id('customer')], array_column($parties->json('data'), 'id'));
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
                foreach (self::LIST_QUERIES as $query) {
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

        // Controls: the manager and the device really read data in the checks above.
        foreach (['/api/v1/me', '/api/v1/me/permissions', '/api/v1/locations'] as $uri) {
            $this->get($uri, $a->bearer('manager'))->assertOk();
        }
        $this->assertNotEmpty($this->get('/api/v1/locations', $a->bearer('manager'))->json('data'));
        $this->get('/api/v1/devices/me', $a->bearer('device'))->assertOk()->assertJsonPath('data.id', $a->id('device'));
    }

    /**
     * Code-level guard: every query parameter a GET route reads (its Form
     * Request rules, and query()/input()/... calls in the request class and
     * the controller method) must be one the list check above exercises. A
     * new `?search=` or filter therefore fails here until the list check
     * calls it with B's values.
     */
    public function test_list_routes_read_only_query_parameters_the_suite_exercises(): void
    {
        $checked = 0;

        foreach ($this->apiRoutes() as $route) {
            // Routes with ids are listed too: they take the same queries (with B's and A's ids) above.
            if ($this->isPublic($route) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            foreach ($this->queryParametersRead($route) as $parameter) {
                $this->assertContains($parameter, self::LIST_QUERY_PARAMETERS, sprintf(
                    'GET %s reads the query parameter [%s], which the isolation list check never sends: add it to %s::LIST_QUERY_PARAMETERS and to the queries of test_list_routes_show_nothing_of_tenant_b_to_the_owner_the_branch_manager_or_a_device (with tenant B\'s values for a search or filter).',
                    $route->uri(), $parameter, self::class,
                ));
            }
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }

    public function test_every_history_type_refuses_tenant_b_records(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $types = app(HistoryTypes::class)->names();
        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            $this->assertArrayHasKey($type, self::HISTORY_TYPES, sprintf(
                'The history type [%s] has no fixture: add it to %s::HISTORY_TYPES and give both tenants a record of that type in tests/Support/TwoTenants.php.',
                $type, self::class,
            ));
            $fixture = self::HISTORY_TYPES[$type];

            $refused = $this->getJson("/api/v1/history/{$type}/{$b->id($fixture)}", $a->bearer());
            $this->assertSame(404, $refused->status(), "GET history/{$type} with tenant B's record answered {$refused->status()}");
            $this->assertBodyHasNothingOf($b, $refused, "GET history/{$type} with B's record");

            // Control: A's own record has a history, and it shows nothing of B.
            $own = $this->getJson("/api/v1/history/{$type}/{$a->id($fixture)}", $a->bearer())->assertOk();
            $this->assertNotEmpty($own->json('data'), "history/{$type} of A's own record is empty, so the check proves nothing");
            $this->assertBodyHasNothingOf($b, $own, "GET history/{$type} with A's record");
        }
    }

    // ---- Ids in request bodies -------------------------------------------

    /**
     * Foreign keys are checked by PostgreSQL without row-level security, so
     * a body naming B's role or location could be stored in A's rows. Every
     * mutating route is called on A's own resources (it passes binding) with
     * each id field of its Form Request set to one of B's ids: always 404 or
     * 422, and afterwards no A row references anything of B.
     */
    public function test_ids_of_tenant_b_in_request_bodies_are_refused_and_never_stored(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $before = $this->snapshot($b->tenantId);
        $hijacked = [];

        foreach ($this->apiRoutes() as $route) {
            if ($this->isPublic($route)) {
                continue;
            }

            foreach (array_diff($this->methods($route), ['GET']) as $method) {
                $key = "{$method} {$route->uri()}";
                $idFields = $this->idFields($this->ruleKeys($route, $method, $a));

                if ($idFields === []) {
                    continue;
                }

                $base = $this->bodyFor($key, $a);
                $this->assertNotNull($base, "{$key} takes ids in its body ({$this->list($idFields)}): add a valid body for it to bodyFor() so the isolation suite can send B's ids");
                $uri = $this->uriWith($route, $a);

                foreach ($this->hijackVariants($base, $idFields, $b) as $label => $body) {
                    $response = $this->json($method, $uri, $body, $a->bearer());
                    $this->assertContains($response->status(), [404, 422], "{$key} with {$label} of tenant B answered {$response->status()}: {$response->getContent()}");
                    $this->assertBodyHasNothingOf($b, $response, "{$key} with {$label}");
                    $hijacked[$key][] = $label;
                }

                // Control: the same body with A's own ids is accepted, so the refusals are about B's ids.
                $control = $this->json($method, $uri, $base, $a->bearer());
                $this->assertTrue($control->isSuccessful(), "{$key} control with A's own ids answered {$control->status()}: {$control->getContent()}");
            }
        }

        $this->assertArrayHasKey('POST api/v1/invitations', $hijacked);
        $this->assertArrayHasKey('POST api/v1/users/{user}/assignments', $hijacked);
        $this->assertArrayHasKey('POST api/v1/tax-categories', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/tax-categories/{tax_category}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/parties', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/parties/{party}', $hijacked);
        $this->assertArrayHasKey('PUT api/v1/master-data/settings', $hijacked);
        $this->assertNoRowOf($a, 'references', $b);
        $this->assertSame($before, $this->snapshot($b->tenantId), "tenant B's rows changed after tenant A sent B's ids in request bodies");
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
     * GlobalTables::TABLES). Authenticated code reaches them only
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
        "))->pluck('table_name')->diff(GlobalTables::TABLES)->values()->all();

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
            if (isset(self::GLOBAL_PARAMETERS[$name])) {
                $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], self::GLOBAL_PARAMETERS[$name], $uri);

                continue;
            }

            $this->assertArrayHasKey($name, self::PARAMETERS, sprintf(
                'Route %s has the parameter {%s}, which the isolation suite cannot fill: add it to %s::PARAMETERS and give both tenants a row of that type in tests/Support/TwoTenants.php.',
                $route->uri(), $name, self::class,
            ));
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $tenant->id(self::PARAMETERS[$name]), $uri);
        }

        return $uri;
    }

    private function hasOnlyGlobalParameters(RoutingRoute $route): bool
    {
        return array_diff($route->parameterNames(), array_keys(self::GLOBAL_PARAMETERS)) === [];
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

            foreach (GlobalTables::TABLES as $table) {
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

            foreach (GlobalTables::TABLES as $table) {
                $hashes[$table] = DB::selectOne("select md5(coalesce(string_agg(t::text, '|' order by t::text), '')) as h from \"{$table}\" t where tenant_id = ?", [$tenantId])->h;
            }

            return $hashes;
        });
    }

    /** The Form Request class the route's action takes, if any. */
    private function formRequestOf(RoutingRoute $route): ?string
    {
        $uses = $route->getAction('uses');

        if (! is_string($uses)) {
            return null;
        }

        [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * The keys of the route's Form Request rules(), built as for a real
     * request by A's Owner on A's own resources (rules may read the route).
     *
     * @return list<string>
     */
    private function ruleKeys(RoutingRoute $route, string $method, TenantFixture $a): array
    {
        $class = $this->formRequestOf($route);

        if ($class === null) {
            return [];
        }

        return $this->asTenant($a->tenantId, function () use ($class, $route, $method, $a) {
            $owner = User::findOrFail($a->id('user'));
            $request = $class::create($this->uriWith($route, $a), $method);
            $request->setContainer(app())->setUserResolver(fn () => $owner);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            app('router')->substituteBindings($route);
            app('router')->substituteImplicitBindings($route);

            return array_keys(app()->call([$request, 'rules']));
        });
    }

    /**
     * Rule keys naming a row by id (`role_id`, `assignments.*.scope_id`);
     * fails for an `_id` field the suite cannot fill.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function idFields(array $keys): array
    {
        $fields = [];

        foreach ($keys as $key) {
            $leaf = Str::afterLast($key, '.');

            if (! str_ends_with($leaf, '_id') || isset(self::NOT_REFERENCES[$leaf])) {
                continue;
            }

            $this->assertArrayHasKey($leaf, self::REFERENCE_FIELDS, sprintf(
                'The body field [%s] looks like an id the isolation suite cannot fill: add it to %s::REFERENCE_FIELDS (or NOT_REFERENCES with a reason).',
                $key, self::class,
            ));
            $fields[] = $key;
        }

        return $fields;
    }

    /**
     * $base with one id field at a time set to B's id (scope_id once per
     * scope type), then all of them at once.
     *
     * @param  list<string>  $fields
     * @return array<string, array>
     */
    private function hijackVariants(array $base, array $fields, TenantFixture $b): array
    {
        $variants = [];
        $all = $base;

        foreach ($fields as $field) {
            $path = str_replace('*', '0', $field);
            $leaf = Str::afterLast($field, '.');

            if ($leaf === 'scope_id') {
                $typePath = Str::beforeLast($path, 'scope_id').'scope_type';

                foreach (self::SCOPE_IDS as $type => $idType) {
                    $body = $base;
                    data_set($body, $typePath, $type);
                    data_set($body, $path, $b->id($idType));
                    $variants["{$field} = {$type} {$b->id($idType)}"] = $body;
                }

                data_set($all, $path, $b->id(self::SCOPE_IDS[data_get($base, $typePath)]));

                continue;
            }

            $body = $base;
            data_set($body, $path, $b->id(self::REFERENCE_FIELDS[$leaf]));
            $variants["{$field} = {$b->id(self::REFERENCE_FIELDS[$leaf])}"] = $body;
            data_set($all, $path, $b->id(self::REFERENCE_FIELDS[$leaf]));
        }

        $variants['every id field'] = $all;

        return $variants;
    }

    /** A valid body for "METHOD uri" naming $tenant's own rows; null when the suite has none. */
    private function bodyFor(string $key, TenantFixture $tenant): ?array
    {
        $assignment = ['role_id' => $tenant->id('role'), 'scope_type' => 'location', 'scope_id' => $tenant->id('location')];

        return match ($key) {
            'POST api/v1/invitations' => ['name' => 'Invitee', 'email' => 'invitee-hijack@example.com', 'assignments' => [$assignment]],
            'POST api/v1/users/{user}/assignments' => $assignment,
            // MD-03: a shared category (items are shared, TEN-08) mapping the company's tax code.
            'POST api/v1/tax-categories' => [
                'name' => 'Hijack check',
                'codes' => [['company_id' => $tenant->id('company'), 'tax_code_id' => $tenant->id('tax_code')]],
            ],
            'PATCH api/v1/tax-categories/{tax_category}' => [
                'codes' => [['company_id' => $tenant->id('company'), 'tax_code_id' => $tenant->id('tax_code')]],
            ],
            // MD-01, TEN-08: suppliers are kept per company in TwoTenants.
            'POST api/v1/parties' => [
                'kind' => 'organisation', 'name' => 'Hijack supplier', 'roles' => ['supplier'],
                'company_id' => $tenant->id('company'), 'price_list_id' => $tenant->id('price_list'),
            ],
            'PATCH api/v1/parties/{party}' => ['company_id' => $tenant->id('company'), 'price_list_id' => $tenant->id('price_list')],
            // TEN-08: customers move to per company, every shared one to A's company.
            'PUT api/v1/master-data/settings' => [
                'data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $tenant->id('company'),
            ],
            default => null,
        };
    }

    /**
     * Fails when any row of $holder (read in its context) holds one of
     * $other's ids in a uuid column, or in a json/jsonb column's text.
     */
    private function assertNoRowOf(TenantFixture $holder, string $verb, TenantFixture $other): void
    {
        $ids = array_values(array_filter($this->identifiersOf($other), fn ($v) => Str::isUuid($v)));
        $array = '{'.implode(',', $ids).'}';
        $patterns = '{'.implode(',', array_map(fn ($id) => "%{$id}%", $ids)).'}';
        $scanned = 0;

        $this->asTenant($holder->tenantId, function () use ($array, $patterns, $verb, &$scanned) {
            foreach ($this->tenantTables() as $table) {
                $columns = DB::select("
                    select column_name, data_type from information_schema.columns
                    where table_schema = 'public' and table_name = ? and data_type in ('uuid', 'json', 'jsonb')
                ", [$table]);

                foreach ($columns as $column) {
                    $sql = $column->data_type === 'uuid'
                        ? "select count(*) from \"{$table}\" where \"{$column->column_name}\" = any(?::uuid[])"
                        : "select count(*) from \"{$table}\" where \"{$column->column_name}\"::text like any(?::text[])";
                    $found = $this->rows($sql, [$column->data_type === 'uuid' ? $array : $patterns]);
                    $this->assertSame(0, $found, "{$table}.{$column->column_name}: {$found} row(s) of tenant A {$verb} ids of tenant B");
                    $scanned++;
                }
            }
        });

        $this->assertGreaterThan(20, $scanned);
    }

    /**
     * Query parameters a GET route reads: its Form Request's rule keys and
     * literal query()/input()/... reads in that request class (and its
     * parents) and in the controller method.
     *
     * @return list<string>
     */
    private function queryParametersRead(RoutingRoute $route): array
    {
        $sources = [];
        $uses = $route->getAction('uses');

        if (is_string($uses)) {
            [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];
            $reflection = new ReflectionMethod($class, $method);
            $lines = file($reflection->getFileName());
            $sources[] = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        }

        $parameters = [];
        $request = $this->formRequestOf($route);

        if ($request !== null) {
            $parameters = $this->ruleKeys($route, 'GET', $this->tenants->a);

            for ($class = new ReflectionClass($request); $class && $class->getName() !== FormRequest::class; $class = $class->getParentClass()) {
                $sources[] = file_get_contents($class->getFileName());
            }
        }

        foreach ($sources as $source) {
            preg_match_all('/->(?:query|input|get|string|integer|boolean|has|filled|validated|date|enum|collect)\(\s*[\'"]([A-Za-z0-9_.\-\[\]]+)[\'"]/', $source, $matches);
            array_push($parameters, ...$matches[1]);
        }

        return array_values(array_unique(array_map(fn ($p) => Str::before($p, '.'), $parameters)));
    }

    /** @param list<string> $items */
    private function list(array $items): string
    {
        return implode(', ', $items);
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
