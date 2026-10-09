<?php

namespace Tests\Feature\Core\Branding;

use App\Core\Audit\AuditEntry;
use App\Core\Branding\BrandedSender;
use App\Core\Branding\Domains\DnsResolver;
use App\Core\Branding\Models\TenantDomain;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\Support\Branding\FakeDnsResolver;
use Tests\TestCase;

/**
 * BR-05, BR-06, TEN-01, RBAC-04, AUD-01: custom domains verified by a DNS
 * TXT record (a fake resolver, never the network), the scheduled check
 * through a security-definer function, the TLS ask endpoint, the branded
 * email sender, permissions and other tenants.
 */
class DomainApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase, WithoutOwnerConnection;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->setUpOrganisation();
    }

    private function add(string $host, ?array $headers = null): array
    {
        return $this->postJson('/api/v1/branding/domains', ['host' => $host], $headers ?? $this->headersFor())->assertCreated()->json('data');
    }

    public function test_a_domain_is_verified_by_its_txt_record_through_the_scheduled_check(): void
    {
        $domain = $this->add(' ERP.Company.co.ke. ');
        $this->assertSame(['erp.company.co.ke', 'pending', 'TXT', '_platform-verify.erp.company.co.ke'], [$domain['host'], $domain['status'], $domain['record']['type'], $domain['record']['name']]);
        $this->assertStringStartsWith('platform-verify=', $domain['record']['value']);

        // Not there yet: still pending after a check.
        $this->postJson("/api/v1/branding/domains/{$domain['id']}/check", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.failure', 'record_missing');
        $this->get('/api/v1/tls/ask?domain=erp.company.co.ke')->assertNotFound();

        // The owner creates the record; the scheduler finds the tenant without the owner connection.
        $this->dns->publish($domain['record']['name'], 'v=spf1 -all');
        $this->dns->publish($domain['record']['name'], $domain['record']['value']);
        $this->withoutOwnerConnection();
        $this->artisan('domains:verify')->assertSuccessful();

        $this->getJson('/api/v1/branding/domains', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.status', 'verified')->assertJsonPath('data.0.failure', null);
        $this->get('/api/v1/tls/ask?domain=erp.company.co.ke')->assertOk();
        $this->get('/api/v1/tls/ask?domain=ERP.company.co.ke')->assertOk();
        $this->get('/api/v1/tls/ask?domain=other.company.co.ke')->assertNotFound();
        $this->get('/api/v1/tls/ask')->assertNotFound();

        $actions = $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $domain['id'])->orderBy('seq')->pluck('action')->all());
        $this->assertSame(['core.domain.add', 'core.domain.verify'], $actions);

        // Archived: TLS stops at once, and the host is free again.
        $this->postJson("/api/v1/branding/domains/{$domain['id']}/archive", [], $this->headersFor())->assertOk();
        $this->get('/api/v1/tls/ask?domain=erp.company.co.ke')->assertNotFound();
        $this->getJson('/api/v1/branding/domains', $this->headersFor())->assertJsonPath('data', []);
    }

    public function test_an_unproven_domain_fails_after_the_waiting_period_and_can_be_checked_again(): void
    {
        $domain = $this->add('shop.example.org');
        $this->dns->failing[] = $domain['record']['name'];

        $this->artisan('domains:verify', ['--at' => CarbonImmutable::now()->addDays(4)->toIso8601String()])->assertSuccessful();
        $this->getJson('/api/v1/branding/domains', $this->headersFor())->assertJsonPath('data.0.status', 'failed')->assertJsonPath('data.0.failure', 'lookup_failed');

        // A failed domain is not checked by the scheduler; "Check now" makes it pending again.
        $this->dns->failing = [];
        $this->dns->publish($domain['record']['name'], $domain['record']['value']);
        $this->postJson("/api/v1/branding/domains/{$domain['id']}/check", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'verified');
    }

    public function test_hosts_are_validated_and_belong_to_one_tenant(): void
    {
        config(['branding.base_domain' => 'example.app']);

        foreach (['http://erp.company.co.ke', 'localhost', '10.0.0.1', 'erp.company.co.ke/path', 'acme.example.app', 'example.app'] as $host) {
            $this->postJson('/api/v1/branding/domains', ['host' => $host], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('host');
        }

        $this->add('erp.company.co.ke');
        $other = $this->otherTenant();
        $this->postJson('/api/v1/branding/domains', ['host' => 'erp.company.co.ke'], $this->bearer($this->tokenFor($other['user'])))
            ->assertUnprocessable()->assertJsonPath('code', 'domain_taken');
    }

    public function test_only_owner_and_admin_manage_domains_and_other_tenants_get_not_found(): void
    {
        $domain = $this->add('erp.company.co.ke');

        $admin = $this->userWith('admin', Scope::tenant());
        $this->getJson('/api/v1/branding/domains', $this->headersFor($admin))->assertOk()->assertJsonCount(1, 'data');

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson('/api/v1/branding/domains', $this->headersFor($manager))->assertForbidden();
        $this->postJson('/api/v1/branding/domains', ['host' => 'x.example.org'], $this->headersFor($manager))->assertForbidden();
        $this->getJson('/api/v1/branding/settings', $this->headersFor($manager))->assertForbidden();
        $this->putJson('/api/v1/branding/settings', ['slug' => 'acme'], $this->headersFor($manager))->assertForbidden();

        $other = $this->otherTenant();
        $headers = $this->bearer($this->tokenFor($other['user']));
        $this->postJson("/api/v1/branding/domains/{$domain['id']}/check", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/branding/domains/{$domain['id']}/archive", [], $headers)->assertNotFound();
        $this->getJson('/api/v1/branding/domains', $headers)->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_email_sender_is_used_only_on_a_verified_domain(): void
    {
        $domain = $this->add('company.co.ke');

        $this->putJson('/api/v1/branding/settings', ['email_from_address' => 'billing@company.co.ke'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('email_from_address');

        $this->dns->publish($domain['record']['name'], $domain['record']['value']);
        $this->postJson("/api/v1/branding/domains/{$domain['id']}/check", [], $this->headersFor())->assertOk();

        $this->putJson('/api/v1/branding/settings', [
            'email_from_name' => 'Acme Billing', 'email_from_address' => 'Billing@Company.co.ke', 'sms_sender_id' => 'ACME',
        ], $this->headersFor())->assertOk()
            ->assertJsonPath('data.email_from_address', 'billing@company.co.ke')
            ->assertJsonPath('data.email_sender_active', true)
            ->assertJsonPath('data.sms_sender_id', 'ACME')
            ->assertJsonPath('data.hide_platform', false);

        $this->putJson('/api/v1/branding/settings', ['email_from_name' => "Acme\nBcc: x", 'sms_sender_id' => 'TOO-LONG-SENDER'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['email_from_name', 'sms_sender_id']);

        // NotificationMail goes out from the tenant's sender, and from the platform once the domain is archived.
        $this->inTenant(function () {
            $sender = BrandedSender::address();
            $this->assertSame(['billing@company.co.ke', 'Acme Billing'], [$sender?->address, $sender?->name]);
            $mail = new NotificationMail('Subject', 'Text', null, 'en', [], $sender);
            $mail->assertFrom('billing@company.co.ke', 'Acme Billing');
        });

        $this->postJson("/api/v1/branding/domains/{$domain['id']}/archive", [], $this->headersFor())->assertOk();
        $this->inTenant(fn () => $this->assertNull(BrandedSender::address()));
        $this->getJson('/api/v1/branding/settings', $this->headersFor())->assertJsonPath('data.email_sender_active', false);

        $this->assertContains('core.branding.settings_update', $this->inTenant(fn () => AuditEntry::query()->pluck('action')->all()));
    }

    public function test_the_scheduler_functions_are_locked_down(): void
    {
        foreach (['app_tenants_with_pending_domains', 'app_tenant_for_verified_domain', 'app_public_branding'] as $name) {
            $fn = DB::selectOne(<<<'SQL'
                select p.oid, p.prosecdef, p.proconfig::text as config, pg_get_userbyid(p.proowner) as owner,
                       has_function_privilege('public', p.oid, 'execute') as public_execute,
                       has_function_privilege(current_user, p.oid, 'execute') as runtime_execute
                from pg_proc p join pg_namespace n on n.oid = p.pronamespace
                where n.nspname = 'public' and p.proname = ?
                SQL, [$name]);

            $this->assertNotNull($fn, $name);
            $this->assertTrue($fn->prosecdef, "{$name} is security definer");
            $this->assertSame(config('database.connections.pgsql_owner.username'), $fn->owner);
            $this->assertStringContainsString('search_path=pg_catalog, public', $fn->config);
            $this->assertFalse($fn->public_execute, "{$name} is revoked from PUBLIC");
            $this->assertTrue($fn->runtime_execute, "{$name} is granted to the runtime role");
        }

        // Without a tenant context the table itself shows nothing (RLS stays on).
        $this->add('erp.company.co.ke');
        app(TenantContext::class)->set(null);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as n from tenant_domains')->n);
        $this->assertSame(0, TenantDomain::query()->count());
    }
}
