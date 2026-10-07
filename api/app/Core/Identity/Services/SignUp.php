<?php

namespace App\Core\Identity\Services;

use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self sign-up (AUTH-01, ONB-): a tenant with one company, branch and
 * location, and its pending owner, in one transaction under the new
 * tenant's context. The owner gets a 6-digit code to verify the contact.
 */
class SignUp
{
    /** Per-country company defaults. */
    public const COUNTRIES = [
        'KE' => ['currency' => 'KES', 'timezone' => 'Africa/Nairobi'],
        'CD' => ['currency' => 'USD', 'timezone' => 'Africa/Kinshasa'],
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Challenges $challenges,
    ) {}

    /**
     * @param  array{name: string, email?: ?string, phone?: ?string, password: string, country: string, locale: string, business_name: string}  $data
     * @return array{user: User, challenge: VerificationChallenge}
     */
    public function handle(array $data): array
    {
        $tenantId = (string) Str::uuid7();
        $email = $data['email'] ?? null;
        $locale = $data['locale'];
        $country = self::COUNTRIES[$data['country']];

        try {
            [$user, $challenge, $code] = $this->tenants->run($tenantId, fn () => DB::transaction(function () use ($tenantId, $data, $email, $locale, $country) {
                $tenant = Tenant::provision([
                    'id' => $tenantId,
                    'name' => $data['business_name'],
                    'default_locale' => $locale,
                ]);

                $company = Company::create([
                    'name' => $data['business_name'],
                    'legal_name' => $data['business_name'],
                    'country' => $data['country'],
                    'base_currency' => $country['currency'],
                    'fiscal_year_start_month' => 1,
                    'timezone' => $country['timezone'],
                ]);

                $branch = $company->branches()->create([
                    'name' => __('core.defaults.branch', [], $locale),
                    'code' => 'MAIN',
                ]);

                $branch->locations()->create([
                    'name' => __('core.defaults.location', [], $locale),
                    'type' => 'outlet',
                ]);

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $email === null ? $data['phone'] : null,
                    'password' => $data['password'],
                    'locale' => $locale,
                    'status' => User::STATUS_PENDING,
                ]);

                event(new TenantProvisioned($tenant, $user));

                [$channel, $destination] = $email !== null ? ['email', $email] : ['sms', $user->phone];
                [$challenge, $code] = $this->challenges->create($user, VerificationChallenge::PURPOSE_VERIFY_CONTACT, $channel, $destination);

                return [$user, $challenge, $code];
            }));
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another sign-up for the same login.
            $field = $email !== null ? 'email' : 'phone';

            throw ValidationException::withMessages([$field => __('validation.unique', ['attribute' => $field])]);
        }

        // Sent after commit: a delivery failure never undoes the sign-up.
        $this->challenges->send($user, $challenge, $code);

        return ['user' => $user, 'challenge' => $challenge];
    }
}
