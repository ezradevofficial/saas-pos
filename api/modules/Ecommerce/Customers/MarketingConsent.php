<?php

namespace Modules\Ecommerce\Customers;

use App\Core\Notifications\EventType;
use App\Core\Privacy\Consents;
use App\Core\Tenancy\Models\Company;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-08, NFR-08 (KE DPA): a storefront customer's marketing consent,
 * recorded through the core consent service (source `storefront`) on the
 * customer's party, for every channel (`any`), with the version of the
 * wording the shop showed (config privacy.wording_versions) and the
 * evidence of where it was given. The box is unticked by default; an
 * unticked box at sign-up records nothing (no record means no consent),
 * so it never withdraws a consent given elsewhere (at the till). The
 * account page grants or withdraws it later.
 *
 * Consents is the source of truth; `ecom_customers.consent` is not read.
 */
final class MarketingConsent
{
    public const WORDING = 'storefront_marketing';

    public function __construct(private readonly Customers $customers, private readonly Consents $consents) {}

    /** The wording versions a storefront may show (the current one first). @return list<string> */
    public static function wordingVersions(): array
    {
        return array_values((array) config('privacy.wording_versions.'.self::WORDING, []));
    }

    /**
     * At sign-up (a verified code), the customer ticked the box: a grant
     * on every channel, unless they already consent. Creates the
     * customer's party when it has none yet (consent belongs to the party).
     */
    public function signUp(Store $store, Customer $customer, string $wordingVersion, string $challengeId): void
    {
        $this->grant($store, $customer, $wordingVersion, "Ticked the marketing box when signing in to the storefront {$store->handle} with code {$challengeId}, in ".app()->getLocale().'.');
    }

    /** From the account page: grant (ticked, with the wording shown) or withdraw on every channel. */
    public function set(Store $store, Customer $customer, bool $granted, ?string $wordingVersion): bool
    {
        if ($granted) {
            $this->grant($store, $customer, (string) $wordingVersion, "Turned marketing messages on in the storefront {$store->handle} account, in ".app()->getLocale().'.');

            return true;
        }

        if ($customer->party_id !== null && $this->current($customer)) {
            $this->consents->record(null, 'customer', $customer->party_id, Consents::MARKETING, 'any', false, 'storefront',
                "Turned marketing messages off in the storefront {$store->handle} account, in ".app()->getLocale().'.');
        }

        return false;
    }

    /** Whether the customer may get marketing messages on at least one channel now. */
    public function current(Customer $customer): bool
    {
        if ($customer->party_id === null) {
            return false;
        }

        foreach (EventType::MARKETING_CHANNELS as $channel) {
            if ($this->consents->allows('customer', $customer->party_id, Consents::MARKETING, $channel)) {
                return true;
            }
        }

        return false;
    }

    private function grant(Store $store, Customer $customer, string $wordingVersion, string $evidence): void
    {
        Tx::run(function () use ($store, $customer, $wordingVersion, $evidence) {
            $party = $this->customers->party($customer, Company::query()->findOrFail($store->company_id));

            // Already consenting on every channel: nothing new to record.
            $missing = array_filter(EventType::MARKETING_CHANNELS, fn (string $channel) => ! $this->consents->allows('customer', $party, Consents::MARKETING, $channel));

            if ($missing === []) {
                return;
            }

            $this->consents->record(null, 'customer', $party, Consents::MARKETING, 'any', true, 'storefront', $evidence, $wordingVersion);
        });
    }
}
