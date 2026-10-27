<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\UsesObjectStorage;
use Tests\TestCase;

// PHA-14, PHA-15, PHA-28: prescription records (personal data), their images
// on the tenant files disk behind signed links that are checked and audited
// when used, and who may see them.
class PrescriptionsTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase, UsesObjectStorage;

    private User $pharmacist;

    private User $technician;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useObjectStorage();
        $this->setUpPharmacy();
        $this->pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $this->technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'location_id' => $this->locationA->id,
            'prescriber_name' => 'Dr Test Prescriber', 'prescriber_licence' => 'LIC-1', 'facility' => 'Test Clinic',
            'patient_name' => 'Pat Patient', 'patient_phone' => '+254700000001', 'patient_birth_date' => '1990-04-01',
            'prescribed_on' => $this->inDays(-2), 'notes' => 'Twice daily',
        ], $overrides);
    }

    public function test_a_prescription_is_recorded_with_who_prescribed_for_whom(): void
    {
        $amina = $this->customer();
        $created = $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(['patient_party_id' => $amina->id]), $this->headersFor($this->technician))->assertCreated()
            ->assertJsonPath('data.prescriber_name', 'Dr Test Prescriber')
            ->assertJsonPath('data.prescriber_licence', 'LIC-1')
            ->assertJsonPath('data.facility', 'Test Clinic')
            ->assertJsonPath('data.patient_name', 'Pat Patient')
            ->assertJsonPath('data.patient.name', 'Amina')
            ->assertJsonPath('data.prescribed_on', $this->inDays(-2))
            ->assertJsonPath('data.source', 'office')
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.image', null)
            ->assertJsonPath('data.created_by.id', $this->technician->id)
            ->json('data');

        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.prescription.create')->where('auditable_id', $created['id'])->count()));
        $this->getJson("/api/v1/pharmacy/prescriptions/{$created['id']}", $this->headersFor($this->pharmacist))->assertOk()->assertJsonPath('data.facility', 'Test Clinic');
        $this->getJson('/api/v1/pharmacy/prescriptions?search=pat', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pharmacy/prescriptions?search=nobody', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pharmacy/prescriptions?status=cancelled', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_record_is_checked_before_it_is_stored(): void
    {
        $headers = $this->headersFor($this->technician);

        foreach (['prescriber_name', 'prescriber_licence', 'facility', 'patient_name', 'prescribed_on'] as $field) {
            $this->postJson('/api/v1/pharmacy/prescriptions', $this->body([$field => '']), $headers)->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(['prescribed_on' => $this->inDays(10)]), $headers)->assertUnprocessable()->assertJsonValidationErrors('prescribed_on');
        $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(['patient_birth_date' => $this->inDays(10)]), $headers)->assertUnprocessable()->assertJsonValidationErrors('patient_birth_date');
        // The licence number's format is a regulator's rule, never guessed here.
        $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(['prescriber_licence' => 'any text 123']), $headers)->assertCreated();
        $this->assertSame(1, $this->inTenant(fn () => Prescription::count()));
    }

    public function test_the_image_is_stored_on_the_tenant_disk_and_read_through_an_audited_signed_link(): void
    {
        $id = $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(), $this->headersFor($this->technician))->assertCreated()->json('data.id');

        $this->post("/api/v1/pharmacy/prescriptions/{$id}/image", ['file' => UploadedFile::fake()->create('x.exe', 1, 'application/x-msdownload')], $this->headersFor($this->technician))
            ->assertUnprocessable();
        $this->post("/api/v1/pharmacy/prescriptions/{$id}/image", ['file' => UploadedFile::fake()->image('scan.jpg')], $this->headersFor($this->cashier))->assertNotFound();
        $this->post("/api/v1/pharmacy/prescriptions/{$id}/image", ['file' => UploadedFile::fake()->image('scan.jpg')], $this->headersFor($this->technician))->assertOk()
            ->assertJsonPath('data.image.name', 'scan.jpg')->assertJsonPath('data.image.mime', 'image/jpeg');

        $row = $this->inTenant(fn () => Prescription::query()->findOrFail($id));
        $this->assertOnObjectStorage($row->image_disk, $row->image_path, $this->owner->tenant_id, 'pharmacy/prescriptions/');

        // Reading the image needs its own permission; the link is the API's signed route, bound to the reader.
        $this->getJson("/api/v1/pharmacy/prescriptions/{$id}/image-link", $this->headersFor($this->technician))->assertForbidden();
        $url = $this->getJson("/api/v1/pharmacy/prescriptions/{$id}/image-link", $this->headersFor($this->pharmacist))->assertOk()->json('data.url');
        $this->assertSignedApiUrl($url);
        $this->assertFalse($this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.prescription.download')->exists()), 'A link is not a download.');
        $this->get($url)->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->assertTrue($this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.prescription.download')->where('auditable_id', $id)->exists()));

        // Checked again when used: an archived prescription, or a link that has expired, gives nothing.
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $this->inTenant(fn () => Prescription::query()->whereKey($id)->update(['archived_at' => now()]));
        $this->get($url)->assertNotFound();
    }

    public function test_a_prescription_can_be_cancelled_once_and_never_deleted(): void
    {
        $id = $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(), $this->headersFor($this->technician))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/pharmacy/prescriptions/{$id}/cancel", ['reason' => 'Wrong patient'], $this->headersFor($this->technician))->assertForbidden();
        $this->postJson("/api/v1/pharmacy/prescriptions/{$id}/cancel", ['reason' => 'Wrong patient'], $this->headersFor($this->pharmacist))->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Wrong patient');
        $this->postJson("/api/v1/pharmacy/prescriptions/{$id}/cancel", ['reason' => 'Again'], $this->headersFor($this->pharmacist))->assertStatus(409)->assertJsonPath('code', 'prescription_cancelled');
        $this->assertSame(1, $this->inTenant(fn () => Prescription::count()));
    }

    public function test_patients_are_personal_data_for_those_who_may_see_them_where_they_work(): void
    {
        $id = $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(), $this->headersFor($this->technician))->assertCreated()->json('data.id');

        // A cashier takes a prescription down at the till but cannot read the log; an Admin and an auditor do not read it either.
        $this->getJson('/api/v1/pharmacy/prescriptions', $this->headersFor($this->cashier))->assertForbidden();
        $this->getJson("/api/v1/pharmacy/prescriptions/{$id}", $this->headersFor($this->cashier))->assertNotFound();
        $admin = $this->userWith('admin', Scope::tenant());
        $this->getJson('/api/v1/pharmacy/prescriptions', $this->headersFor($admin))->assertForbidden();
        $auditor = $this->userWith('read_only_auditor', Scope::tenant());
        $this->getJson('/api/v1/pharmacy/prescriptions', $this->headersFor($auditor))->assertForbidden();

        // Another place's pharmacist sees none of this place's patients.
        $elsewhere = $this->userWith('pharmacist', Scope::location($this->locationB->id));
        $this->getJson('/api/v1/pharmacy/prescriptions', $this->headersFor($elsewhere))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/pharmacy/prescriptions/{$id}", $this->headersFor($elsewhere))->assertNotFound();
        $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(), $this->headersFor($elsewhere))->assertForbidden();

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));
        $this->getJson("/api/v1/pharmacy/prescriptions/{$id}", $this->headersFor($other['user']))->assertNotFound();
        $this->getJson("/api/v1/pharmacy/prescriptions/{$id}/image-link", $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/pharmacy/prescriptions', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_privacy_report_lists_a_customers_prescriptions(): void
    {
        $amina = $this->customer();
        $this->postJson('/api/v1/pharmacy/prescriptions', $this->body(['patient_party_id' => $amina->id]), $this->headersFor($this->technician))->assertCreated();

        $sources = $this->inTenant(fn () => collect(app(PrivacySubjects::class)->sources('customer'))->map(fn ($source) => $source->table)->all());
        $this->assertContains('pharmacy_prescriptions', $sources);
        $this->assertContains('pharmacy_dispenses', $sources);
        $this->assertContains('pharmacy_lot_movements', $sources);
    }
}
