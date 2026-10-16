<?php

namespace Modules\CRM\Overview;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Modules\Accounting\Contracts\CustomerReceivables;
use Modules\CRM\Http\Resources\ActivityResource;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Http\Resources\QuoteResource;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Quote;
use Modules\CRM\Support\CrmAccess;
use Modules\Ecommerce\Contracts\CustomerOrders;
use Modules\Loyalty\Contracts\CustomerBalances;
use Modules\POS\Contracts\CustomerSales;

/**
 * CRM-25: the customer 360 view. The CRM part is what the reader sees of
 * the customer (CRM-08); each other module's part comes only from that
 * module's read contract (architecture rule 1), present while the module
 * is active and the reader holds its permission, else null.
 */
class CustomerOverview
{
    private const RECENT = 10;

    public function __construct(private readonly CrmAccess $access) {}

    /** @return array<string, mixed> */
    public function for(Party $party, User $user, Request $request): array
    {
        $open = $this->access->visible(Opportunity::query(), $user, 'opportunity')->where('party_id', $party->id)->whereNull('archived_at');
        $openValues = (clone $open)->where('status', Opportunity::OPEN)->groupBy('currency')->orderBy('currency')
            ->selectRaw('currency, sum(value_minor) as amount')->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'amount_minor' => (string) $row->amount])->values()->all();
        $activities = $this->access->visible(Activity::query(), $user, 'activity')->where('party_id', $party->id)->with(ActivityResource::RELATIONS);
        $now = CarbonImmutable::now();

        return [
            'party' => ['id' => $party->id, 'name' => $party->name],
            'crm' => [
                // CRM-09: none for a reader whose field rules hide opportunity values.
                'open_value' => $this->access->hidesValues($user) ? null : $openValues,
                'opportunities' => OpportunityResource::collection((clone $open)->with(OpportunityResource::RELATIONS)->orderByRaw("status = 'open' desc")->orderByDesc('created_at')->limit(self::RECENT)->get())->resolve($request),
                'quotes' => QuoteResource::collection($this->access->visible(Quote::query(), $user, 'quote')->where('party_id', $party->id)->with(QuoteResource::RELATIONS)
                    ->orderByDesc('created_at')->limit(self::RECENT)->get())->resolve($request),
                'activities' => [
                    'upcoming' => ActivityResource::collection((clone $activities)->where('status', Activity::OPEN)->where('starts_at', '>=', $now->subDays(30))->orderBy('starts_at')->limit(self::RECENT)->get())->resolve($request),
                    'recent' => ActivityResource::collection((clone $activities)->where('status', Activity::DONE)->orderByDesc('done_at')->limit(self::RECENT)->get())->resolve($request),
                ],
                'leads' => LeadResource::collection($this->access->visible(Lead::query(), $user, 'lead')->where('party_id', $party->id)->with(LeadResource::RELATIONS)
                    ->orderByDesc('converted_at')->limit(self::RECENT)->get())->resolve($request),
            ],
            'pos' => class_exists(CustomerSales::class) ? app(CustomerSales::class)->forCustomer($user, $party->id, self::RECENT) : null,
            'loyalty' => class_exists(CustomerBalances::class) ? app(CustomerBalances::class)->forCustomer($user, $party->id) : null,
            'accounting' => class_exists(CustomerReceivables::class) ? app(CustomerReceivables::class)->forCustomer($user, $party->id, self::RECENT) : null,
            // ECOM-34: online orders (docs/modules/ecommerce.md), through E-commerce's read contract.
            'ecommerce' => class_exists(CustomerOrders::class) ? app(CustomerOrders::class)->forCustomer($user, $party->id, self::RECENT) : null,
        ];
    }
}
