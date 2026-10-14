<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\Loyalty\Http\Lists\AccountList;
use Modules\Loyalty\Http\Lists\GiftCardList;
use Modules\Loyalty\Http\Lists\PromotionList;
use Modules\Loyalty\Http\Lists\SaleList;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Models\Promotion;

/**
 * The back-office lists (EXP-01, RBAC-04): GET loyalty/promotions,
 * loyalty/gift-cards, loyalty/accounts, loyalty/ageing and loyalty/sales (H2: flagged
 * sales to review, `?flag=`, `?flagged=0` for all). Rows of the
 * companies where the user holds the view permission; `?status=` (or
 * `all`), `?company=`, `?search=`, `?sort=`, pages of `?per_page`, an export;
 * the ageing report also takes `?as_of=` (a day, the end of it).
 */
class ListLoyaltyRequest extends LoyaltyRequest
{
    use SortsAndExports;

    /** list => [view permission, statuses, list definition] */
    private const LISTS = [
        'promotions' => ['loyalty.promotion.view', Promotion::STATUSES],
        'gift-cards' => ['loyalty.gift_card.view', GiftCard::STATUSES],
        'accounts' => ['loyalty.credit.view', [Account::OPEN, Account::CLOSED]],
        'ageing' => ['loyalty.credit.view', [Account::OPEN, Account::CLOSED]],
        'sales' => ['loyalty.sale.view', [LoyaltySale::PENDING, LoyaltySale::PROCESSED, LoyaltySale::FAILED]],
    ];

    public function kind(): string
    {
        return (string) preg_replace('#^api/v1/loyalty/#', '', $this->route()->uri());
    }

    public function list(): ListDefinition
    {
        return match ($this->kind()) {
            'promotions' => new PromotionList,
            'gift-cards' => new GiftCardList,
            'ageing' => new AccountList(ageing: true),
            'sales' => new SaleList,
            default => new AccountList,
        };
    }

    public function permission(): string
    {
        return self::LISTS[$this->kind()][0];
    }

    public function authorize(): bool
    {
        $this->authorizeAnywhere($this->permission());

        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...self::LISTS[$this->kind()][1], 'all'])],
            // Under RLS: another tenant's company does not exist.
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            // H2: the review list of sales (flagged ones unless `flagged=0`), by one flag code.
            'flag' => ['sometimes', 'string', 'regex:/^[a-z_]{1,40}$/'],
            'flagged' => ['sometimes', 'in:0,1'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** $query limited to the user's companies and the filters. */
    public function applyFilters(Builder $query): Builder
    {
        $this->access()->scope($query, $this->user(), $this->permission());

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        if ($this->filled('status') && $this->validated('status') !== 'all') {
            $query->where($query->qualifyColumn('status'), $this->validated('status'));
        }

        return $query;
    }

    /** The end of the as-of day (UTC; the report's days are close enough to the companies' zones). */
    public function asOf(): CarbonImmutable
    {
        return $this->filled('as_of') ? CarbonImmutable::parse($this->validated('as_of'))->endOfDay() : CarbonImmutable::now();
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
