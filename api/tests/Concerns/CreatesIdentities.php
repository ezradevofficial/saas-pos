<?php

namespace Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait CreatesIdentities
{
    protected string $password = 'violet-harbour-42';

    /** An active, verified user in a new tenant. */
    protected function createUser(array $attributes = [], array $tenant = []): User
    {
        $model = Tenant::provision(array_merge(['name' => 'Acme '.Str::random(4)], $tenant));

        return $this->asTenant($model->id, fn () => User::create(array_merge([
            'tenant_id' => $model->id,
            'name' => 'Owner',
            'email' => 'owner-'.Str::lower(Str::random(8)).'@example.com',
            'password' => $this->password,
            'status' => 'active',
            'email_verified_at' => now(),
            'locale' => 'en',
        ], $attributes)));
    }

    protected function asTenant(string $tenantId, callable $fn): mixed
    {
        return app(TenantContext::class)->run($tenantId, $fn);
    }

    protected function signIn(string $login, ?string $password = null, array $headers = [], array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/sign-in', array_merge([
            'login' => $login,
            'password' => $password ?? $this->password,
        ], $extra), $headers);
    }

    protected function tokenFor(User $user, array $headers = [], array $extra = []): string
    {
        return $this->signIn($user->email ?? $user->phone, null, $headers, $extra)->assertOk()->json('token');
    }

    protected function bearer(string $token, array $headers = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$token], $headers);
    }

    /** The plain code of the last VerificationCode notification sent (Notification::fake()). */
    protected function lastCode(): string
    {
        $codes = [];

        foreach (Notification::sentNotifications() as $byId) {
            foreach ($byId as $byClass) {
                foreach ($byClass[VerificationCode::class] ?? [] as $sent) {
                    $codes[] = $sent['notification'];
                }
            }
        }

        $this->assertNotEmpty($codes, 'No verification code was sent.');

        return end($codes)->code;
    }
}
