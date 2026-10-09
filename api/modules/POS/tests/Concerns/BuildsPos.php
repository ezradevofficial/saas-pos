<?php

namespace Modules\POS\Tests\Concerns;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Pin\ActorProofVerifier;
use App\Core\Identity\Pin\TillSignIn;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\POS\Sync\OverrideVerifier;
use Modules\POS\Tests\Support\FakeOverrides;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with the POS module active (BuildsOrganisation's Acme in KES,
 * USD active too), location A coded `L01` with a paired till, an item in a
 * tax category whose code has a test rate of 12.5 % (a synthetic figure,
 * never a real rate), a tax-inclusive KES price list, cash KES and USD and
 * an M-Pesa method. Each till gets a device secret, so tests sign real
 * AUTH-07 sign-in attestations (actorProof()).
 */
trait BuildsPos
{
    use BuildsOrganisation;

    protected Device $till;

    protected string $tillToken;

    /** @var array<string, array{kid: string, secret: string}> each paired till's device secret, by device id */
    protected array $tillSecrets = [];

    protected Uom $each;

    protected TaxCode $vat;

    protected TaxCategory $goods;

    protected Item $soap;

    protected PriceList $retail;

    /** @var array<string, PaymentMethod> cash_kes, cash_usd, mpesa */
    protected array $methods = [];

