<?php

namespace Tests\Feature\Core\Sso;

use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-11 (Phase 8 review L3): starting single sign-on is limited per
// address at an office-sized rate; the callback, ACS and completion are
// limited per flow (state, relay state or ticket), so many people signing
// in from one address never hold each other back.
class SsoRateLimitsTest extends TestCase
{
    use RefreshTenantDatabase;

    private function complete(string $ticket)
    {
        return $this->postJson('/api/v1/auth/sso/complete', ['ticket' => $ticket, 'binding' => Str::random(43)]);
    }

    public function test_starts_are_limited_per_address_at_an_office_sized_rate(): void
    {
        foreach (range(1, 40) as $i) {
            $this->assertNotSame(429, $this->postJson('/api/v1/auth/sso/start', ['email' => "person{$i}@unknown.example"])->status());
        }

        // Still limited per address.
        config(['sso.start_per_minute' => 41]);
        $this->assertNotSame(429, $this->postJson('/api/v1/auth/sso/start', ['email' => 'one-more@unknown.example'])->status());
        $this->postJson('/api/v1/auth/sso/start', ['email' => 'too-many@unknown.example'])->assertStatus(429);
    }

    public function test_callbacks_and_completions_are_limited_per_flow_not_per_address(): void
    {
        // Thirty people completing from one address: none is refused.
        foreach (range(1, 30) as $i) {
            $this->assertNotSame(429, $this->complete(Str::random(48))->status());
            $this->assertNotSame(429, $this->getJson('/api/v1/auth/sso/oidc/callback?state='.Str::random(43).'&code=x')->status());
        }

        // One flow replayed is limited on its own.
        $ticket = Str::random(48);
        foreach (range(1, 10) as $i) {
            $this->assertNotSame(429, $this->complete($ticket)->status());
        }
        $this->complete($ticket)->assertStatus(429);
        $this->assertNotSame(429, $this->complete(Str::random(48))->status());

        // Values that name no flow share the address's ceiling.
        config(['sso.callback_per_minute_per_ip' => 3]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40']);
        foreach (range(1, 3) as $i) {
            $this->assertNotSame(429, $this->complete(Str::random(48))->status());
        }
        $this->complete(Str::random(48))->assertStatus(429);
    }
}
