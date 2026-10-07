<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Audit\AuditEntry;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// L10N-01 on authenticated requests; AUD-02 user attribution.
class MeTest extends TestCase
{
    use CreatesIdentities, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_patch_me_updates_name_and_locale_and_audits_the_user(): void
    {
        $user = $this->createUser();
        $token = $this->tokenFor($user);

        $this->patchJson('/api/v1/me', ['name' => 'Amina O.', 'locale' => 'fr'], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.name', 'Amina O.')
            ->assertJsonPath('data.locale', 'fr');

        $entry = $this->asTenant($user->tenant_id, fn () => AuditEntry::where('action', 'core.user.update')->sole());
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(['locale' => 'fr', 'name' => 'Amina O.'], collect($entry->after)->sortKeys()->all());
    }

    public function test_the_audit_log_never_holds_the_password(): void
    {
        $user = $this->createUser();

        $entry = $this->asTenant($user->tenant_id, fn () => AuditEntry::where('action', 'core.user.create')->sole());
        $this->assertArrayNotHasKey('password', $entry->after);
        $this->assertArrayNotHasKey('two_factor_secret', $entry->after);
    }

    public function test_the_tenant_default_locale_applies_to_authenticated_requests(): void
    {
        $user = $this->createUser([], ['default_locale' => 'fr']);
        $token = $this->tokenFor($user);

        // The test client sends `Accept-Language: en-us,en;q=0.5` unless told otherwise.
        $this->patchJson('/api/v1/me', ['locale' => 'de'], $this->bearer($token, ['Accept-Language' => '']))
            ->assertStatus(422)
            ->assertJsonPath('message', __('core.errors.validation_failed', [], 'fr'))
            ->assertJsonPath('errors.locale.0', __('validation.in', ['attribute' => 'locale'], 'fr'));
    }

    public function test_accept_language_beats_the_tenant_default_when_authenticated(): void
    {
        $user = $this->createUser([], ['default_locale' => 'fr']);
        $token = $this->tokenFor($user);

        $this->patchJson('/api/v1/me', ['locale' => 'de'], $this->bearer($token, ['Accept-Language' => 'en']))
            ->assertStatus(422)
            ->assertJsonPath('errors.locale.0', __('validation.in', ['attribute' => 'locale'], 'en'));
    }

    public function test_an_unauthenticated_error_follows_accept_language(): void
    {
        $this->getJson('/api/v1/me', ['Accept-Language' => 'fr'])
            ->assertUnauthorized()
            ->assertJsonPath('message', __('core.errors.unauthenticated', [], 'fr'));
    }
}
