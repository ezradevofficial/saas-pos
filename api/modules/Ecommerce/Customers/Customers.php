<?php

namespace Modules\Ecommerce\Customers;

use App\Core\Identity\Support\PhoneNumber;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyRoles;
use App\Core\MasterData\Parties\PartyWriter;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Support\EcomError;

/**
 * ECOM-08, MD-01: online customers and their core party. A customer is
 * found by phone, else email, in the store's company, or created; its
 * party is the customer party with that phone (or email) the company
 * reaches, else a new person through the core party writer (the customer
 * role, the company's sharing mode, TEN-08). Names are stored as typed.
 */
final class Customers
{
    public function __construct(private readonly PartyWriter $parties) {}

    /** E.164 for a phone typed in the company's country (KE or CD), or null. */
    public static function phone(?string $input, Company $company): ?string
    {
        $input = trim((string) $input);

        return $input === '' ? null : PhoneNumber::normalise($input, $company->country);
    }

    public static function email(?string $input): ?string
    {
        $email = Str::lower(trim((string) $input));

        return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /** The customer of a contact (phone or email), creating it when $create. */
    public function forContact(Company $company, ?string $phone, ?string $email, ?string $name, bool $create = true): ?Customer
    {
        $customer = null;

        if ($phone !== null) {
            $customer = Customer::query()->where('company_id', $company->id)->where('phone', $phone)->first();
        }

        if ($customer === null && $email !== null) {
            $customer = Customer::query()->where('company_id', $company->id)->where('email', $email)->first();
        }

        if ($customer === null) {
            if (! $create) {
                return null;
            }

            return Customer::create([
                'company_id' => $company->id,
                'name' => mb_substr(trim((string) $name) ?: ($phone ?? $email ?? ''), 0, 160),
                'phone' => $phone,
                'email' => $email,
            ]);
        }

        $changes = array_filter([
            'phone' => $customer->phone === null && $phone !== null && ! Customer::query()->where('company_id', $company->id)->where('phone', $phone)->exists() ? $phone : null,
            'email' => $customer->email === null && $email !== null && ! Customer::query()->where('company_id', $company->id)->where('email', $email)->exists() ? $email : null,
        ]);

        if ($changes !== []) {
            $customer->fill($changes)->save();
        }

        return $customer;
    }

    /** MD-01: the customer's core party (customer role), linked once. */
    public function party(Customer $customer, Company $company): string
    {
        if ($customer->party_id !== null) {
            return $customer->party_id;
        }

        $perCompany = PartyRoles::perCompany(['customer'], app(MasterDataSharing::class));
        $reachable = fn ($q) => $perCompany ? $q->where('company_id', $company->id) : $q->whereNull('company_id');
        $party = null;

        if ($customer->phone !== null) {
            $party = Party::query()->tap($reachable)->whereNull('archived_at')->whereRaw("'customer' = any(roles)")
                ->whereRaw('phones @> ?::jsonb', [json_encode([['number' => $customer->phone]])])->orderBy('created_at')->first();
        }

        if ($party === null && $customer->email !== null) {
            $party = Party::query()->tap($reachable)->whereNull('archived_at')->whereRaw("'customer' = any(roles)")
                ->whereRaw('emails @> ?::jsonb', [json_encode([['address' => $customer->email]])])->orderBy('created_at')->first();
        }

        if ($party === null) {
            try {
                $party = $this->parties->create(array_filter([
                    'kind' => 'person',
                    'name' => $customer->name,
                    'company_id' => $perCompany ? $company->id : null,
                    'phones' => $customer->phone === null ? null : [['number' => $customer->phone, 'label' => null]],
                    'emails' => $customer->email === null ? null : [['address' => $customer->email, 'label' => null]],
                    'roles' => ['customer'],
                ], fn ($value) => $value !== null));
            } catch (\Throwable) {
                throw EcomError::make('customer_party_failed');
            }
        }

        $customer->forceFill(['party_id' => $party->id])->save();

        return $party->id;
    }
}
