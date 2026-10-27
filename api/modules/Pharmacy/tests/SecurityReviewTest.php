<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Privacy\SubjectData;
use App\Core\Rbac\Scope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Sales\Dispensing;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\Qty;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-08, PHA-16, PHA-17, PHA-24, PHA-26, PHA-28 (security review): a prescription is only ever
// attached to its own sale, patients are shown only to those who may read prescriptions, a
// pharmacist's own right counts only with a proven sign-in, and small checks around them.
class SecurityReviewTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $cashier;

    private User $pharmacist;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPharmacy();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
    }

    private function dispenseOf(array $sale): Dispense
    {
        return $this->inTenant(fn () => Dispense::query()->where('sale_id', $sale['id'])->sole());
    }

    /** A sale of one amoxicillin by the plain cashier, with whatever the till attached. */
    private function sellAmox(int $seq, array $pharmacy = [], array $over = []): array
    {
        $lot = $this->inTenant(fn () => Lot::query()->where('item_id', $this->amox->id)->first()) ?? $this->lot($this->amox, 'RX-'.$seq, 200, '20');
        $line = $this->id();

        return $this->sellDrugs($this->shift, $seq, [$this->drugLine($this->amox, '1', ['id' => $line])], [
            'lines' => [['line_id' => $line, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]]], ...$pharmacy,
        ], ['cashier_id' => $this->cashier->id, ...$over]);
    }

    public function test_a_prescription_of_another_sale_is_never_attached_or_copied_to_the_register(): void
    {
        $lot = $this->lot($this->morphine, 'M-1', 200, '10');
        $this->inTenant(fn () => app(ControlledRegister::class)->append($this->acme->id, $this->locationA->id, $this->morphine->id, RegisterEntry::RECEIPT, Qty::of('10'), 'test.receipt', (string) Str::uuid7(), null, $lot));
        $prescription = $this->prescriptionBody();
        $first = $this->sellDrugs($this->shift, 1, [$this->drugLine($this->morphine, '1', ['id' => $a = $this->id()])], [
            'lines' => [['line_id' => $a, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]]], 'prescription' => $prescription, 'approval' => $this->override($this->pharmacist->id),
        ], ['cashier_id' => $this->cashier->id]);
        $this->assertSame($prescription['id'], $this->dispenseOf($first)->prescription_id);

        // A second sale names the first one's prescription id: it is not attached, not shown, and flagged.
        $second = $this->sellDrugs($this->shift, 2, [$this->drugLine($this->morphine, '1', ['id' => $b = $this->id()])], [
            'lines' => [['line_id' => $b, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]]], 'prescription' => ['id' => $prescription['id']] + $this->prescriptionBody(), 'approval' => $this->override($this->pharmacist->id),
        ], ['cashier_id' => $this->cashier->id]);

        $dispense = $this->dispenseOf($second);
        $this->assertNull($dispense->prescription_id);
        $this->assertEqualsCanonicalizing(['prescription_missing', 'prescription_reused'], array_values(array_intersect($dispense->flags, ['prescription_missing', 'prescription_reused'])));
        $this->assertSame('open', $dispense->review_status);
        $entry = $this->inTenant(fn () => RegisterEntry::query()->where('source_id', $second['id'])->sole());
        $this->assertNull($entry->patient_name);
        $this->assertNull($entry->prescriber_name);
        $this->assertSame($first['id'], $this->inTenant(fn () => Prescription::query()->findOrFail($prescription['id']))->sale_id);
    }

    public function test_an_office_prescription_cannot_be_borrowed_by_a_till_either(): void
    {
        $technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
        $office = $this->postJson('/api/v1/pharmacy/prescriptions', [
            'location_id' => $this->locationA->id, 'prescriber_name' => 'Dr Office', 'prescriber_licence' => 'LIC-9', 'facility' => 'Clinic',
            'patient_name' => 'Someone Else', 'prescribed_on' => $this->inDays(-1),
        ], $this->headersFor($technician))->assertCreated()->json('data.id');

        $sale = $this->sellAmox(1, ['prescription' => ['id' => $office] + $this->prescriptionBody(), 'approval' => $this->override($this->pharmacist->id)]);

        $dispense = $this->dispenseOf($sale);
        $this->assertNull($dispense->prescription_id);
        $this->assertContains('prescription_reused', $dispense->flags);
    }

    public function test_a_prescription_dated_in_the_future_is_refused(): void
    {
        $sale = $this->sellAmox(1, ['prescription' => $this->prescriptionBody(['prescribed_on' => $this->inDays(10)]), 'approval' => $this->override($this->pharmacist->id)]);

        $dispense = $this->dispenseOf($sale);
        $this->assertNull($dispense->prescription_id);
        $this->assertContains('prescription_missing', $dispense->flags);
        $this->assertSame(0, $this->inTenant(fn () => Prescription::count()));
    }

    public function test_the_prescription_log_hides_patients_from_readers_who_may_not_read_prescriptions(): void
    {
        $this->sellAmox(1, ['prescription' => $this->prescriptionBody(), 'approval' => $this->override($this->pharmacist->id)]);
        $auditor = $this->userWith('read_only_auditor', Scope::tenant());
        $admin = $this->userWith('admin', Scope::tenant());

        foreach ([$auditor, $admin] as $reader) {
            $headers = $this->headersFor($reader);
            foreach (['/api/v1/pharmacy/dispenses', '/api/v1/pharmacy/reports/prescriptions'] as $url) {
                $row = $this->getJson($url, $headers)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
                $this->assertArrayNotHasKey('patient_name', $row['prescription']);
                $this->assertArrayNotHasKey('prescriber_name', $row['prescription']);
                $this->assertArrayNotHasKey('prescriber_licence', $row['prescription']);
            }
            // The patient search finds nothing for them (it would leak the name), but finds the receipt.
            $this->getJson('/api/v1/pharmacy/dispenses?search=Patient', $headers)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/pharmacy/dispenses?search=LIC', $headers)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/pharmacy/dispenses?search=R-L01', $headers)->assertOk()->assertJsonCount(1, 'data');
            $csv = $this->get('/api/v1/pharmacy/reports/prescriptions?format=csv', $headers)->assertOk()->streamedContent();
            $this->assertStringNotContainsString('Pat Patient', $csv);
            $this->assertStringNotContainsString('LIC-0001', $csv);
        }

        // The pharmacy team still reads and searches them.
        $headers = $this->headersFor($this->pharmacist);
        $this->getJson('/api/v1/pharmacy/dispenses?search=Patient', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.prescription.patient_name', 'Pat Patient');
        $this->assertStringContainsString('Pat Patient', $this->get('/api/v1/pharmacy/reports/prescriptions?format=csv', $headers)->assertOk()->streamedContent());
    }

    public function test_a_pharmacists_own_right_counts_only_when_the_till_proved_who_was_signed_in(): void
    {
        $proven = $this->sellAmox(1, ['prescription' => $this->prescriptionBody()], ['cashier_id' => $this->pharmacist->id]);
        $this->assertSame(['self', $this->pharmacist->id], [$this->dispenseOf($proven)->approval_mode, $this->dispenseOf($proven)->pharmacist_id]);

        // The sign-in attestation does not verify: the cashier's right is not taken on trust.
        $forged = $this->sellAmox(2, ['prescription' => $this->prescriptionBody()], ['cashier_id' => $this->pharmacist->id, 'actor_proof' => $this->actorProof($this->pharmacist->id, tamper: ['signature' => 'forged'])]);
        $dispense = $this->dispenseOf($forged);
        $this->assertSame(['none', null, 'open'], [$dispense->approval_mode, $dispense->pharmacist_id, $dispense->review_status]);
        $this->assertContains('pharmacist_unverified', $dispense->flags);
    }

    public function test_an_approval_given_offline_is_reviewed(): void
    {
        $this->assertContains('approval_offline', Dispensing::REVIEW_FLAGS);
        $sale = $this->sellAmox(1, ['prescription' => $this->prescriptionBody(), 'approval' => [...$this->override($this->pharmacist->id), 'mode' => 'offline']]);

        $dispense = $this->dispenseOf($sale);
        $this->assertSame(['approval_offline'], $dispense->flags);
        $this->assertSame('open', $dispense->review_status);
    }

    public function test_a_lot_of_another_company_is_never_taken_from(): void
    {
        $other = $this->inTenant(fn () => $this->company('Other company'));
        $foreign = $this->inTenant(function () use ($other) {
            $lots = app(Lots::class);
            $lot = $lots->ensure($other->id, $this->amox->id, 'FOREIGN', $this->inDays(300));
            $lots->move($lot, $this->locationA->id, LotMovement::RECEIPT, Qty::of('10'), 'test.receipt', (string) Str::uuid7());

            return $lot;
        });
        $own = $this->lot($this->amox, 'OWN', 200, '10');
        $line = $this->id();

        $sale = $this->sellDrugs($this->shift, 1, [$this->drugLine($this->amox, '2', ['id' => $line])], [
            'lines' => [['line_id' => $line, 'lots' => [['lot_id' => $foreign->id, 'qty' => '2']]]], 'prescription' => $this->prescriptionBody(), 'approval' => $this->override($this->pharmacist->id),
        ], ['cashier_id' => $this->cashier->id]);

        $this->assertSame(['10', '8'], [$this->lotQty($foreign), $this->lotQty($own)]);
        $dispense = $this->dispenseOf($sale);
        $this->assertContains('lot_company_mismatch', $dispense->flags);
        $this->assertSame('open', $dispense->review_status);
    }

    public function test_a_move_re_checks_the_stock_under_the_lock(): void
    {
        $lot = $this->lot($this->para, 'LOW', 200, '3');

        $refused = $this->inTenant(function () use ($lot) {
            try {
                app(Lots::class)->move($lot, $this->locationA->id, LotMovement::TRANSFER_OUT, BigDecimal::of('-5'), 'test', (string) Str::uuid7(), refuseShortage: true);
            } catch (ApiException $e) {
                return $e->getStatusCode();
            }

            return null;
        });

        $this->assertSame(422, $refused);
        $this->assertSame('3', $this->lotQty($lot));
    }

    public function test_a_register_correction_names_a_lot_of_the_same_item(): void
    {
        $wrongLot = $this->lot($this->para, 'WRONG', 200, '3');
        $headers = $this->headersFor($this->pharmacist);
        $body = ['item_id' => $this->morphine->id, 'location_id' => $this->locationA->id, 'qty' => '1', 'reason' => 'Count correction'];

        $this->postJson('/api/v1/pharmacy/register/adjustments', [...$body, 'lot_id' => $wrongLot->id], $headers)->assertUnprocessable()->assertJsonValidationErrors('lot_id');
        $right = $this->lot($this->morphine, 'RIGHT', 200, '3');
        $this->postJson('/api/v1/pharmacy/register/adjustments', [...$body, 'lot_id' => $right->id], $headers)->assertCreated();
    }

    public function test_nobody_closes_the_flag_on_a_sale_they_made(): void
    {
        $sale = $this->sellAmox(1, [], ['cashier_id' => $this->pharmacist->id]);
        $id = $this->dispenseOf($sale)->id;
        $this->assertSame('open', $this->dispenseOf($sale)->review_status);

        $this->postJson("/api/v1/pharmacy/dispenses/{$id}/review", ['note' => 'Looked fine'], $this->headersFor($this->pharmacist))->assertForbidden();
        $second = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $this->postJson("/api/v1/pharmacy/dispenses/{$id}/review", ['note' => 'Looked fine'], $this->headersFor($second))->assertOk()->assertJsonPath('data.review_status', 'reviewed');
    }

    // NFR-08, PHA-28: erasing a patient (the customer a prescription was taken down for) clears the name, phone
    // and image of their prescriptions; the register keeps its append-only rows.
    public function test_erasing_a_patient_anonymises_their_prescriptions_but_keeps_the_register(): void
    {
        Storage::fake('local');
        $lot = $this->lot($this->morphine, 'M-9', 200, '10');
        $this->inTenant(fn () => app(ControlledRegister::class)->append($this->acme->id, $this->locationA->id, $this->morphine->id, RegisterEntry::RECEIPT, Qty::of('10'), 'test.receipt', (string) Str::uuid7(), null, $lot));
        $amina = $this->customer();
        $prescription = $this->prescriptionBody(['patient_phone' => '+254700000009']);
        $this->sellDrugs($this->shift, 1, [$this->drugLine($this->morphine, '1', ['id' => $line = $this->id()])], [
            'lines' => [['line_id' => $line, 'lots' => [['lot_id' => $lot->id, 'qty' => '1']]]], 'prescription' => $prescription, 'approval' => $this->override($this->pharmacist->id),
        ], ['cashier_id' => $this->cashier->id, 'customer_id' => $amina->id]);
        Storage::disk('local')->put('rx/scan.jpg', 'image');
        $this->inTenant(fn () => DB::table('pharmacy_prescriptions')->where('id', $prescription['id'])->update(['image_disk' => 'local', 'image_path' => 'rx/scan.jpg', 'image_name' => 'scan.jpg', 'image_mime' => 'image/jpeg']));

        $report = $this->inTenant(fn () => app(SubjectData::class)->anonymise('customer', $amina->id, 'Erased'));

        $stored = $this->inTenant(fn () => Prescription::query()->findOrFail($prescription['id']));
        $this->assertSame(['Erased', null, null, null], [$stored->patient_name, $stored->patient_phone, $stored->image_path, $stored->image_name]);
        $this->assertSame('Dr Test Prescriber', $stored->prescriber_name);
        Storage::disk('local')->assertMissing('rx/scan.jpg');
        $this->assertContains('pharmacy_prescriptions', array_keys($report['changed']));
        $this->assertSame('Pat Patient', $this->inTenant(fn () => RegisterEntry::query()->where('kind', 'dispense')->sole())->patient_name);
        $this->assertContains('pharmacy_register_entries', array_column($report['kept'], 'table'));
    }
}
