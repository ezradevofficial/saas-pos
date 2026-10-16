<?php

namespace Modules\Ecommerce\Customers;

use App\Core\Http\ApiErrorRenderer;
use App\Core\Http\ApiException;
use App\Core\Identity\Support\PhoneNumber;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Models\CustomerCode;
use Modules\Ecommerce\Models\CustomerSession;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-08: customers sign in with a one-time code, with core's OTP rules
 * (AUTH-01, AUTH-10; decision 3): 6 digits, valid 30 minutes, single use,
 * 5 attempts, at most 5 codes an hour and 60 seconds between codes per
 * contact and store, kept only as an HMAC bound to the code's id. Asking
 * for a code answers the same whether or not the contact is a customer
 * (the customer is created on the first sign-in). A session is
 * `ecs_` + 48 characters, stored as sha256, 30 days, extended on use,
 * revocable.
 */
final class CustomerAuth
{
    public const TTL_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS_PER_HOUR = 5;

    public const COOLDOWN_SECONDS = 60;

    public const SESSION_DAYS = 30;

    public const PREFIX = 'ecs_';

    public function __construct(private readonly Customers $customers) {}

    /** @return array{challenge_id: string, channel: string, destination: string} */
    public function start(Store $store, string $contact): array
    {
        $company = Company::query()->findOrFail($store->company_id);
        [$channel, $to] = $this->contact($contact, $company);

        return Tx::run(function () use ($store, $channel, $to) {
            Tx::lock("ecom:codes:{$store->id}:{$to}");
            $recent = CustomerCode::query()->where('store_id', $store->id)->where('contact', $to)->where('created_at', '>=', CarbonImmutable::now()->subHour())->orderByDesc('created_at')->get();
            $last = $recent->first();

            if ($last !== null && $last->created_at->greaterThan(CarbonImmutable::now()->subSeconds(self::COOLDOWN_SECONDS))) {
                throw self::tooMany(self::COOLDOWN_SECONDS - (int) $last->created_at->diffInSeconds(CarbonImmutable::now()));
            }

            if ($recent->count() >= self::MAX_SENDS_PER_HOUR) {
                throw self::tooMany((int) CarbonImmutable::now()->diffInSeconds($recent->last()->created_at->addHour()));
            }

            CustomerCode::query()->where('store_id', $store->id)->where('contact', $to)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            $id = (string) Str::uuid7();
            CustomerCode::create([
                'id' => $id, 'store_id' => $store->id, 'contact' => $to, 'channel' => $channel, 'code_hash' => self::hash($id, $code),
                'expires_at' => CarbonImmutable::now()->addMinutes(self::TTL_MINUTES),
            ]);

            rescue(fn () => Notification::route($channel === 'sms' ? 'sms' : 'mail', $to)
                ->notify(new CustomerCodeNotification($code, $channel, $store->name, self::TTL_MINUTES)), report: true);

            return ['challenge_id' => $id, 'channel' => $channel, 'destination' => $channel === 'sms' ? PhoneNumber::mask($to) : self::maskEmail($to)];
        });
    }

    /** @return array{token: string, customer: Customer} */
    public function verify(Store $store, string $challengeId, string $code): array
    {
        $company = Company::query()->findOrFail($store->company_id);

        $result = Tx::run(function () use ($store, $company, $challengeId, $code) {
            $challenge = Str::isUuid($challengeId)
                ? CustomerCode::query()->where('store_id', $store->id)->whereKey($challengeId)->lockForUpdate()->first()
                : null;

            if ($challenge === null || $challenge->consumed_at !== null || $challenge->expires_at->isPast() || $challenge->attempts >= self::MAX_ATTEMPTS) {
                throw EcomError::make('code_invalid', field: 'code');
            }

            if (! hash_equals($challenge->code_hash, self::hash($challenge->id, $code))) {
                $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();

                // Committed before the refusal: the attempt counts.
                return null;
            }

            $challenge->forceFill(['consumed_at' => CarbonImmutable::now()])->save();
            $customer = $this->customers->forContact($company, $challenge->channel === 'sms' ? $challenge->contact : null, $challenge->channel === 'email' ? $challenge->contact : null, null);
            $customer->forceFill(['last_signed_in_at' => CarbonImmutable::now()])->save();
            $token = self::PREFIX.Str::random(48);
            CustomerSession::create([
                'customer_id' => $customer->id, 'store_id' => $store->id, 'token_hash' => hash('sha256', $token),
                'last_used_at' => CarbonImmutable::now(), 'expires_at' => CarbonImmutable::now()->addDays(self::SESSION_DAYS),
            ]);

            return ['token' => $token, 'customer' => $customer];
        });

        return $result ?? throw EcomError::make('code_invalid', field: 'code');
    }

    /** The live session of a bearer token in this store, or null. */
    public function resolve(Store $store, string $token): ?CustomerSession
    {
        if (! str_starts_with($token, self::PREFIX) || strlen($token) !== strlen(self::PREFIX) + 48) {
            return null;
        }

        $session = CustomerSession::query()->where('token_hash', hash('sha256', $token))->where('store_id', $store->id)
            ->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now())->with('customer')->first();

        if ($session === null || $session->customer === null || $session->customer->archived_at !== null) {
            return null;
        }

        if ($session->last_used_at === null || $session->last_used_at->lessThan(CarbonImmutable::now()->subMinutes(5))) {
            $session->forceFill(['last_used_at' => CarbonImmutable::now(), 'expires_at' => CarbonImmutable::now()->addDays(self::SESSION_DAYS)])->save();
        }

        return $session;
    }

    public function signOut(CustomerSession $session): void
    {
        $session->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
    }

    /** @return array{0: string, 1: string} the channel and the normalised contact */
    private function contact(string $contact, Company $company): array
    {
        if (str_contains($contact, '@')) {
            $email = Customers::email($contact);

            return $email === null ? throw EcomError::make('contact_invalid', field: 'contact') : ['email', $email];
        }

        $phone = Customers::phone($contact, $company);

        return $phone === null ? throw EcomError::make('contact_invalid', field: 'contact') : ['sms', $phone];
    }

    private static function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('*', max(1, mb_strlen($name) - 1)).'@'.$domain;
    }

    private static function tooMany(int $seconds): ApiException
    {
        $seconds = max(1, $seconds);

        return new ApiException(429, 'too_many_requests', ApiErrorRenderer::retryMessage($seconds), headers: ['Retry-After' => $seconds]);
    }
}
