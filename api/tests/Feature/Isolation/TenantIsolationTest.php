<?php

namespace Tests\Feature\Isolation;

use App\Core\Exports\ListExport;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\MasterData\History\HistoryTypes;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\RegistersTillModule;
use Tests\Support\Configuration\TestLayoutKind;
use Tests\Support\GlobalTables;
use Tests\Support\TenantFixture;
use Tests\Support\TwoTenants;
use Tests\Support\Workflow\TestRequestType;
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
    use RefreshTenantDatabase, RegistersTillModule;

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
        'GET api/v1/media/{path}' => 'temporary signed URL for one file and one user; the controller enters the tenant the path names and checks that user may view the item (MD-02)',
        'GET api/v1/approval-files/{path}' => 'temporary signed URL for one file and one user; the controller enters the tenant the path names and checks that user may still see the approval (APR-03)',
        'GET api/v1/approvals/email/{token}' => 'the 48-character single-use approval token is the credential; answers only what confirming would do (APR-08)',
        'POST api/v1/approvals/email/{token}' => 'the 48-character single-use approval token is the credential (APR-08)',
        'POST api/v1/payments/callbacks/{token}/{kind}' => 'a payment provider\'s callback: the 48-character callback token names one payment method (its tenant found by a security-definer function), the caller must be the provider\'s address, and it answers only "Accepted"',
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
        'item' => 'item',
        'item_category' => 'item_category',
        'uom' => 'uom',
        'item_image' => 'item_image',
        'item_price' => 'item_price', // MD-03 follow-up: item-prices/{item_price}/archive|restore
        'payment_method' => 'payment_method',
        'department' => 'department',
        'cost_centre' => 'cost_centre',
        'project' => 'project',
        'workflow' => 'workflow',
        'workflow_version' => 'workflow_version',
        'document' => 'document', // WF-10: document-workflows/{document_type}/{document}, the test type's document
        'notification' => 'notification', // NOT-01: POST notifications/{notification}/read|archive
        'automation_rule' => 'automation_rule', // AUTO-01..AUTO-04: automation-rules/{automation_rule}[/enable|disable|archive|test]
        'automation_run' => 'automation_run', // AUTO-05: automation-runs/{automation_run}
        'approval' => 'approval', // APR-04: approvals/{approval}, a request waiting for the manager
        'credit_limit_change' => 'credit_limit_change', // WF-01: credit-limit-changes/{credit_limit_change}, a pending request
        'delegation' => 'delegation', // APR-06: me/delegations/{delegation}/revoke, the manager's delegation (A's owner gets 404 on B's)
        'payment_intent' => 'payment_intent', // payments/intents/{payment_intent} (device), a manual payment at the till's location
        'payment_receipt' => 'payment_receipt', // payment-receipts/{payment_receipt}/match, money received that matched nothing
        'fiscal_submission' => 'fiscal_submission', // fiscal-submissions/{fiscal_submission}[/retry], an accepted sale
        'pos_sale' => 'pos_sale', // POS-12: pos/sales/{pos_sale}, a partly refunded sale
        'pos_shift' => 'pos_shift', // POS-12: pos/shifts/{pos_shift}, the till's open shift
        'pos_void' => 'pos_void', // H2: pos/voids/{pos_void}/approve|reject, a held void
        'pos_refund' => 'pos_refund', // H2: pos/refunds/{pos_refund}/approve|reject, a held refund
        'pos_cash_movement' => 'pos_cash_movement', // H2: pos/cash-movements/{pos_cash_movement}/approve|reject
        'config_document' => 'config_document', // LAY-06: config/{kind}/{config_document}[/draft|publish|rollback|copy|discard-draft]
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
        'document_type' => TestRequestType::KEY, // WF-01: a registered document type key; the document is B's
        'event_type' => 'core.notification.test', // GET notification-templates/{event_type}: a registered event type key (NOT-03); the texts shown are the caller's tenant's
        'kind' => TestLayoutKind::KEY, // LAY-06: config/{kind}: a registered configuration kind; the documents shown are the caller's tenant's
    ];

    /**
     * Routes whose parameters are all global but that write rows of the
     * caller's own tenant (never global data), with why. The body check
     * (test_ids_of_tenant_b_in_request_bodies_are_refused_and_never_stored)
     * sends them B's ids.
     */
    public const TENANT_WRITES_UNDER_GLOBAL_PARAMETERS = [
        'POST api/v1/config/{kind}' => 'LAY-06: saves the draft of the caller\'s own configuration document for a key and scope; the kind is a registered key',
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
        'party_id' => 'party', // WF-01: a credit limit change's party
        'assign_to_company_id' => 'company',
        'category_id' => 'item_category',
        'parent_id' => 'item_category_parent',
        'base_uom_id' => 'uom',
        'uom_id' => 'uom_box', // an item's other unit or a barcode's unit; base_uom_id is EA
        'tax_category_id' => 'tax_category',
        'item_id' => 'item', // MD-03 follow-up: the item a price is for
        'owner_user_id' => 'user', // MD-05: a dimension's owner (APR-02)
        'document_id' => 'document', // AUTO-04: test a rule against a real document (the test type's)
        'rule_id' => 'automation_rule', // AUTO-04: test an edited rule with its stored webhook addresses
        'from_user_id' => 'user', // APR-06: reassign from a pending approver
        'to_user_id' => 'user', // APR-06: reassign to, or delegate to, a user
        'manager_user_id' => 'user', // AUTH-08: the manager authorising an override (the owner)
        'cashier_user_id' => 'manager', // AUTH-08: the cashier the override is for
        'payment_method_id' => 'payment_method', // payments: the method a till asks money through
        'payment_intent_id' => 'payment_intent', // payments: the payment a received amount is matched to
        // POS-09: ids a till uploads (the device's own shift, sale and line; users; master data).
        'shift_id' => 'pos_shift',
        'sale_id' => 'pos_sale',
        'sale_line_id' => 'pos_sale_line',
        'cashier_id' => 'user',
        'opened_by_id' => 'user',
        'closed_by_id' => 'user',
        'voided_by_id' => 'user',
        'number_range_id' => 'pos_number_range', // NUM-02, M1: the range the till numbered from
        'customer_id' => 'customer',
        'item_id' => 'item',
        'scope_id' => null,
    ];

    /**
     * REFERENCE_FIELDS overrides for routes where a field names another kind
     * of row ("METHOD uri" => field => which of B's ids).
     */
    public const ROUTE_REFERENCE_FIELDS = [
        'POST api/v1/companies/{company}/departments' => ['parent_id' => 'department_parent'],
        'PATCH api/v1/departments/{department}' => ['parent_id' => 'department_parent'],
        'POST api/v1/companies/{company}/cost-centres' => ['parent_id' => 'cost_centre_parent'],
        'PATCH api/v1/cost-centres/{cost_centre}' => ['parent_id' => 'cost_centre_parent'],
        'POST api/v1/companies/{company}/projects' => ['parent_id' => 'project_parent'],
        'PATCH api/v1/projects/{project}' => ['parent_id' => 'project_parent'],
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
        'item' => 'item',
        'item_category' => 'item_category',
        'uom' => 'uom',
        'payment_method' => 'payment_method',
        'department' => 'department',
        'cost_centre' => 'cost_centre',
        'project' => 'project',
        'company' => 'company',
        'branch' => 'branch',
        'location' => 'location',
        'user' => 'user',
        'role' => 'role',
    ];

    /** scope_type => which of B's ids goes in scope_id. */
    public const SCOPE_IDS = ['tenant' => 'tenant', 'company' => 'company', 'branch' => 'branch', 'location' => 'location', 'scope_id' => 'company'];

    /** Fields ending in `_id` that are not references to rows, with why. */
    public const NOT_REFERENCES = [
        'tax_id' => "a company's tax registration number, free text",
        'session_id' => 'AUTH-07: a till sign-in session the device names itself (actor_proof); looked up only for that device under its tenant, never a reference to another row',
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
        // MD-02: both tenants have a stock item "Item A|B" with this barcode.
        ['search' => 'Item', 'type' => 'stock', 'barcode' => '6161000000001'],
        // EXP-01: list exports (items and parties); the sort and columns both lists have.
        ['format' => 'csv', 'status' => 'all', 'sort' => '-created_at', 'columns' => ['name']],
        // NOT-06: the delivery log's channel filter (both tenants sent email).
        ['channel' => 'email', 'status' => 'all'],
        // AUTO-05: the run log's outcome filter (both tenants have a run that succeeded).
        ['outcome' => 'succeeded'],
        // MD-03 follow-up: a price list's prices in force (both tenants priced their item).
        ['state' => 'current'],

        // APR-04: the approvals inbox's oversight view and overdue filter.
        ['view' => 'all', 'status' => 'all', 'overdue' => '0'],
        // NFR-04: a device pull of some entities from the start, one row per page.
        ['entities' => ['items', 'customers', 'staff', 'exchange_rates'], 'cursors' => ['items' => '', 'customers' => ''], 'limit' => 1],
        // M3, H2: review filters (sales and the held list).
        ['flagged' => '1', 'flag' => 'actor_unverified', 'reviewed' => '0', 'kind' => 'refund'],
        // TEN-07: the consolidated sales of a year in a reporting currency (both tenants sold in KES).
        ['from' => '2026-01-01', 'to' => '2026-12-31', 'currency' => 'USD'],
        // LAY-06: a configuration key (both tenants have a layout under the default key).
        ['key' => 'default'],
        // LAY-06: one scope of a key (scope_id is sent with B's and A's ids through LIST_ID_QUERIES).
        ['key' => 'default', 'scope_type' => 'tenant'],
    ];

    /**
     * Query parameters that take a row id (`?category=` on items, MD-02;
     * `?company=` on tax categories, MD-03) => which id. The list check
     * sends B's id, and A's as a control (idQueries()).
     */
    public const LIST_ID_QUERIES = ['category' => 'item_category', 'company' => 'company', 'party' => 'customer', 'rule' => 'automation_rule', 'branch' => 'branch', 'location' => 'location'];

    /** Query parameters LIST_QUERIES and LIST_ID_QUERIES cover; `page` only pages through the same rows. */
    public const LIST_QUERY_PARAMETERS = ['status', 'per_page', 'page', 'format', 'pair', 'from', 'to', 'kind', 'search', 'role', 'tag', 'type', 'barcode', 'category', 'company', 'sort', 'columns', 'channel', 'view', 'overdue', 'party', 'outcome', 'rule', 'state', 'entities', 'cursors', 'limit', 'branch', 'location', 'flagged', 'flag', 'reviewed', 'currency', 'key', 'scope_type', 'scope_id'];

    private TwoTenants $tenants;

    /** @var array<string, list<string>> */
    private array $identifiers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AUTH-06..AUTH-08: till permissions for the staff, PIN and override routes.
        $this->registerTillModule();
        $this->tenants = TwoTenants::build($this);
        app(TenantContext::class)->set(null);
        // EXP-01: the suite exports every list many times a minute; the
        // limit itself is tested in ListSortAndExportTest.
        RateLimiter::for(ListExport::EXPORT_LIMITER, fn () => Limit::none());
        // NFR-04: the suite calls the device routes far more than a till would.
        RateLimiter::for('device-sync', fn () => Limit::none());
        RateLimiter::for('device-secret', fn () => Limit::none());
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
                if (array_diff(array_map(fn ($m) => "{$m} {$route->uri()}", $this->methods($route)), array_keys(self::TENANT_WRITES_UNDER_GLOBAL_PARAMETERS)) === []) {
                    continue;
                }

                $this->assertSame(['GET'], $this->methods($route), "{$route->uri()} changes global data through the tenant API");

                foreach (self::LIST_QUERIES as $query) {
                    $uri = $this->uriWith($route, $a).($query === [] ? '' : '?'.http_build_query($query));
                    $response = $this->json('GET', $uri, [], $a->bearer());
                    $query === [] ? $response->assertOk() : $this->assertContains($response->getStatusCode(), [200, 422], "GET {$uri} answered {$response->getStatusCode()}");
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
                    $response = $this->json($method, $uri, $method === 'GET' ? [] : $this->hijackBody($b), $this->bearerFor($route, $a));
                    $called++;

                    // 404 exactly: A's Owner holds every permission, so a 403 here would hide whether the id was resolved.
                    $this->assertSame(404, $response->getStatusCode(), "{$method} {$uri} with tenant B's ids answered {$response->getStatusCode()} to tenant A: {$response->getContent()}");
                    $this->assertBodyHasNothingOf($b, $response, "{$method} {$uri}");

                    // Control: the same GET with A's own ids works (a route may refuse
                    // another list's filter, but never as not found), so the 404 above
                    // is isolation, not a bad URL.
                    if ($method === 'GET') {
                        $control = $this->json('GET', $this->uriWith($route, $a).$suffix, [], $this->bearerFor($route, $a));
                        $query === [] ? $control->assertOk() : $this->assertContains($control->getStatusCode(), [200, 422], "GET {$this->uriWith($route, $a)}{$suffix} answered {$control->getStatusCode()}");
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

        // Control: the item search and barcode lookup really select A's item.
        $items = $this->json('GET', '/api/v1/items?'.http_build_query(self::LIST_QUERIES[5]), [], $a->bearer())->assertOk();
        $this->assertSame([$a->id('item')], array_column($items->json('data'), 'id'));

        // `?category=` takes an id: B's category is refused, A's selects A's item (and its subcategories').
        $refused = $this->json('GET', "/api/v1/items?category={$b->id('item_category_parent')}", [], $a->bearer());
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertBodyHasNothingOf($b, $refused, 'GET items?category= with B\'s category');
        $own = $this->json('GET', "/api/v1/items?category={$a->id('item_category_parent')}", [], $a->bearer())->assertOk();
        $this->assertSame([$a->id('item')], array_column($own->json('data'), 'id'));
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
                foreach ([...self::LIST_QUERIES, ...$this->idQueries($route, $a, $b)] as $query) {
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
        // Control: filtering by A's own category finds A's item, by B's finds nothing (MD-02).
        $this->assertNotEmpty($this->get('/api/v1/items?category='.$a->id('item_category'), $a->bearer('owner'))->assertOk()->json('data'));
        $this->get('/api/v1/items?category='.$b->id('item_category'), $a->bearer('owner'))->assertUnprocessable();
        // Control: tax categories of A's own company are listed, B's company is refused (MD-03).
        $this->assertNotEmpty($this->get('/api/v1/tax-categories?status=all&company='.$a->id('company'), $a->bearer('owner'))->assertOk()->json('data'));
        $this->get('/api/v1/tax-categories?company='.$b->id('company'), $a->bearer('owner'))->assertUnprocessable();
    }

    /** TEN-07: the consolidated sales of today hold A's sale, in KES and USD, and nothing of B. */
    public function test_pos_insights_of_tenant_a_show_nothing_of_tenant_b(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $today = now('Africa/Nairobi')->toDateString();

        foreach (['owner', 'manager'] as $who) {
            $response = $this->getJson("/api/v1/pos/insights?from={$today}&to={$today}&currency=USD", $a->bearer($who))->assertOk();
            $this->assertBodyHasNothingOf($b, $response, "GET pos/insights as A's {$who}");
        }

        // Control: A's owner sees A's own sales.
        $own = $this->getJson("/api/v1/pos/insights?from={$today}&to={$today}", $a->bearer())->assertOk();
        $this->assertGreaterThan(0, $own->json('data.sales_count'));
        $this->assertSame([$a->id('company')], array_column(array_column($own->json('data.companies'), 'company'), 'id'));
        $this->get("/api/v1/pos/insights?from={$today}&to={$today}&company={$b->id('company')}", ['Accept' => 'application/json', ...$a->bearer()])->assertUnprocessable();
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
            $this->assertSame(404, $refused->getStatusCode(), "GET history/{$type} with tenant B's record answered {$refused->getStatusCode()}");
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

                foreach ($this->hijackVariants($base, $idFields, $b, self::ROUTE_REFERENCE_FIELDS[$key] ?? []) as $label => $body) {
                    $response = $this->json($method, $uri, $body, $this->bearerFor($route, $a));
                    $this->assertContains($response->getStatusCode(), [404, 422], "{$key} with {$label} of tenant B answered {$response->getStatusCode()}: {$response->getContent()}");
                    $this->assertBodyHasNothingOf($b, $response, "{$key} with {$label}");
                    $hijacked[$key][] = $label;
                }

                // Control: the same body with A's own ids is accepted, so the refusals are about B's ids.
                $control = $this->json($method, $uri, $base, $this->bearerFor($route, $a));
                $this->assertTrue($control->isSuccessful(), "{$key} control with A's own ids answered {$control->getStatusCode()}: {$control->getContent()}");
            }
        }

        $this->assertArrayHasKey('POST api/v1/invitations', $hijacked);
        $this->assertArrayHasKey('POST api/v1/users/{user}/assignments', $hijacked);
        $this->assertArrayHasKey('POST api/v1/tax-categories', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/tax-categories/{tax_category}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/parties', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/parties/{party}', $hijacked);
        $this->assertArrayHasKey('PUT api/v1/master-data/settings', $hijacked);
        $this->assertArrayHasKey('POST api/v1/items', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/items/{item}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/price-lists/{price_list}/prices', $hijacked);
        $this->assertArrayHasKey('POST api/v1/price-lists/{price_list}/prices/bulk', $hijacked);
        $this->assertArrayHasKey('POST api/v1/item-categories', $hijacked);
        $this->assertArrayHasKey('PATCH api/v1/item-categories/{item_category}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/workflows', $hijacked);
        $this->assertArrayHasKey('POST api/v1/workflows/{workflow}/copy', $hijacked);
        $this->assertArrayHasKey('POST api/v1/automation-rules', $hijacked);
        $this->assertArrayHasKey('POST api/v1/automation-rules/{automation_rule}/test', $hijacked);
        $this->assertArrayHasKey('POST api/v1/automation-templates/use', $hijacked);
        $this->assertArrayHasKey('POST api/v1/approvals/{approval}/reassign', $hijacked);
        $this->assertArrayHasKey('POST api/v1/me/delegations', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/pin/verify', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/pin/attempts', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/override', $hijacked);
        $this->assertArrayHasKey('POST api/v1/pos/pin/change', $hijacked);
        $this->assertArrayHasKey('POST api/v1/payments/intents', $hijacked);
        $this->assertArrayHasKey('POST api/v1/payment-receipts/{payment_receipt}/match', $hijacked);
        $this->assertArrayHasKey('PUT api/v1/numbering/formats', $hijacked);
        $this->assertArrayHasKey('POST api/v1/config/{kind}', $hijacked);
        $this->assertArrayHasKey('POST api/v1/config/{kind}/{config_document}/copy', $hijacked);
        foreach (['sales', 'shifts', 'cash-movements', 'voids', 'refunds'] as $upload) {
            $this->assertArrayHasKey("POST api/v1/pos/{$upload}", $hijacked);
        }
        foreach (array_keys(self::ROUTE_REFERENCE_FIELDS) as $key) {
            $this->assertArrayHasKey($key, $hijacked);
        }
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

    /**
     * EXP-01: list exports read the same tenant-bound query as the list,
     * inside the tenant's own context while streaming. CSV and the cells
     * of the Excel file are checked as text (an xlsx is a zip, a PDF is
     * compressed: their bytes prove nothing); every format answers.
     */
    public function test_list_exports_of_tenant_a_contain_nothing_of_tenant_b(): void
    {
        $a = $this->tenants->a;
        $b = $this->tenants->b;
        $theirs = ['Customer B', 'Supplier B', 'P00000000B', 'ITEM-B', 'Article B', 'Goods B', '+254700000302',
            'Owner B', 'Manager B', 'Cashier B', 'Clerk B', 'Retail B', 'Unit B', 'Root B', 'Outlet B', 'Branch B',
            'key-b', 'secret-b', 'pass-b', '17437b'];
        // MD-04: payment method settings and credentials never reach an export, A's own included.
        $secrets = ['key-a', 'secret-a', 'pass-a', '17437a'];

        foreach ($this->exportedLists($a) as $list => $ours) {
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                $response = $this->get("/api/v1/{$list}".(str_contains($list, '?') ? '&' : '?')."format={$format}", $a->bearer())->assertOk();
                $body = $response->streamedContent();

                if ($format === 'pdf') {
                    $this->assertStringStartsWith('%PDF-', $body);

                    continue;
                }

                $text = $format === 'csv' ? $body : $this->xlsxText($body);

                foreach ($ours as $value) {
                    $this->assertStringContainsString($value, $text, "control: A's {$list} {$format} export lists {$value}");
                }

                foreach ([...$theirs, ...$this->identifiersOf($b)] as $value) {
                    $this->assertStringNotContainsString($value, $text, "A's {$list} {$format} export contains {$value} of tenant B");
                }

                foreach ($secrets as $value) {
                    $this->assertStringNotContainsString($value, $text, "A's {$list} {$format} export contains the credential or setting {$value}");
                }
            }
        }
    }

    /**
     * Every exported list (EXP-01), with values of tenant A each export
     * must show (the control).
     *
     * @return array<string, list<string>>
     */
    private function exportedLists(TenantFixture $a): array
    {
        return [
            'items?status=all' => ['ITEM-A', 'Article A'],
            'parties?status=all' => ['Customer A', 'Supplier A', '+254700000301'],
            'users' => ['Owner A', 'Manager A', 'Branch Manager at Branch A'],
            'invitations' => ['Cashier A', 'Cashier at Outlet A'],
            'auth/sessions' => [],
            'roles?status=all' => ['Clerk A', 'Branch Manager'],
            "users/{$a->id('manager')}/assignments" => ['Clerk A', 'Outlet A'],
            "companies/{$a->id('company')}/exchange-rates" => ['USD/KES', '129.5', '140'],
            'tenant/currencies' => ['KES', 'USD'],
            "companies/{$a->id('company')}/tax-codes?status=all" => ['VAT_STD', '12.5%'],
            'tax-categories?status=all' => ['Goods A', 'VAT_STD in Company A'],
            "companies/{$a->id('company')}/price-lists?status=all" => ['Retail A'],
            'uoms?status=all' => ['EA', 'BOX'],
            'item-categories?status=all' => ['Goods A', 'Goods A sub'],
            "companies/{$a->id('company')}/payment-methods?status=all" => ['M-Pesa', 'Cash KES'],
            "companies/{$a->id('company')}/departments?status=all" => ['A-1', 'Unit A renamed', 'Root A'],
            "companies/{$a->id('company')}/cost-centres?status=all" => ['A-1', 'Unit A renamed'],
            "companies/{$a->id('company')}/projects?status=all" => ['A-1', 'Unit A renamed'],
            // NOT-01, NOT-06: the owner's inbox and the delivery log.
            'notifications?status=all' => ['Isolation A from Owner A', 'Note: Stock count A'],
            'notification-deliveries' => ['Owner A', 'Manager A', 'manager-a@example.com'],
            // AUTO-01, AUTO-05: the rules and the run log.
            'automation-rules?status=all' => ['Rule A'],
            'automation-runs' => ['Rule A', 'Done'],

            // APR-04: the oversight list of approvals.
            'approvals?view=all&status=all' => ['Approve A', 'Waiting'],

            // Payments and fiscal: the till's payments, money received, the fiscal queue.
            "companies/{$a->id('company')}/payment-intents" => ['QJK3AMANUAL', 'Code entered'],
            "companies/{$a->id('company')}/payment-receipts" => ['QJK3ALOOSE1', 'KES 700.00'],
            "companies/{$a->id('company')}/fiscal-submissions" => ['Sale', 'Accepted'],
            // POS-12: sales and shifts of A's till.
            'pos/sales?status=all' => ['Outlet A', 'Owner A', 'KES 1,125.00'],
            'pos/shifts' => ['Outlet A', 'Owner A', 'KES 5,000.00'],
        ];
    }

    /** Every cell of an xlsx file, as one string. */
    private function xlsxText(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'isolation-xlsx-');
        file_put_contents($path, $content);
        $reader = new XlsxReader;
        $reader->open($path);
        $cells = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->cells as $cell) {
                    $cells[] = (string) $cell->getValue();
                }
            }
        }

        $reader->close();
        unlink($path);

        return implode("\n", $cells);
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
        $this->assertContains($refused->getStatusCode(), [403, 404, 422], 'tenant A confirmed with tenant B\'s two-factor code');
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

    /**
     * LIST_ID_QUERIES the route reads, with B's ids (must show nothing of B
     * beyond the id echoed in its own pagination links) and with A's ids
     * (the control: the filter really runs). Routes that do not read the
     * parameter are not sent it: their pagination links would echo B's id.
     *
     * @return list<array<string, string>>
     */
    private function idQueries(RoutingRoute $route, TenantFixture $a, TenantFixture $b): array
    {
        $queries = [];
        $read = $this->queryParametersRead($route);

        foreach (array_intersect_key(self::LIST_ID_QUERIES, array_flip($read)) as $parameter => $key) {
            $queries[] = [$parameter => $b->id($key)];
            $queries[] = [$parameter => $a->id($key)];
        }

        return $queries;
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

    /** TEN-05: device routes are called with the tenant's paired device, every other route as its Owner. */
    private function bearerFor(RoutingRoute $route, TenantFixture $tenant): array
    {
        return in_array(EnsureDeviceToken::class, $route->gatherMiddleware(), true) ? $tenant->bearer('device') : $tenant->bearer();
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
     * @param  array<string, string>  $references  this route's REFERENCE_FIELDS overrides
     * @return array<string, array>
     */
    private function hijackVariants(array $base, array $fields, TenantFixture $b, array $references = []): array
    {
        $references = [...self::REFERENCE_FIELDS, ...$references];

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
            data_set($body, $path, $b->id($references[$leaf]));
            $variants["{$field} = {$b->id($references[$leaf])}"] = $body;
            data_set($all, $path, $b->id($references[$leaf]));
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
            // MD-02: a shared item in A's category and tax category, with a box and a barcode for it.
            'POST api/v1/items' => [
                'code' => 'HIJACK-ITEM', 'name' => 'Hijack item', 'type' => 'stock', 'base_uom_id' => $tenant->id('uom'),
                'category_id' => $tenant->id('item_category'), 'tax_category_id' => $tenant->id('tax_category'),
                'uoms' => [['uom_id' => $tenant->id('uom_box'), 'factor' => '6']],
                'barcodes' => [['barcode' => 'HIJACK1', 'uom_id' => $tenant->id('uom_box')]],
            ],
            'PATCH api/v1/items/{item}' => [
                'base_uom_id' => $tenant->id('uom'), 'category_id' => $tenant->id('item_category'), 'tax_category_id' => $tenant->id('tax_category'),
                'uoms' => [['uom_id' => $tenant->id('uom_box'), 'factor' => '12']],
                'barcodes' => [['barcode' => '6161000000001'], ['barcode' => '6161000000018', 'uom_id' => $tenant->id('uom_box')]],
            ],
            // MD-03 follow-up: the item's box in the company's default list, one price or a batch.
            'POST api/v1/price-lists/{price_list}/prices' => ['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'amount_minor' => '130000', 'currency' => 'KES'],
            'POST api/v1/price-lists/{price_list}/prices/bulk' => ['prices' => [['item_id' => $tenant->id('item'), 'uom_id' => $tenant->id('uom_box'), 'amount_minor' => '131000', 'currency' => 'KES']]],
            'POST api/v1/item-categories' => ['name' => 'Hijack category', 'parent_id' => $tenant->id('item_category_parent')],
            'PATCH api/v1/item-categories/{item_category}' => ['parent_id' => $tenant->id('item_category_parent')],
            // MD-05: a child of the company's parent row, owned by the Owner.
            'POST api/v1/companies/{company}/departments' => ['code' => 'HIJACK-D', 'name' => 'Hijack', 'parent_id' => $tenant->id('department_parent'), 'owner_user_id' => $tenant->id('user')],
            'PATCH api/v1/departments/{department}' => ['parent_id' => $tenant->id('department_parent'), 'owner_user_id' => $tenant->id('user')],
            'POST api/v1/companies/{company}/cost-centres' => ['code' => 'HIJACK-C', 'name' => 'Hijack', 'parent_id' => $tenant->id('cost_centre_parent'), 'owner_user_id' => $tenant->id('user')],
            'PATCH api/v1/cost-centres/{cost_centre}' => ['parent_id' => $tenant->id('cost_centre_parent'), 'owner_user_id' => $tenant->id('user')],
            'POST api/v1/companies/{company}/projects' => ['code' => 'HIJACK-P', 'name' => 'Hijack', 'parent_id' => $tenant->id('project_parent'), 'owner_user_id' => $tenant->id('user')],
            'PATCH api/v1/projects/{project}' => ['parent_id' => $tenant->id('project_parent'), 'owner_user_id' => $tenant->id('user')],
            // WF-02: a flow for the sign-up company (TwoTenants made the company's), and a copy of the company's there.
            'POST api/v1/workflows' => ['document_type' => TestRequestType::KEY, 'company_id' => $tenant->id('sign_up_company')],
            'POST api/v1/workflows/{workflow}/copy' => ['company_id' => $tenant->id('sign_up_company'), 'from' => 'published'],
            // AUTO-01..AUTO-04, AUTO-07: a rule for A's company, the same rule tested unsaved, a test
            // against A's document, and a template used for A's company.
            'POST api/v1/automation-rules' => [...self::automationRule(), 'company_id' => $tenant->id('company')],
            'PATCH api/v1/automation-rules/{automation_rule}' => ['company_id' => $tenant->id('company')],
            'POST api/v1/automation-rules/test' => [...self::automationRule(), 'company_id' => $tenant->id('company')],
            'POST api/v1/automation-rules/{automation_rule}/test' => ['document_id' => $tenant->id('document')],
            'POST api/v1/automation-templates/use' => [
                'template' => 'core.alert_below_level', 'document_type' => TestRequestType::KEY, 'company_id' => $tenant->id('company'),
                'params' => ['field' => 'total', 'value' => ['amount_minor' => '100', 'currency' => 'KES']],
            ],

            // APR-06: the manager's pending approval goes to the owner; the owner delegates to the manager.
            'POST api/v1/approvals/{approval}/reassign' => ['from_user_id' => $tenant->id('manager'), 'to_user_id' => $tenant->id('user')],
            'POST api/v1/me/delegations' => ['to_user_id' => $tenant->id('manager'), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDay()->toDateString()],
            // WF-01: a credit limit change for the company's supplier.
            'POST api/v1/credit-limit-changes' => [
                'party_id' => $tenant->id('party'), 'company_id' => $tenant->id('company'),
                'requested_limit' => ['amount_minor' => '100000', 'currency' => 'KES'], 'reason' => 'Hijack check',
            ],
            // TEN-08: customers move to per company, every shared one to A's company.
            'PUT api/v1/master-data/settings' => [
                'data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $tenant->id('company'),
            ],
            // NUM-01: a branch's receipt format.
            'PUT api/v1/numbering/formats' => [
                'document_type' => 'pos.receipt', 'company_id' => $tenant->id('company'), 'branch_id' => $tenant->id('branch'),
                'pattern' => 'R-{BRANCH}-{000001}', 'reset' => 'never',
            ],
            // POS-09 (device token): a sale on the till's open shift with a manager's
            // override on its line; a shift opened and closed in one upload; a pay-in
            // with an override; a void of the spare sale; the second unit of the
            // refunded sale given back.
            'POST api/v1/pos/sales' => ['sales' => [[
                ...($sale = TwoTenants::posSale(fn () => (string) Str::uuid7(), $tenant->id('pos_shift'), $tenant->id('user'), 50,
                    str_replace('{000001}', '000050', $tenant->id('pos_receipt_pattern')), $tenant->id('item'), $tenant->id('uom'),
                    $tenant->id('price_list'), $tenant->id('payment_method'), $tenant->id('tax_code'))),
                'customer_id' => $tenant->id('customer'),
                'lines' => [[...$sale['lines'][0], 'override' => ['manager_user_id' => $tenant->id('manager'), 'cashier_user_id' => $tenant->id('user')]]],
            ]]],
            'POST api/v1/pos/shifts' => ['shifts' => [[
                'id' => (string) Str::uuid7(), 'opened_by_id' => $tenant->id('user'), 'opened_at' => now()->subMinutes(30)->toIso8601String(),
                'opening_float' => [['currency' => 'KES', 'amount_minor' => '1000']],
                'closing' => ['closed_by_id' => $tenant->id('user'), 'closed_at' => now()->toIso8601String(), 'counted' => [['currency' => 'KES', 'amount_minor' => '1000']]],
            ]]],
            'POST api/v1/pos/cash-movements' => ['movements' => [[
                'id' => (string) Str::uuid7(), 'shift_id' => $tenant->id('pos_shift'), 'user_id' => $tenant->id('user'), 'kind' => 'pay_out',
                'currency' => 'KES', 'amount_minor' => '500', 'reason' => 'Hijack check', 'occurred_at' => now()->toIso8601String(),
            ]]],
            'POST api/v1/pos/voids' => ['voids' => [[
                'id' => (string) Str::uuid7(), 'sale_id' => $tenant->id('pos_sale_spare'), 'voided_by_id' => $tenant->id('user'),
                'voided_at' => now()->toIso8601String(), 'reason' => 'Hijack check',
            ]]],
            'POST api/v1/pos/refunds' => ['refunds' => [[
                ...TwoTenants::posRefund(fn () => (string) Str::uuid7(), ['id' => $tenant->id('pos_sale'), 'lines' => [['id' => $tenant->id('pos_sale_line')]]],
                    $tenant->id('pos_shift'), $tenant->id('user'), 2, str_replace('{000001}', '000002', $tenant->id('pos_refund_pattern')), $tenant->id('payment_method')),
            ]]],
            // AUTH-06..AUTH-08, from A's device: the owner signs in, a report of no
            // wrong PINs, and the owner approving a void for the manager.
            'POST api/v1/pos/pin/verify' => ['user_id' => $tenant->id('user'), 'pin' => TwoTenants::PIN],
            'POST api/v1/pos/pin/attempts' => ['reports' => [['user_id' => $tenant->id('user'), 'failed_attempts' => 0, 'locked' => false]]],
            'POST api/v1/pos/override' => [
                'manager_user_id' => $tenant->id('user'), 'pin' => TwoTenants::PIN, 'permission' => 'pos.sale.void', 'cashier_user_id' => $tenant->id('manager'),
                'reference' => 'sale-hijack-check',
            ],
            // The owner picks a new PIN at the till (the last device route the suite calls with it).
            'POST api/v1/pos/pin/change' => ['user_id' => $tenant->id('user'), 'pin' => TwoTenants::PIN, 'new_pin' => '739104'],
            // Payments (device token): a manual M-Pesa payment by the owner at the till.
            'POST api/v1/payments/intents' => [
                'payment_method_id' => $tenant->id('payment_method'), 'mode' => 'manual', 'amount_minor' => '10000', 'currency' => 'KES',
                'receipt' => 'QJK3HIJACK'.strtoupper(Str::random(4)), 'reference_type' => 'pos.sale', 'reference' => (string) Str::uuid7(), 'user_id' => $tenant->id('user'),
            ],
            // LAY-06: a layout for the location (saved again on every call: one draft), and a copy of the company's there.
            'POST api/v1/config/{kind}' => ['scope_type' => 'location', 'scope_id' => $tenant->id('location'), 'payload' => ['columns' => [['id' => 'name']]]],
            'POST api/v1/config/{kind}/{config_document}/copy' => ['scope_type' => 'location', 'scope_id' => $tenant->id('location'), 'from' => 'published', 'replace' => true],
            // Payments: the money received matched to the till's manual payment.
            'POST api/v1/payment-receipts/{payment_receipt}/match' => ['payment_intent_id' => $tenant->id('payment_intent')],
            default => null,
        };
    }

    /** A valid automation rule body (on the test request type, notifying the Admin role). */
    private static function automationRule(): array
    {
        return [
            'name' => 'Hijack rule',
            'document_type' => TestRequestType::KEY,
            'trigger' => ['type' => 'record_created'],
            'actions' => [['type' => 'notify', 'to' => ['role:admin'], 'subject' => 'New {note}', 'message' => 'A request was created.']],
        ];
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
