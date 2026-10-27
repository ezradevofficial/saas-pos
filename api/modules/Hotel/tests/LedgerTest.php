<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\PostingException;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-27: what the folio means for Accounting when it is active: charges, deposits, payments and POS room charges reach the guest ledger,
// revenue, tax and tender accounts of the KE default rules, and the guest ledger is empty once the folio is paid.
class LedgerTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('accounting');
            app(Charts::class)->fromTemplate($this->acme);
        });
    }

    /** An account's balance (base debits less credits, minor units) over every posted journal. */
    private function balance(string $code): int
    {
        return $this->inTenant(fn () => (int) JournalLine::query()->where('account_id', Account::query()->where('company_id', $this->acme->id)->where('code', $code)->value('id'))
            ->whereIn('journal_id', Journal::query()->where('status', 'posted')->select('id'))
            ->selectRaw('coalesce(sum(base_debit_minor - base_credit_minor), 0) as balance')->value('balance'));
    }

    public function test_a_stay_posts_revenue_tax_deposit_and_payment_and_leaves_the_guest_ledger_empty(): void
    {
        $stay = $this->checkedIn(2, '101');
        $folio = $this->inTenant(fn () => Folio::query()->where('stay_id', $stay['id'])->value('id'));
        $this->postJson("/api/v1/hotel/folios/{$folio}/payments", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 500000, 'kind' => 'deposit'], $this->headersFor())->assertCreated();
        // The deposit is a liability until it is applied.
        $this->assertSame([-500000, 500000], [$this->balance('2300'), $this->balance('1110')]);

        foreach ([0, 1] as $ignored) {
            $this->postJson('/api/v1/hotel/night-audits', ['branch_id' => $this->branchA->id], $this->headersFor())->assertCreated();
        }

        $this->assertSame([2000000, -1777778, -222222], [$this->balance('1280'), $this->balance('4110'), $this->balance('2200')]);
        $this->postJson("/api/v1/hotel/stays/{$stay['id']}/check-out", ['payments' => [['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 1500000]]], $this->headersFor())->assertOk();

        $this->assertSame([0, 0, 2000000], [$this->balance('1280'), $this->balance('2300'), $this->balance('1110')]);
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()), 'nothing waits as an exception');
        $this->assertSame(['hotel.charge' => 2, 'hotel.deposit' => 1, 'hotel.deposit_applied' => 1, 'hotel.payment' => 1], $this->inTenant(fn () => Journal::query()->where('source_type', 'like', 'hotel.%')->selectRaw('source_type, count(*) as n')->groupBy('source_type')->pluck('n', 'source_type')->map(fn ($n) => (int) $n)->all()));
    }

    public function test_a_voided_charge_and_a_refund_post_the_other_way(): void
    {
        $stay = $this->checkedIn(1, '101');
        $folio = $this->inTenant(fn () => Folio::query()->where('stay_id', $stay['id'])->value('id'));
        $line = $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->minibar->id, 'unit_price_minor' => 100000, 'description' => 'Minibar'], $this->headersFor())->assertCreated()->json('data.id');
        $this->assertSame(112500, $this->balance('1280'));
        $this->postJson("/api/v1/hotel/folios/{$folio}/lines/{$line}/void", ['reason' => 'Wrong room'], $this->headersFor())->assertCreated();
        $this->assertSame([0, 0], [$this->balance('1280'), $this->balance('4200')]);

        $this->postJson("/api/v1/hotel/folios/{$folio}/payments", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 30000], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/hotel/folios/{$folio}/refunds", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 30000, 'reason' => 'Paid twice'], $this->headersFor())->assertCreated();
        $this->assertSame([0, 0], [$this->balance('1110'), $this->balance('1280')]);
    }

    public function test_a_pos_sale_charged_to_a_room_debits_the_guest_ledger(): void
    {
        $this->checkedIn(2, '101');
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1);
        $sale['payments'] = [[
            'id' => $this->id(), 'payment_method_id' => $this->roomCharge->id, 'currency' => 'KES', 'amount_minor' => $sale['totals']['total_minor'],
            'amount_in_sale_minor' => $sale['totals']['total_minor'], 'rate' => null, 'status' => 'confirmed', 'tender' => ['room' => '101'],
        ]];
        $this->upload([$sale])->assertOk();

        // Dr guest ledger 1,125.00 (POS journals the tender); the hotel posts nothing of its own for it.
        $this->assertSame(112500, $this->balance('1280'));
        $this->assertSame(0, $this->inTenant(fn () => Journal::query()->where('source_type', 'like', 'hotel.%')->count()));
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()));
    }
}
