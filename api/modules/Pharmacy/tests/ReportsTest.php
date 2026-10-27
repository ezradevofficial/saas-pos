<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-24, PHA-26: the expiry report, slow movers per batch, the prescription
// log and the review of flagged dispenses.
class ReportsTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $pharmacist;

    private User $technician;

    private User $cashier;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPharmacy();
        $this->pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $this->technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
    }

    public function test_the_expiry_report_lists_what_expired_or_expires_soon_earliest_first(): void
    {
        $this->lot($this->amox, 'OLD', -5, '2');
        $this->lot($this->amox, 'SOON', 20, '3');
        $this->lot($this->para, 'MID', 80, '4');
        $this->lot($this->para, 'FAR', 400, '5');
        $headers = $this->headersFor($this->pharmacist);

        // The default window is the company's own (90 days).
        $this->getJson('/api/v1/pharmacy/reports/expiry', $headers)->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.batch_number', 'OLD')->assertJsonPath('data.0.state', 'expired')
            ->assertJsonPath('data.1.batch_number', 'SOON')->assertJsonPath('data.2.batch_number', 'MID');
        $this->getJson('/api/v1/pharmacy/reports/expiry?days=30', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->settings(['near_expiry_days' => 10]);
        $this->getJson('/api/v1/pharmacy/reports/expiry', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.batch_number', 'OLD');

        $csv = $this->get('/api/v1/pharmacy/reports/expiry?days=30&format=csv', $headers)->assertOk()->streamedContent();
        $this->assertStringContainsString('Amoxicillin 500 mg (AMOX)', $csv);
        $this->assertStringContainsString('SOON', $csv);
        $this->assertStringNotContainsString('FAR', $csv);

        $this->getJson('/api/v1/pharmacy/reports/expiry', $this->headersFor($this->cashier))->assertForbidden();
        $elsewhere = $this->userWith('pharmacist', Scope::location($this->locationB->id));
        $this->getJson('/api/v1/pharmacy/reports/expiry', $this->headersFor($elsewhere))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_slow_movers_per_batch_show_when_each_last_sold_at_its_place(): void
    {
        $stale = $this->lot($this->para, 'STALE', 300, '5');
        $lively = $this->lot($this->para, 'LIVELY', 300, '5');
        $never = $this->lot($this->para, 'NEVER', 300, '5');
        $this->inTenant(function () use ($stale, $lively) {
            $lots = app(Lots::class);
            $lots->move($stale, $this->locationA->id, LotMovement::SALE, BigDecimal::of('-1'), 'pos.sale', (string) Str::uuid7(), null, CarbonImmutable::now()->subDays(120));
            $lots->move($lively, $this->locationA->id, LotMovement::SALE, BigDecimal::of('-1'), 'pos.sale', (string) Str::uuid7(), null, CarbonImmutable::now()->subDays(2));
        });

        $rows = $this->getJson('/api/v1/pharmacy/reports/slow-movers?days=90', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame(['NEVER', null, null], [$rows[0]['batch_number'], $rows[0]['last_sold_at'], $rows[0]['days_since_sale']]);
        $this->assertSame(['STALE', 120], [$rows[1]['batch_number'], $rows[1]['days_since_sale']]);
        $this->getJson('/api/v1/pharmacy/reports/slow-movers?days=1', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/pharmacy/reports/slow-movers?days=400', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.batch_number', 'NEVER');
        $this->get('/api/v1/pharmacy/reports/slow-movers?format=csv', $this->headersFor($this->pharmacist))->assertOk();
        $this->assertNotNull($never->id);
    }

    public function test_the_prescription_log_and_the_review_of_flagged_dispenses(): void
    {
        $lot = $this->lot($this->amox, 'RX', 200, '20');
        $prescription = $this->prescriptionBody();
        $this->sellDrugs($this->shift, 1, [$this->drugLine($this->amox, '1', ['id' => $a = $this->id()])], [
            'lines' => [['line_id' => $a, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]]], 'prescription' => $prescription, 'approval' => $this->override($this->pharmacist->id),
        ], ['cashier_id' => $this->cashier->id]);
        // Sold with nothing: flagged.
        $this->sellDrugs($this->shift, 2, [$this->drugLine($this->amox, '1')], [], ['cashier_id' => $this->cashier->id]);
        $headers = $this->headersFor($this->pharmacist);

        $this->getJson('/api/v1/pharmacy/dispenses', $headers)->assertOk()->assertJsonCount(2, 'data');
        $log = $this->getJson('/api/v1/pharmacy/reports/prescriptions', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['Pat Patient', 'Dr Test Prescriber', 'LIC-0001', 'override'], [
            $log->json('data.0.prescription.patient_name'), $log->json('data.0.prescription.prescriber_name'), $log->json('data.0.prescription.prescriber_licence'), $log->json('data.0.approval_mode'),
        ]);
        $flagged = $this->getJson('/api/v1/pharmacy/dispenses?review=open', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->assertEqualsCanonicalizing(['prescription_missing', 'pharmacist_missing', 'batch_not_chosen'], $flagged->json('data.0.flags'));
        $id = $flagged->json('data.0.id');

        $csv = $this->get('/api/v1/pharmacy/reports/prescriptions?format=csv', $headers)->assertOk()->streamedContent();
        $this->assertStringContainsString('Pat Patient', $csv);

        // A technician reads the log but does not close a flagged sale.
        $this->getJson('/api/v1/pharmacy/dispenses', $this->headersFor($this->technician))->assertOk();
        $this->postJson("/api/v1/pharmacy/dispenses/{$id}/review", ['note' => 'Customer came back with the prescription'], $this->headersFor($this->technician))->assertNotFound();
        $this->postJson("/api/v1/pharmacy/dispenses/{$id}/review", ['note' => ''], $headers)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/pharmacy/dispenses/{$id}/review", ['note' => 'Customer came back with the prescription'], $headers)->assertOk()
            ->assertJsonPath('data.review_status', 'reviewed')->assertJsonPath('data.review_note', 'Customer came back with the prescription');
        $this->postJson("/api/v1/pharmacy/dispenses/{$id}/review", ['note' => 'Again'], $headers)->assertStatus(409)->assertJsonPath('code', 'dispense_not_open');
        $this->getJson('/api/v1/pharmacy/dispenses?review=open', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.dispense.review')->where('auditable_id', $id)->count()));

        $elsewhere = $this->userWith('pharmacist', Scope::location($this->locationB->id));
        $this->postJson("/api/v1/pharmacy/dispenses/{$id}/review", ['note' => 'x'], $this->headersFor($elsewhere))->assertNotFound();
        $this->getJson('/api/v1/pharmacy/dispenses', $this->headersFor($elsewhere))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pharmacy/dispenses', $this->headersFor($this->cashier))->assertForbidden();
    }
}
