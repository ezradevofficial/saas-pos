<?php

namespace Modules\VanSales\Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Modules\POS\Tests\Concerns\BuildsPos;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Stop;
use Modules\VanSales\Models\Van;

/**
 * A tenant with POS and Van sales active (BuildsPos' Acme in KES, outlets A
 * and B, a till at A that stands for the warehouse): van 1, a `store`
 * location at branch A with its own paired device and receipt range, a rep
 * (Van rep at the van), a supervisor (Route supervisor at branch A), a
 * branch manager and a storekeeper, two customers on a daily route (a shop
 * with a KES 10,000.00 credit limit, a kiosk with none), and a credit
 * payment method. The soap of BuildsPos is the van's stock.
 */
trait BuildsVanSales
{
    use BuildsPos;

    protected Location $vanLocation;

    protected Device $vanDevice;

    protected string $vanToken;

    protected Van $van;

    protected User $rep;

    protected User $supervisor;

    protected User $manager;

    protected User $storekeeper;

    protected Party $shop;

    protected Party $kiosk;

    protected Route $route;

    protected Stop $shopStop;

    protected Stop $kioskStop;

    protected string $vanShift;

    protected function setUpVanSales(bool $inventory = false): void
    {
        $this->setUpPos();

        $this->inTenant(function () use ($inventory) {
            app(ModuleRegistry::class)->activate('van_sales');

            if ($inventory) {
                app(ModuleRegistry::class)->activate('inventory');
            }

            $this->vanLocation = Location::create(['branch_id' => $this->branchA->id, 'name' => 'Van 1', 'type' => 'store']);
            $this->vanLocation->forceFill(['code' => 'V01'])->save();
            [$this->vanDevice, $this->vanToken] = $this->pairedTill($this->vanLocation, 'Van 1 phone');
            $this->methods['credit'] = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'credit', 'name' => 'On account', 'currency' => 'KES', 'active' => true, 'position' => 4]);
        });

        $this->rep = $this->userWith('van_rep', Scope::location($this->vanLocation->id));
        $this->supervisor = $this->userWith('route_supervisor', Scope::branch($this->branchA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));

        $this->inTenant(function () {
            $this->van = Van::create([
                'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->vanLocation->id,
                'device_id' => $this->vanDevice->id, 'name' => 'Van 1', 'rep_id' => $this->rep->id,
            ]);
            $this->shop = Party::create(['kind' => 'organisation', 'name' => 'Corner shop', 'roles' => ['customer'], 'company_id' => $this->acme->id,
                'credit_limit_minor' => '1000000', 'credit_limit_currency' => 'KES']);
            $this->kiosk = Party::create(['kind' => 'person', 'name' => 'Kiosk', 'roles' => ['customer'], 'company_id' => $this->acme->id]);
            $this->route = Route::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'van_id' => $this->van->id, 'name' => 'Daily route', 'visit_days' => [1, 2, 3, 4, 5, 6, 7]]);
            $this->shopStop = Stop::create(['company_id' => $this->acme->id, 'route_id' => $this->route->id, 'customer_id' => $this->shop->id, 'position' => 1]);
            $this->kioskStop = Stop::create(['company_id' => $this->acme->id, 'route_id' => $this->route->id, 'customer_id' => $this->kiosk->id, 'position' => 2]);
        });

        $this->ranges('pos.receipt', null, $this->vanToken)->assertOk();
        $this->vanShift = $this->openShift($this->vanToken, $this->rep->id);
    }

    protected function api(string $method, string $uri, array $body = [], ?User $user = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.$uri, $body, $this->headersFor($user));
    }

    protected function vsSync(array $records, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/van-sales/device/sync', $records, $this->tillHeaders($token ?? $this->vanToken));
    }

    protected function at(int $minutesAgo): string
    {
        return CarbonImmutable::now()->subMinutes($minutesAgo)->toIso8601String();
    }

    /** @return array<string, mixed> a run started by the rep on the daily route */
    protected function runRecord(array $overrides = []): array
    {
        return array_replace(['id' => $this->id(), 'route_id' => $this->route->id, 'user_id' => $this->rep->id, 'started_at' => $this->at(180)], $overrides);
    }

    /** Starts a run through the device sync and returns its id. */
    protected function startRun(array $overrides = []): string
    {
        $record = $this->runRecord($overrides);
        $this->vsSync(['runs' => [$record]])->assertOk()->assertJsonPath('results.runs.0.status', 'stored');

        return $record['id'];
    }

    /** @return array<string, mixed> a visit record */
    protected function visitRecord(string $runId, Party $customer, array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(), 'run_id' => $runId, 'stop_id' => null, 'customer_id' => $customer->id, 'user_id' => $this->rep->id,
            'status' => 'visited', 'no_sale_reason' => null, 'note' => null, 'arrived_at' => $this->at(120), 'left_at' => null, 'updated_at' => $this->at(119),
        ], $overrides);
    }

    /** @return array<string, mixed> a payment body of the sale (method key of $this->methods) */
    protected function pay(string $method, string $amount, string $currency = 'KES'): array
    {
        return [
            'id' => $this->id(), 'payment_method_id' => $this->methods[$method]->id, 'currency' => $currency,
            'amount_minor' => $amount, 'amount_in_sale_minor' => $amount, 'rate' => null, 'status' => 'confirmed',
        ];
    }

    /**
     * A van sale of $qty soaps (KES 562.50 each, tax included) by the rep for
     * $customer, in the run, paid with $payments (cash for the whole by default).
     *
     * @param  list<array<string, mixed>>|null  $payments
     * @return array<string, mixed> the sale body as uploaded
     */
    protected function vanSale(string $runId, int $seq, ?Party $customer = null, ?array $payments = null, string $qty = '2', array $ext = [], array $overrides = []): array
    {
        $gross = (int) round(56250 * (float) $qty);
        $tax = (int) round($gross * 0.125 / 1.125);
        // The device's credit decision names the limit it used (the customer's, unless a test says otherwise).
        $credits = array_filter($payments ?? [], fn (array $p) => $p['payment_method_id'] === $this->methods['credit']->id);
        $ext = $credits === [] ? $ext : array_replace(['credit' => ['limit_minor' => '1000000']], $ext);
        $body = $this->saleBody($this->vanShift, $seq, [
            'cashier_id' => $this->rep->id,
            'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice),
            'receipt_number' => sprintf('R-V01-%06d', $seq),
            'customer_id' => $customer?->id,
            'lines' => [$this->line(['qty' => $qty, 'tax_minor' => (string) $tax, 'total_minor' => (string) $gross])],
            'extensions' => ['van_sales' => array_replace(['run_id' => $runId], $ext)],
            ...$overrides,
        ]);
        $body['payments'] = $payments ?? [$this->pay('cash_kes', (string) $gross)];

        return $body;
    }

    /** Uploads sales on the van's device and returns the response. */
    protected function sellOnVan(array ...$sales): TestResponse
    {
        return $this->upload($sales, $this->vanToken);
    }

    /** A confirmed load of $qty soaps onto the van, without Inventory: made, released and confirmed in a run. */
    protected function loadVan(string $qty = '10', ?string $runId = null): string
    {
        $load = $this->api('POST', 'van-sales/loads', [
            'van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'run_id' => $runId,
            'lines' => [['item_id' => $this->soap->id, 'qty' => $qty]],
        ], $this->supervisor)->assertCreated()->json('data');

        $this->postJson("/api/v1/van-sales/device/loads/{$load['id']}/confirm", [
            'user_id' => $this->rep->id, 'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice), 'lines' => [['id' => $load['lines'][0]['id'], 'qty_received' => $qty]],
        ], $this->tillHeaders($this->vanToken))->assertOk();

        return $load['id'];
    }

    /** @return array<string, mixed> an end-of-day count record */
    protected function countRecord(string $runId, array $lines = [], array $tenders = [], array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(), 'run_id' => $runId, 'user_id' => $this->rep->id, 'submitted_at' => $this->at(1), 'note' => null,
            'lines' => $lines, 'tenders' => $tenders,
        ], $overrides);
    }

    /** Another tenant with the Van sales module active, so only isolation (not the module gate) refuses it. */
    protected function otherVanTenant(): array
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('van_sales'));

        return $other;
    }
}
