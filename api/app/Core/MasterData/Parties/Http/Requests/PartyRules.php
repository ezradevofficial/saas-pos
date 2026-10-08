<?php

namespace App\Core\MasterData\Parties\Http\Requests;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Currency\Rules\MoneyAmount;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Support\PhoneNumber;
use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartyRoles;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MD-01 party validation and normalisation, shared by create and update.
 *
 * - `company_id` follows the sharing mode of the party's roles (TEN-08):
 *   required when any of them is per company, refused when all are shared.
 *   A role change that would set or clear it needs `company_id` in the
 *   request (null to share), else 422 `company_change_needs_confirmation`.
 * - Phones become E.164 (country of the party's company, else of the
 *   tenant's first company); emails lower case; tags lower case, once each;
 *   tax IDs upper case without spaces.
 * - A price list is active and of the party's company; for a shared party,
 *   of a company the user reaches with the party permission in use.
 * - The credit limit is typed in major units of its currency (ADR 003).
 *   Lowering it in its currency needs only the party permission; any
 *   other change also needs `core.credit_limit.set_directly`, else 422
 *   `credit_limit_needs_request` (ask through a credit limit change).
 */
final class PartyRules
{
    public const MAX_ENTRIES = 10;

    public const TAG_PATTERN = '/^[\pL\pN][\pL\pN _\-]{0,39}\z/u';

    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $activeCurrency = Rule::exists('tenant_currencies', 'code')->where('active', true);

        return [
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'kind' => [...$required, 'string', Rule::in(Party::KINDS)],
            'name' => [...$required, 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'phones' => ['sometimes', 'array', 'max:'.self::MAX_ENTRIES],
            'phones.*' => ['array:number,label'],
            'phones.*.number' => ['required', 'string', 'max:30'],
            'phones.*.label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'emails' => ['sometimes', 'array', 'max:'.self::MAX_ENTRIES],
            'emails.*' => ['array:address,label'],
            'emails.*.address' => ['required', 'string', 'email', 'max:254'],
            'emails.*.label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'addresses' => ['sometimes', 'array', 'max:'.self::MAX_ENTRIES],
            'addresses.*' => ['array:label,line1,line2,city,region,postal_code,country'],
            'addresses.*.label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'addresses.*.line1' => ['required', 'string', 'max:255'],
            'addresses.*.line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'addresses.*.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'addresses.*.region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'addresses.*.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'addresses.*.country' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}\z/'],
            'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}\z/', $activeCurrency],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'between:0,365'],
            'credit_limit' => ['sometimes', 'nullable', MoneyAmount::fromField('credit_limit_currency')->min('0')],
            'credit_limit_currency' => ['required_with:credit_limit', 'nullable', 'string', 'regex:/^[A-Z]{3}\z/', $activeCurrency],
            'price_list_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('price_lists', 'id')->whereNull('archived_at')],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'regex:'.self::TAG_PATTERN],
            'roles' => [...$required, 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(PartyRoles::ALL)],
        ];
    }

    /**
     * Cross-field checks once the fields are valid: the company against the
     * sharing mode and the user's reach, the price list, the phones.
     *
     * @param  string  $permission  core.party.create | core.party.edit
     */
    public static function validateParty(Validator $validator, array $input, ?Party $party, User $user, string $permission): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        // A role change that would share the party or tie it to a company
        // is explicit: the request names company_id (null to share).
        if ($party !== null && array_key_exists('roles', $input) && ! array_key_exists('company_id', $input)
            && PartyRoles::perCompany($input['roles'], app(MasterDataSharing::class)) !== ($party->company_id !== null)) {
            throw new ApiException(422, 'company_change_needs_confirmation', __('core.party.company_change_needs_confirmation'), [
                'company_id' => [__('core.party.company_change_needs_confirmation')],
            ]);
        }

        $companyId = self::companyId($input, $party);

        if ($companyId === false) {
            $validator->errors()->add('company_id', __('core.party.company_required'));

            return;
        }

        if ($companyId === null && ($input['company_id'] ?? null) !== null) {
            $validator->errors()->add('company_id', __('core.party.company_not_allowed'));

            return;
        }

        // WF-01: a raise (a first limit, removing it, another currency) goes
        // through a credit limit change request unless the user may set it directly.
        if (array_key_exists('credit_limit', $input)) {
            $limit = $input['credit_limit'] === null
                ? null
                : Money::parse((string) $input['credit_limit'], (string) $input['credit_limit_currency'], app(CurrencyDecimals::class));

            if (CreditLimitChanges::needsApproval($party?->creditLimit(), $limit) && ! app(CreditLimitChanges::class)->canSetDirectly($user, $companyId)) {
                throw CreditLimitChanges::needsRequestError();
            }
        }

        $reach = app(CompanyReach::class);

        if ($party !== null && $companyId !== null && $companyId !== $party->company_id && ! app(PartyPolicy::class)->editIn($user, $companyId)) {
            $validator->errors()->add('company_id', __('core.party.company_not_reached'));
        }

        $priceListId = array_key_exists('price_list_id', $input) ? $input['price_list_id'] : $party?->price_list_id;

        if ($priceListId !== null) {
            $listCompany = PriceList::query()->whereKey($priceListId)->value('company_id');
            $ok = $companyId !== null ? $listCompany === $companyId : $reach->reachesRecord($user, $listCompany, [$permission]);

            if (! $ok) {
                $validator->errors()->add('price_list_id', __('core.party.price_list_other_company'));
            }
        }

        foreach (array_values($input['phones'] ?? []) as $index => $phone) {
            if (PhoneNumber::normalise($phone['number'], self::country($companyId)) === null) {
                $validator->errors()->add("phones.{$index}.number", __('core.party.phone_invalid'));
            }
        }
    }

    /**
     * The party's company after the change: a string or null (shared), or
     * false when its roles need a company and none is given.
     */
    public static function companyId(array $input, ?Party $party): string|null|false
    {
        $roles = $input['roles'] ?? $party?->roles ?? [];

        if (! PartyRoles::perCompany($roles, app(MasterDataSharing::class))) {
            return null;
        }

        $companyId = array_key_exists('company_id', $input) ? $input['company_id'] : $party?->company_id;

        return $companyId ?? false;
    }

    /**
     * Model attributes from validated input, normalised (only the fields
     * given; company_id is set by the caller).
     */
    public static function attributes(array $data, ?string $companyId): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'kind', 'name', 'legal_name', 'currency', 'payment_terms_days', 'price_list_id',
        ]));

        if (array_key_exists('tax_id', $data)) {
            $taxId = $data['tax_id'] === null ? '' : Str::upper(preg_replace('/\s+/u', '', $data['tax_id']));
            $attributes['tax_id'] = $taxId === '' ? null : $taxId;
        }

        if (array_key_exists('phones', $data)) {
            $country = self::country($companyId);
            $attributes['phones'] = collect($data['phones'])
                ->map(fn (array $p) => ['number' => PhoneNumber::normalise($p['number'], $country), 'label' => $p['label'] ?? null])
                ->unique('number')->values()->all();
        }

        if (array_key_exists('emails', $data)) {
            $attributes['emails'] = collect($data['emails'])
                ->map(fn (array $e) => ['address' => Str::lower(trim($e['address'])), 'label' => $e['label'] ?? null])
                ->unique('address')->values()->all();
        }

        if (array_key_exists('addresses', $data)) {
            $keys = ['label', 'line1', 'line2', 'city', 'region', 'postal_code', 'country'];
            $attributes['addresses'] = array_map(
                fn (array $a) => array_merge(array_fill_keys($keys, null), array_intersect_key($a, array_flip($keys))),
                array_values($data['addresses']),
            );
        }

        if (array_key_exists('tags', $data)) {
            $attributes['tags'] = array_values(array_unique(array_map(fn (string $t) => Str::lower(trim($t)), $data['tags'])));
        }

        if (array_key_exists('roles', $data)) {
            $attributes['roles'] = array_values(array_intersect(PartyRoles::ALL, $data['roles']));
        }

        if (array_key_exists('credit_limit', $data)) {
            $money = $data['credit_limit'] === null
                ? null
                : Money::parse((string) $data['credit_limit'], $data['credit_limit_currency'], app(CurrencyDecimals::class));
            $attributes['credit_limit_minor'] = $money?->minor();
            $attributes['credit_limit_currency'] = $money?->currency();
        }

        return $attributes;
    }

    /** The country for local phone numbers: the party's company, else the tenant's first company. */
    public static function country(?string $companyId): ?string
    {
        if ($companyId !== null) {
            return Company::query()->whereKey($companyId)->value('country');
        }

        return Company::query()->orderBy('created_at')->orderBy('id')->value('country');
    }

    /** @return array<string, string> */
    public static function attributeNames(): array
    {
        return collect([
            'company_id' => 'company', 'kind' => 'kind', 'name' => 'name', 'legal_name' => 'legal_name',
            'tax_id' => 'tax_id', 'phones' => 'phones', 'phones.*.number' => 'phone', 'emails' => 'emails',
            'emails.*.address' => 'email', 'addresses' => 'addresses', 'addresses.*.line1' => 'address_line1',
            'currency' => 'currency', 'payment_terms_days' => 'payment_terms_days', 'credit_limit' => 'credit_limit',
            'credit_limit_currency' => 'credit_limit_currency', 'price_list_id' => 'price_list', 'tags' => 'tags',
            'tags.*' => 'tag', 'roles' => 'roles', 'roles.*' => 'role',
        ])->map(fn (string $key) => __("core.party.attributes.{$key}"))->all();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'currency.exists' => __('core.currency.not_active'),
            'credit_limit_currency.exists' => __('core.currency.not_active'),
            'tags.*.regex' => __('core.party.tag_invalid'),
        ];
    }
}