    protected function setUpPos(): void
    {
        $this->setUpOrganisation();
        // AUTH-08: override proofs the tests control (FakeOverrides); AUTH-07 attestations are real.
        app()->instance(OverrideVerifier::class, new FakeOverrides);

        $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(ModuleRegistry::class)->activate('pos');

            $this->locationA->forceFill(['code' => 'L01'])->save();
            $this->locationB->forceFill(['code' => 'L02'])->save();
            [$this->till, $this->tillToken] = $this->pairedTill($this->locationA, 'Till 1');

            $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $this->vat = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_T', 'name' => 'VAT test', 'kind' => 'vat']);
            TaxRate::create(['tax_code_id' => $this->vat->id, 'rate' => '12.5', 'effective_from' => '2026-01-01']);
            $this->goods = TaxCategory::create(['name' => 'Goods']);
            TaxCategoryCode::create(['tax_category_id' => $this->goods->id, 'company_id' => $this->acme->id, 'tax_code_id' => $this->vat->id]);
            $this->soap = Item::create(['code' => 'SOAP', 'name' => 'Soap', 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);
            $this->retail = PriceList::create(['company_id' => $this->acme->id, 'name' => 'Retail', 'currency' => 'KES', 'tax_inclusive' => true, 'is_default' => true]);
            // M4: the server's price for the soap, as the till sells it.
            ItemPrice::create(['price_list_id' => $this->retail->id, 'item_id' => $this->soap->id, 'uom_id' => $this->each->id, 'amount_minor' => '56250', 'currency' => 'KES', 'effective_from' => '2026-01-01']);

            $this->methods = [
                'cash_kes' => PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'cash', 'name' => 'Cash KES', 'currency' => 'KES', 'active' => true, 'position' => 1]),
                'cash_usd' => PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'cash', 'name' => 'Cash USD', 'currency' => 'USD', 'active' => true, 'position' => 2]),
                'mpesa' => PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'mobile_money', 'name' => 'M-Pesa', 'currency' => 'KES', 'provider' => 'mpesa_ke', 'active' => true, 'position' => 3]),
            ];
        });
    }

    /** @return array{0: Device, 1: string} a device paired at $location and its bearer token */
    protected function pairedTill(Location $location, string $name): array
    {
        return $this->inTenant(function () use ($location, $name) {
            $device = Device::create(['location_id' => $location->id, 'name' => $name]);
            $device->forceFill(['status' => Device::STATUS_ACTIVE, 'paired_at' => now()])->save();
            $this->tillSecrets[$device->id] = app(DeviceSecrets::class)->issueFirst($device);

            return [$device, $device->issueToken($name, null, null)->plainTextToken];
        });
    }

    protected function tillHeaders(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->tillToken), 'Accept' => 'application/json'];
    }

    protected function id(): string
    {
        return (string) Str::uuid7();
    }

    protected function ranges(string $type = 'pos.receipt', ?int $next = null, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/pos/number-ranges', ['document_type' => $type, 'next' => $next], $this->tillHeaders($token));
    }

    protected function shiftBody(array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(),
            'opened_by_id' => $this->owner->id,
            'opened_at' => now()->subHour()->toIso8601String(),
            'opening_float' => [['currency' => 'KES', 'amount_minor' => '500000']],
            'closing' => null,
        ], $overrides);
    }

    /** An open shift on the till; returns its id. */
    protected function openShift(?string $token = null, ?string $openedBy = null): string
    {
        $body = $this->shiftBody(['opened_by_id' => $openedBy ?? $this->owner->id]);
        $this->postJson('/api/v1/pos/shifts', ['shifts' => [$body]], $this->tillHeaders($token))->assertOk()->assertJsonPath('results.0.status', 'stored');

        return $body['id'];
    }

    /** A soap line: 2 × KES 562.50, tax included at the test rate (KES 125.00 of KES 1,125.00). */
    protected function line(array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(),
            'item_id' => $this->soap->id,
            'uom_id' => $this->each->id,
            'qty' => '2',
            'unit_price_minor' => '56250',
            'list_price_minor' => '56250',
            'price_list_id' => $this->retail->id,
            'tax_inclusive' => true,
            'discount_minor' => '0',
            'tax_code_id' => $this->vat->id,
            'tax_rate' => '12.5000',
            'tax_minor' => '12500',
            'total_minor' => '112500',
            'override' => null,
        ], $overrides);
    }

    /**
     * A KES sale of one soap line paid in cash, numbered $seq from the
     * till's range (R-L01-000001...).
     */
    protected function saleBody(string $shiftId, int $seq = 1, array $overrides = []): array
    {
        $lines = $overrides['lines'] ?? [$this->line()];
        $sum = fn (string $key) => (string) array_sum(array_map(fn (array $l) => (int) $l[$key], $lines));
        $gross = (string) array_sum(array_map(fn (array $l) => (int) round((int) $l['unit_price_minor'] * (float) $l['qty']), $lines));
        $total = $sum('total_minor');

        return array_replace([
            'id' => $this->id(),
            'shift_id' => $shiftId,
            'cashier_id' => $this->owner->id,
            'actor_proof' => $this->actorProof($overrides['cashier_id'] ?? $this->owner->id),
            'customer_id' => null,
            'receipt_seq' => $seq,
            'receipt_number' => sprintf('R-L01-%06d', $seq),
            'sold_at' => now()->subMinutes(5)->toIso8601String(),
            'offline' => false,
            'currency' => 'KES',
            'price_list_id' => $this->retail->id,
            'lines' => $lines,
            'totals' => [
                'subtotal_minor' => $gross,
                'discount_minor' => $sum('discount_minor'),
                'tax_minor' => $sum('tax_minor'),
                'total_minor' => $total,
            ],
            'payments' => [[
                'id' => $this->id(),
                'payment_method_id' => $this->methods['cash_kes']->id,
                'currency' => 'KES',
                'amount_minor' => $total,
                'amount_in_sale_minor' => $total,
                'rate' => null,
                'status' => 'confirmed',
            ]],
            'change' => ['currency' => 'KES', 'amount_minor' => '0', 'rate' => null],
        ], $overrides);
    }

    protected function upload(array $sales, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/pos/sales', ['sales' => $sales], $this->tillHeaders($token));
    }

    protected function customer(?string $companyId = null): Party
    {
        return $this->inTenant(fn () => Party::create(['kind' => 'person', 'name' => 'Amina', 'roles' => ['customer'], 'company_id' => $companyId]));
    }

    /** AUTH-08: an override by $managerId the (fake) verifier proves, or can't verify when $proven is false. */
    protected function override(string $managerId, bool $proven = true): array
    {
        return ['manager_user_id' => $managerId, 'signature' => $proven ? FakeOverrides::VALID : 'unverifiable'];
    }

    /**
     * AUTH-07: a sign-in attestation for $userId signed with $device's
     * secret (the main till by default), as the POS app signs it. $online
     * also records the session as checked by POST pos/pin/verify. $fields
     * replace signed fields before signing; $tamper after.
     *
     * @return array{session_id: string, user_id: string, signed_in_at: string, kid: string, signature: string}
     */
    protected function actorProof(string $userId, ?Device $device = null, bool $online = false, array $fields = [], array $tamper = []): array
    {
        $device ??= $this->till;
        $secret = $this->tillSecrets[$device->id];
        $proof = array_replace([
            'session_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            // Signed in before the records it proves (sales are dated 5 minutes back), inside the key's skew window.
            'signed_in_at' => CarbonImmutable::now()->subMinutes(8)->format('Y-m-d\TH:i:s.v\Z'),
            'kid' => $secret['kid'],
        ], $fields);
        $message = ActorProofVerifier::message($device->id, $proof['kid'], $proof['session_id'], $proof['user_id'], $proof['signed_in_at']);
        $proof['signature'] = DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($secret['secret']), true));

        if ($online) {
            $this->inTenant(fn () => TillSignIn::create([
                'device_id' => $device->id, 'user_id' => $userId, 'session_id' => $proof['session_id'], 'signed_in_at' => $proof['signed_in_at'], 'verified_at' => now(),
            ]));
        }

        return array_replace($proof, $tamper);
    }

    protected function setCashRounding(string $code, int $step): void
    {
        $this->inTenant(fn () => TenantCurrency::where('code', $code)->update(['cash_rounding_minor' => $step]));
    }
}
