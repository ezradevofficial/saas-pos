<?php

namespace App\Core\Identity\Services;

use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self sign-up (AUTH-01, ONB-01): the country and language come first and
 * set the company's currency, time zone and the tenant's language; a
 * tenant with its subdomain, one company, branch and location, and its
 * pending owner, in one transaction under the new tenant's context
 * (TenantProvisioner). The owner gets a 6-digit code to verify the contact.
 */
class SignUp
{
    /** Per-country company defaults (the country is chosen first, ONB-01). */
    public const COUNTRIES = TenantProvisioner::COUNTRIES;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Challenges $challenges,
        private readonly TenantProvisioner $provisioner,
    ) {}

    /**
     * @param  array{name: string, email?: ?string, phone?: ?string, password: string, country: string, locale: string, business_name: string, sandbox?: bool}  $data
     * @return array{user: User, challenge: VerificationChallenge}
     */
    public function handle(array $data): array
    {
        $tenantId = (string) Str::uuid7();
        $email = $data['email'] ?? null;
        $locale = $data['locale'];

        try {
            [$user, $challenge, $code] = $this->tenants->run($tenantId, fn () => DB::transaction(function () use ($tenantId, $data, $email, $locale) {
                ['tenant' => $tenant] = $this->provisioner->provision($tenantId, $data['business_name'], $data['country'], $locale, sandbox: (bool) ($data['sandbox'] ?? false));

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
