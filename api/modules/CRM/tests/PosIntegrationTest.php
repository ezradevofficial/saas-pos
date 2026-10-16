<?php

namespace Modules\CRM\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-25, CRM-30: with POS active, the customer 360 view shows the
// customer's till sales to readers holding pos.sale.view (through POS's
// CustomerSales contract) and POS targets read POS's SalesTotals; with POS
// off neither appears.
class PosIntegrationTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('crm'));
    }

    public function test_pos_sales_reach_the_360_view_and_pos_targets_through_the_pos_contracts(): void
    {
        $customer = $this->customer();
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $this->upload([$this->saleBody($shift, 1, ['customer_id' => $customer->id]), $this->saleBody($shift, 2)])->assertOk();
        $headers = $this->headersFor();

        $overview = $this->getJson("/api/v1/crm/customers/{$customer->id}/overview", $headers)->assertOk()->json('data');
        $this->assertSame([1, [['currency' => 'KES', 'total_minor' => '112500']], 'R-L01-000001'],
            [$overview['pos']['count'], $overview['pos']['totals'], $overview['pos']['recent'][0]['receipt_number']]);

        // A reader without pos.sale.view gets no POS part.
        $rep = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Rep', ['crm.lead.view', 'crm.opportunity.view', 'crm.quote.view', 'crm.activity.view', 'core.party.view']), Scope::branch($this->branchA->id));

            return $user;
        });
        $this->assertNull($this->getJson("/api/v1/crm/customers/{$customer->id}/overview", $this->headersFor($rep))->assertOk()->json('data.pos'));

        $target = $this->postJson('/api/v1/crm/targets', ['company_id' => $this->acme->id, 'subject_type' => 'user', 'subject_id' => $this->owner->id, 'metric' => 'pos_sales',
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'amount_minor' => '450000'], $headers)->assertCreated()->json('data');
        // Before tax (targets are net, stage 7D closing review): KES 2,000.00 of the KES 2,250.00 sold with 12.5 % test VAT.
        $this->assertSame(['200000', '44.4', true], [$target['actual_minor'], $target['percent'], $target['available']]);

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->assertNull($this->getJson("/api/v1/crm/customers/{$customer->id}/overview", $headers)->assertOk()->json('data.pos'));
        $this->assertSame([false, 'module_inactive'], [$this->getJson('/api/v1/crm/targets', $headers)->assertOk()->json('data.0.available'), $this->getJson('/api/v1/crm/targets', $headers)->json('data.0.unavailable')]);
    }
}
