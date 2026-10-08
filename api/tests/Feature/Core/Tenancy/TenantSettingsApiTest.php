<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Services\PasswordPolicy;
use App\Core\Identity\Services\SessionTimeout;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-02 password minimum, AUTH-09 session timeout, L10N-01 default
// language: the tenant's settings, `core.settings.edit` at tenant scope,
// audited as core.settings.update (AUD-01).
class TenantSettingsApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_the_owner_reads_the_defaults(): void
    {
        $this->getJson('/api/v1/tenant/settings', $this->headersFor())
            ->assertOk()
            ->assertExactJson(['data' => [
                'password_min_length' => 8,
                'session_timeout_minutes' => 60,
                'default_locale' => 'en',
            ]]);
    }

    public function test_the_owner_changes_the_settings_and_the_change_is_audited_and_applied(): void
    {
        $this->patchJson('/api/v1/tenant/settings', [
            'password_min_length' => 12,
            'session_timeout_minutes' => 30,
            'default_locale' => 'fr',
        ], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.password_min_length', 12)
            ->assertJsonPath('data.session_timeout_minutes', 30)
            ->assertJsonPath('data.default_locale', 'fr');

        // Only what changes: no entry for an identical save.
        $this->patchJson('/api/v1/tenant/settings', ['session_timeout_minutes' => 30], $this->headersFor())->assertOk();

        $this->inTenant(function () {
            $tenant = Tenant::findOrFail($this->owner->tenant_id);
            $this->assertSame(12, PasswordPolicy::minLength($tenant));
            $this->assertSame(30, SessionTimeout::minutes($tenant));
            $this->assertSame('fr', $tenant->default_locale);

            $entry = AuditEntry::where('action', 'core.settings.update')->sole();
            $this->assertSame($this->owner->id, $entry->user_id);
            $this->assertSame($tenant->id, $entry->auditable_id);
            $this->assertEquals(['password_min_length' => 8, 'session_timeout_minutes' => 60, 'default_locale' => 'en'], $entry->before);
            $this->assertEquals(['password_min_length' => 12, 'session_timeout_minutes' => 30, 'default_locale' => 'fr'], $entry->after);
        });

        // AUTH-02: a new password now needs 12 characters.
        $this->patchJson('/api/v1/tenant/settings', ['password_min_length' => 9], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.session_timeout_minutes', 30);
        $this->inTenant(fn () => $this->assertSame(
            ['password_min_length' => 12],
            AuditEntry::where('action', 'core.settings.update')->orderByDesc('seq')->first()->before,
        ));
    }

    public function test_values_out_of_range_are_refused(): void
    {
        foreach ([
            ['password_min_length' => 7],
            ['password_min_length' => 65],
            ['password_min_length' => 'twelve'],
            ['session_timeout_minutes' => 14],
            ['session_timeout_minutes' => 481],
            ['session_timeout_minutes' => 30.5],
            ['default_locale' => 'de'],
            ['default_locale' => null],
        ] as $input) {
            $this->patchJson('/api/v1/tenant/settings', $input, $this->headersFor())
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($input));
        }

        $this->patchJson('/api/v1/tenant/settings', ['password_min_length' => 64, 'session_timeout_minutes' => 480], $this->headersFor())->assertOk();
        $this->patchJson('/api/v1/tenant/settings', ['password_min_length' => 8, 'session_timeout_minutes' => 15], $this->headersFor())->assertOk();
    }

    public function test_messages_name_the_field_in_french(): void
    {
        $this->patchJson('/api/v1/tenant/settings', ['session_timeout_minutes' => 5], $this->headersFor() + ['Accept-Language' => 'fr'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.session_timeout_minutes.0', fn (string $message) => str_contains($message, 'délai d’expiration de session'));
    }

    public function test_a_branch_or_company_scoped_holder_is_refused(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $companyAdmin = $this->userWith('admin', Scope::company($this->acme->id));

        foreach ([$manager, $companyAdmin] as $user) {
            $this->getJson('/api/v1/tenant/settings', $this->headersFor($user))->assertForbidden();
            $this->patchJson('/api/v1/tenant/settings', ['session_timeout_minutes' => 30], $this->headersFor($user))->assertForbidden();
        }

        $this->inTenant(fn () => $this->assertSame(60, SessionTimeout::minutes(Tenant::findOrFail($this->owner->tenant_id))));
    }

    public function test_a_tenant_wide_admin_may_edit(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());

        $this->patchJson('/api/v1/tenant/settings', ['default_locale' => 'fr'], $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'fr');
    }

    public function test_each_tenant_changes_only_its_own_settings(): void
    {
        $other = $this->otherTenant();

        $this->patchJson('/api/v1/tenant/settings', ['password_min_length' => 20], $this->headersFor($other['user']))->assertOk();

        $this->getJson('/api/v1/tenant/settings', $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.password_min_length', 8);
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/tenant/settings')->assertUnauthorized();
        $this->patchJson('/api/v1/tenant/settings', ['password_min_length' => 10])->assertUnauthorized();
    }
}
