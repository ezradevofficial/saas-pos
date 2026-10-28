<?php

namespace Tests\Feature\Core\Manual;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-08, L10N-01: the manual in the app shows a user only the topics for
// the modules the tenant has and the permissions the user holds.
class ManualApiTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Test App']);

        $this->cashier = $this->createUser(['name' => 'Cashier']);
        $this->asTenant($this->cashier->tenant_id, function () {
            app(ModuleRegistry::class)->activate('pos');
            $this->assign($this->cashier, $this->role('Till', ['pos.sale.create', 'pos.sale.view']), Scope::tenant());
        });
    }

    private function topics(?User $user = null, string $query = ''): array
    {
        $token = $this->tokenFor($user ?? $this->cashier);

        return $this->getJson('/api/v1/manual/topics'.$query, $this->bearer($token))->assertOk()->json('data');
    }

    public function test_a_cashier_sees_pos_topics_but_not_payroll_ones(): void
    {
        $topics = $this->topics();
        $ids = array_column($topics, 'id');

        $this->assertContains('pos-sell', $ids);
        $this->assertNotContains('payroll-payslips', $ids);
        $this->assertNotContains('rbac-create-role', $ids);

        $sell = collect($topics)->firstWhere('id', 'pos-sell');
        $this->assertSame('Sell items', $sell['title']);
        $this->assertSame('pos', $sell['module']);
        $this->assertSame('Point of sale', $sell['module_label']);
        $this->assertNotSame('', $sell['excerpt']);
        $this->assertStringNotContainsString('{{', $sell['excerpt']);
    }

    public function test_seeing_a_screen_is_not_enough_for_a_topic_about_acting_on_it(): void
    {
        // The cashier template can view the tills; pairing one is the Owner's and admin's.
        $this->asTenant($this->cashier->tenant_id, fn () => $this->assign($this->cashier, $this->role('Till viewer', ['core.device.view']), Scope::tenant()));

        $ids = array_column($this->topics(), 'id');

        $this->assertNotContains('organisation-pair-device', $ids);
        $this->assertNotContains('organisation-suspend-or-unpair-device', $ids);
        $this->assertNotContains('onboarding-sign-up', $ids);
        $this->assertContains('pos-sell', $ids);
    }

    public function test_topics_without_permissions_are_for_every_signed_in_user(): void
    {
        $nobody = $this->createUser();

        $ids = array_column($this->topics($nobody), 'id');

        $this->assertContains('core-choose-appearance', $ids);
        $this->assertNotContains('pos-sell', $ids);
    }

    public function test_a_module_the_tenant_does_not_have_is_hidden(): void
    {
        $this->asTenant($this->cashier->tenant_id, fn () => app(ModuleRegistry::class)->deactivate('pos'));

        $this->assertNotContains('pos-sell', array_column($this->topics(), 'id'));

        $this->getJson('/api/v1/manual/topics/pos-sell', $this->bearer($this->tokenFor($this->cashier)))->assertNotFound();
    }

    public function test_the_module_subscription_is_the_tenants_own(): void
    {
        // Another tenant has the module and a permitted user; this tenant's view does not change.
        $other = $this->createUser();
        $this->asTenant($other->tenant_id, function () use ($other) {
            app(ModuleRegistry::class)->activate('payroll');
            $this->assign($other, $this->role('Payroll', ['payroll.payslip.view']), Scope::tenant());
        });

        $this->assertContains('payroll-payslips', array_column($this->topics($other), 'id'));
        $this->assertNotContains('payroll-payslips', array_column($this->topics(), 'id'));
    }

    public function test_one_topic_is_returned_as_safe_html(): void
    {
        $response = $this->getJson('/api/v1/manual/topics/pos-sell', $this->bearer($this->tokenFor($this->cashier)))->assertOk();

        $this->assertSame('pos-sell', $response->json('data.id'));
        $this->assertSame('Sell items', $response->json('data.title'));
        $this->assertStringContainsString('<ol>', $response->json('data.html'));
        $this->assertStringNotContainsString('{{app_name}}', $response->json('data.html'));
        $this->assertStringNotContainsString('<script', $response->json('data.html'));
    }

    public function test_a_hidden_topic_is_not_found_not_forbidden(): void
    {
        $token = $this->tokenFor($this->cashier);

        $hidden = $this->getJson('/api/v1/manual/topics/payroll-payslips', $this->bearer($token))->assertNotFound();
        $missing = $this->getJson('/api/v1/manual/topics/no-such-topic', $this->bearer($token))->assertNotFound();

        $this->assertSame($missing->json('message'), $hidden->json('message'));
        $this->assertSame($missing->json('code'), $hidden->json('code'));
    }

    public function test_signed_out_requests_are_refused(): void
    {
        $this->getJson('/api/v1/manual/topics')->assertUnauthorized();
        $this->getJson('/api/v1/manual/topics/pos-sell')->assertUnauthorized();
        $this->getJson('/api/v1/manual/my-guide.pdf')->assertUnauthorized();
    }

    public function test_the_locale_defaults_to_the_users_and_french_works(): void
    {
        $this->asTenant($this->cashier->tenant_id, fn () => $this->cashier->update(['locale' => 'fr']));

        $topics = $this->topics();
        $sell = collect($topics)->firstWhere('id', 'pos-sell');

        $this->assertSame('Point de vente', $sell['module_label']);
        $this->assertNotSame('Sell items', $sell['title']);

        $english = collect($this->topics(null, '?locale=en'))->firstWhere('id', 'pos-sell');
        $this->assertSame('Sell items', $english['title']);

        $this->getJson('/api/v1/manual/topics?locale=de', $this->bearer($this->tokenFor($this->cashier)))->assertUnprocessable();
    }

    public function test_the_guide_pdf_holds_only_the_visible_topics(): void
    {
        $token = $this->tokenFor($this->cashier);
        $response = $this->get('/api/v1/manual/my-guide.pdf?locale=en', $this->bearer($token))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -64));

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = implode("\n", array_map(fn (string $stream) => (string) @gzuncompress($stream), $streams[1]));

        // Geist is embedded, so the text is stored as UTF-16BE character codes.
        $utf16 = fn (string $text) => mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');

        $this->assertStringContainsString($utf16('Sell items'), $text);
        $this->assertStringContainsString($utf16('My guide'), $text);
        $this->assertStringNotContainsString($utf16('Find and download payslips'), $text);
        $this->assertStringNotContainsString($utf16('Create a role and set its limits'), $text);
    }

    public function test_the_guide_pdf_is_built_in_french_too(): void
    {
        $response = $this->get('/api/v1/manual/my-guide.pdf?locale=fr', $this->bearer($this->tokenFor($this->cashier)))->assertOk();

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('guide-fr.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_the_guide_pdf_is_rate_limited(): void
    {
        $token = $this->tokenFor($this->cashier);
        // The first build embeds the screenshots and can take a while; the limiter's minute must not run out mid-test.
        $this->freezeTime();

        for ($i = 0; $i < 6; $i++) {
            $this->get('/api/v1/manual/my-guide.pdf', $this->bearer($token))->assertOk();
        }

        $this->get('/api/v1/manual/my-guide.pdf', $this->bearer($token))->assertStatus(429);
    }
}
