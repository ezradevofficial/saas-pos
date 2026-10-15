<?php

namespace Modules\CRM\Tests\Concerns;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Currency\TenantCurrencies;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with CRM active (BuildsOrganisation's Acme, KE, base KES, USD
 * active at 1 USD = 130 KES): unit EA; a widget (tax category "Goods" →
 * the synthetic test VAT code at 16 %, never a real rate; KES 1,000.00 on
 * the default KES price list), a service with a list price at 16 %, a
 * "rate needed" gadget (its code has no rate) and an untaxed thing (no
 * tax category); a customer; a Branch Manager at branch A (the default
 * quote approver); a custom "Sales rep" role (CRM views, create and edit,
 * quotes with discounts within a 10 % limit, activities, targets view)
 * held by two reps at branch A and one at branch B; a team leader at A
 * with `crm.scope.team`. Time is fixed at 2026-10-15 09:00 UTC.
 */
trait BuildsCrm
{
    use BuildsExchangeRates, BuildsOrganisation;

    protected Uom $each;

    protected Item $widget;

    protected Item $gadget;

    protected Item $untaxed;

    protected Party $customer;

    protected TaxCode $vat;

    protected TaxCode $pending;

    protected PriceList $list;

    protected Role $repRole;

    protected User $manager;

    protected User $repA;

    protected User $repA2;

    protected User $repB;

    protected User $leader;

    public const REP_PERMISSIONS = [
        'crm.lead.view', 'crm.lead.create', 'crm.lead.edit', 'crm.lead.convert', 'crm.lead.archive',
        'crm.opportunity.view', 'crm.opportunity.create', 'crm.opportunity.edit', 'crm.opportunity.close',
        'crm.quote.view', 'crm.quote.create', 'crm.quote.discount', 'crm.quote.send', 'crm.quote.cancel',
        'crm.activity.view', 'crm.activity.manage', 'crm.target.view',
        'core.party.view', 'core.party.create', 'core.item.view',
    ];

    protected function setUpCrm(): void
    {
        Notification::fake();
        Mail::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15T09:00:00Z'));
        $this->setUpOrganisation();

        $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(ModuleRegistry::class)->activate('crm');

            $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $this->vat = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_T', 'name' => 'VAT test', 'kind' => 'vat']);
            TaxRate::create(['tax_code_id' => $this->vat->id, 'rate' => '16', 'effective_from' => '2026-01-01']);
            $this->pending = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_P', 'name' => 'VAT pending', 'kind' => 'vat']);
            $goods = TaxCategory::create(['name' => 'Goods']);
            $waiting = TaxCategory::create(['name' => 'Waiting']);
            TaxCategoryCode::create(['tax_category_id' => $goods->id, 'company_id' => $this->acme->id, 'tax_code_id' => $this->vat->id]);
            TaxCategoryCode::create(['tax_category_id' => $waiting->id, 'company_id' => $this->acme->id, 'tax_code_id' => $this->pending->id]);

            $this->widget = Item::create(['code' => 'WIDGET', 'name' => 'Widget', 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $goods->id]);
            $this->gadget = Item::create(['code' => 'GADGET', 'name' => 'Gadget', 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $waiting->id]);
            $this->untaxed = Item::create(['code' => 'THING', 'name' => 'Thing', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
            $this->list = PriceList::create(['company_id' => $this->acme->id, 'name' => 'Retail', 'currency' => 'KES', 'tax_inclusive' => false, 'is_default' => true]);

            foreach ([[$this->widget, '100000'], [$this->gadget, '50000'], [$this->untaxed, '1000']] as [$item, $minor]) {
                ItemPrice::create(['price_list_id' => $this->list->id, 'item_id' => $item->id, 'uom_id' => $this->each->id, 'amount_minor' => $minor, 'currency' => 'KES', 'effective_from' => '2026-01-01']);
            }

            $this->customer = Party::create(['kind' => 'organisation', 'name' => 'Duka Moja Ltd', 'roles' => ['customer'], 'company_id' => $this->acme->id,
                'emails' => [['address' => 'buyer@duka.example.com', 'label' => null]]]);
            $this->repRole = $this->role('Sales rep', self::REP_PERMISSIONS);
            LimitRule::create(['role_id' => $this->repRole->id, 'key' => 'max_discount_percent', 'value' => '10']);
        });

        $this->rate('USD', 'KES', '130', at: '2026-10-01T00:00:00Z');
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->repA = $this->rep(Scope::branch($this->branchA->id), 'Rep A');
        $this->repA2 = $this->rep(Scope::branch($this->branchA->id), 'Rep A two');
        $this->repB = $this->rep(Scope::branch($this->branchB->id), 'Rep B');
        $this->leader = $this->rep(Scope::branch($this->branchA->id), 'Leader A');
        $this->inTenant(function () {
            $role = $this->role('Team leader', [...self::REP_PERMISSIONS, 'crm.scope.team', 'crm.lead.assign']);
            $this->assign($this->leader, $role, Scope::branch($this->branchA->id));
        });
    }

    protected function rep(Scope $scope, string $name): User
    {
        return $this->inTenant(function () use ($scope, $name) {
            $user = $this->colleague($this->owner, ['name' => $name]);
            $this->assign($user, $this->repRole, $scope);

            return $user;
        });
    }

    protected function api(string $method, string $uri, array $body = [], ?User $as = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.ltrim($uri, '/'), $body, $this->headersFor($as ?? $this->owner));
    }

    /** A draft quote through the API (KES, branch A), as JSON. */
    protected function draftQuote(array $extra = [], ?array $lines = null, ?User $as = null): array
    {
        return $this->api('POST', 'crm/quotes', [
            'branch_id' => $this->branchA->id,
            'party_id' => $this->customer->id,
            'lines' => $lines ?? [['item_id' => $this->widget->id, 'qty' => '2']],
            ...$extra,
        ], $as ?? $this->repA)->assertCreated()->json('data');
    }

    /** Approve the quote's running flow as the branch manager (APR-07: never the requester). */
    protected function approveFlow(string $type, string $documentId, ?User $as = null): void
    {
        $approval = $this->inTenant(function () use ($type, $documentId) {
            $workflow = app(WorkflowEngine::class)->current($type, $documentId);
            $this->assertSame(DocumentWorkflow::RUNNING, $workflow?->status, 'the flow is running');

            return ApprovalRequest::query()->where('document_id', $documentId)->sole()->id;
        });

        $this->api('POST', "approvals/{$approval}/approve", [], $as ?? $this->manager)->assertOk();
    }

    /** A quote drafted, submitted (approved at once: no threshold) and accepted. */
    protected function acceptedQuote(array $extra = [], ?array $lines = null): array
    {
        $quote = $this->draftQuote($extra, $lines);
        $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk();
        $this->assertSame('approved', $this->api('GET', "crm/quotes/{$quote['id']}", [], $this->repA)->json('data.status'));

        return $this->api('POST', "crm/quotes/{$quote['id']}/accept", [], $this->repA)->assertOk()->json('data');
    }
}
