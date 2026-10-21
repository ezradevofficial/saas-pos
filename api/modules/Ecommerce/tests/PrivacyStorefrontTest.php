<?php

namespace Modules\Ecommerce\Tests;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08 (review M5): a customer's storefront account, delivery addresses
 * and sign-in codes are part of their data: in the access report, and
 * cleared by a deletion. Another shopper's rows stay as they are.
 */
class PrivacyStorefrontTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    private Party $amina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();

        $this->amina = $this->inTenant(function () {
            $party = Party::query()->create(['company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Amina Otieno', 'roles' => ['customer']]);
            foreach ([[$party->id, 'Amina Otieno', '+254712000111', 'amina@example.com', 'Moi Avenue 12'], [null, 'Other Shopper', '+254733000222', 'other@example.com', 'Kenyatta Road 4']] as [$partyId, $name, $phone, $email, $line]) {
                $customer = (string) Str::uuid7();
                DB::table('ecom_customers')->insert(['id' => $customer, 'company_id' => $this->acme->id, 'party_id' => $partyId, 'name' => $name, 'phone' => $phone, 'email' => $email]);
                DB::table('ecom_addresses')->insert(['id' => (string) Str::uuid7(), 'customer_id' => $customer, 'line' => $line, 'area' => 'CBD', 'notes' => 'Blue gate']);
                DB::table('ecom_customer_codes')->insert(['id' => (string) Str::uuid7(), 'store_id' => $this->shop->id, 'contact' => $phone, 'channel' => 'sms', 'code_hash' => str_repeat('c', 64), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);
            }

            return $party;
        });
    }

    private function completed(string $kind): string
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->amina->id, 'kind' => $kind], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk();

        return $id;
    }

    public function test_the_access_report_holds_the_customers_addresses_and_codes_only(): void
    {
        $id = $this->completed('access');
        $report = $this->getJson("/api/v1/privacy/requests/{$id}/report", $this->headersFor())->assertOk()->json();

        $this->assertSame('Moi Avenue 12', $report['records']['ecom_addresses'][0]['line']);
        $this->assertSame('+254712000111', $report['records']['ecom_customer_codes'][0]['contact']);
        $this->assertCount(1, $report['records']['ecom_addresses']);
        $this->assertStringNotContainsString('Kenyatta Road', json_encode($report));
        $this->assertStringNotContainsString('+254733000222', json_encode($report));
    }

    public function test_a_deletion_clears_addresses_and_sign_in_codes(): void
    {
        $this->completed('deletion');

        $this->inTenant(function () {
            $address = DB::table('ecom_addresses')->join('ecom_customers', 'ecom_customers.id', '=', 'ecom_addresses.customer_id')->where('ecom_customers.party_id', $this->amina->id)->select('ecom_addresses.*')->first();
            $this->assertSame('', $address->line);
            $this->assertNull($address->area);
            $this->assertNull($address->notes);
            $this->assertNotNull($address->archived_at);
            $this->assertFalse(DB::table('ecom_customer_codes')->where('contact', '+254712000111')->exists());
            // The other shopper is untouched.
            $this->assertSame('Kenyatta Road 4', DB::table('ecom_addresses')->where('line', 'Kenyatta Road 4')->value('line'));
            $this->assertTrue(DB::table('ecom_customer_codes')->where('contact', '+254733000222')->exists());
        });
    }
}
