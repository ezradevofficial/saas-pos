<?php

namespace Modules\Pharmacy\Tests\Concerns;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotBalance;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Settings;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\Qty;
use Modules\POS\Tests\Concerns\BuildsPos;

/**
 * A tenant with the POS and Pharmacy modules active (BuildsPos' Acme in KES,
 * outlets A and B, a paired till at A) and six items, all sold for KES 562.50
 * (BuildsPos' soap price): amoxicillin (prescription only), paracetamol (over
 * the counter, also sold by the box of 12), morphine (controlled), a pesticide
 * (agrochemical, restricted sale), a veterinary injection (prescription only)
 * and a plain item with no pharmacy profile. All but the plain item are
 * tracked by lot. Names are generic; no real product or schedule is implied.
 */
trait BuildsPharmacy
{
    use BuildsPos;

    protected Item $amox;

    protected Item $para;

    protected Item $morphine;

    protected Item $pesticide;

    protected Item $vet;

    protected Item $plain;

    protected Uom $box;

    protected function setUpPharmacy(): void
    {
        $this->setUpPos();

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('pharmacy');
            $this->box = Uom::create(['code' => 'BOX', 'name' => 'Box', 'kind' => 'count']);
            $profiles = app(Profiles::class);

            $make = function (string $code, string $name, array $profile) use ($profiles): Item {
                $item = Item::create(['code' => $code, 'name' => $name, 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);
                ItemPrice::create(['price_list_id' => $this->retail->id, 'item_id' => $item->id, 'uom_id' => $this->each->id, 'amount_minor' => '56250', 'currency' => 'KES', 'effective_from' => '2026-01-01']);

                if ($profile !== []) {
                    $profiles->save($item, $profile);
                }

                return $item;
            };

            $this->amox = $make('AMOX', 'Amoxicillin 500 mg', ['class' => 'prescription_only', 'batch_tracked' => true]);
            $this->para = $make('PARA', 'Paracetamol 500 mg', ['class' => 'otc', 'batch_tracked' => true]);
            ItemUom::create(['item_id' => $this->para->id, 'uom_id' => $this->box->id, 'factor' => '12']);
            $this->morphine = $make('MORPH', 'Morphine 10 mg', ['class' => 'controlled', 'batch_tracked' => true, 'schedule_note' => 'Schedule to confirm']);
            $this->pesticide = $make('PEST', 'Pesticide 1 l', ['class' => 'agrochemical', 'product_kind' => 'agrochemical', 'batch_tracked' => true, 'restricted_sale' => true, 'usage_notes' => 'Wear gloves. Keep from water.']);
            $this->vet = $make('VET', 'Veterinary injection', ['class' => 'prescription_only', 'product_kind' => 'veterinary', 'batch_tracked' => true, 'withdrawal_days' => 14]);
            $this->plain = $make('PLAIN', 'Plain item', []);
        });
    }

    /** $days from today (negative: past) as a Y-m-d date. */
    protected function inDays(int $days): string
    {
        return CarbonImmutable::now()->addDays($days)->toDateString();
    }

    /** A lot of $item received at a location (A by default), through the ledger. */
    protected function lot(Item $item, string $batch, int $expiresInDays, string $qty, ?string $locationId = null, ?int $manufacturedDaysAgo = null): Lot
    {
        return $this->inTenant(function () use ($item, $batch, $expiresInDays, $qty, $locationId, $manufacturedDaysAgo) {
            $lots = app(Lots::class);
            $lot = $lots->ensure($this->acme->id, $item->id, $batch, $this->inDays($expiresInDays), $manufacturedDaysAgo === null ? null : $this->inDays(-$manufacturedDaysAgo));
            $lots->move($lot, $locationId ?? $this->locationA->id, LotMovement::RECEIPT, Qty::of($qty), 'test.receipt', (string) Str::uuid7());

            return $lot;
        });
    }

    protected function lotQty(Lot $lot, ?string $locationId = null): string
    {
        return $this->inTenant(fn () => Qty::show((string) (LotBalance::query()->where('lot_id', $lot->id)->where('location_id', $locationId ?? $this->locationA->id)->value('qty') ?? '0')));
    }

    protected function settings(array $values): void
    {
        $this->inTenant(fn () => Settings::query()->updateOrCreate(['company_id' => $this->acme->id], $values));
    }

    /** A sale line of $item (KES 562.50 each, no tax computed beyond BuildsPos' test rate). */
    protected function drugLine(Item $item, string $qty = '1', array $overrides = []): array
    {
        $qtyInt = (int) $qty;
        $gross = 56250 * $qtyInt;
        $tax = (int) round($gross * 0.125 / 1.125);

        return $this->line([
            'item_id' => $item->id,
            'qty' => $qty,
            'tax_minor' => (string) $tax,
            'total_minor' => (string) $gross,
            ...$overrides,
        ]);
    }

    /**
     * Upload a sale of $lines on the open shift with the pharmacy extension and
     * return it. `$pharmacy` is the till's `extensions.pharmacy`; `$over`
     * replaces fields of the sale body (customer, cashier...).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $pharmacy
     * @param  array<string, mixed>  $over
     */
    protected function sellDrugs(string $shift, int $seq, array $lines, array $pharmacy = [], array $over = []): array
    {
        $sale = $this->saleBody($shift, $seq, [
            'lines' => $lines,
            ...($pharmacy === [] ? [] : ['extensions' => ['pharmacy' => $pharmacy]]),
            ...$over,
        ]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        return $sale;
    }

    /** The till's prescription for a sale. */
    protected function prescriptionBody(array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(),
            'prescriber_name' => 'Dr Test Prescriber',
            'prescriber_licence' => 'LIC-0001',
            'facility' => 'Test Clinic',
            'patient_name' => 'Pat Patient',
            'prescribed_on' => $this->inDays(-1),
        ], $overrides);
    }
}
