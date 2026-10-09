<?php

namespace Tests\Feature\Core\Branding;

use App\Core\Audit\AuditEntry;
use App\Core\Branding\Models\BrandAsset;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * BR-02, BR-03, BR-08, TEN-01, RBAC-04, AUD-01: the theme configuration
 * kind: publishing refused under WCAG AA, tokens a tenant may not change
 * refused, scope resolution (branch over company over tenant), brand
 * assets, permissions and other tenants.
 */
class ThemeApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const URL = '/api/v1/config/theme';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->setUpOrganisation();
    }

    private function save(array $payload, array $scope = ['scope_type' => 'tenant'], ?array $headers = null, int $status = 201): array
    {
        return $this->postJson(self::URL, [...$scope, 'payload' => $payload], $headers ?? $this->headersFor())->assertStatus($status)->json();
    }

    private function publish(string $id, ?array $headers = null): TestResponse
    {
        return $this->postJson(self::URL."/{$id}/publish", [], $headers ?? $this->headersFor());
    }

    public function test_publishing_is_refused_while_a_pair_is_under_wcag_aa(): void
    {
        $draft = $this->save(['preset' => 'light', 'colors' => ['primary' => '#ffcc00']]);
        $id = $draft['data']['id'];

        // The draft is kept; its problems say which pair fails, in which mode, by how much.
        $problems = collect($draft['meta']['problems']);
        $this->assertSame(['contrast'], $problems->pluck('code')->unique()->values()->all());
        $this->assertSame('colors.primary', $problems->first()['path']);
        $this->assertSame('light', $problems->first()['mode']);
        $this->assertStringContainsString('1.46:1', $problems->first()['message']);

        $this->publish($id)->assertUnprocessable()->assertJsonPath('code', 'config_invalid')->assertJsonPath('problems.0.code', 'contrast');

        // A readable colour publishes; dark mode is derived and checked too.
        $this->putJson(self::URL."/{$id}/draft", ['payload' => ['preset' => 'light', 'colors' => ['primary' => '#0b5d6e', 'accent' => '#7c2d12']]], $this->headersFor())
            ->assertOk()->assertJsonPath('meta.problems', []);
        $this->publish($id)->assertOk()->assertJsonPath('data.published.version', 1);

        $actions = $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $id)->orderBy('seq')->pluck('action')->all());
        $this->assertContains('core.config.publish', $actions);
    }

    public function test_tokens_a_tenant_may_not_change_are_refused(): void
    {
        $draft = $this->save([
            'preset' => 'light',
            'colors' => ['primary' => '#0b5d6e', 'primary-hover' => '#000000', 'danger' => '#00ff00', 'success' => '#00ff00'],
            'focus' => '#ff00ff',
            'spacing' => ['space-4' => '20px'],
            'text-body-size' => '18px',
        ]);

        $refused = collect($draft['meta']['problems'])->where('code', 'not_overridable')->pluck('path')->sort()->values()->all();
        $this->assertSame(['colors.danger', 'colors.primary-hover', 'colors.success', 'focus', 'spacing', 'text-body-size'], $refused);
        $this->publish($draft['data']['id'])->assertUnprocessable()->assertJsonPath('code', 'config_invalid');

        // Unknown choices are refused too.
        $bad = $this->save(['preset' => 'neon', 'corners' => 'round', 'font' => 'comic', 'sidebar' => 'blue', 'colors' => ['primary' => 'teal']], status: 200);
        $this->assertEqualsCanonicalizing(['preset', 'corners', 'font', 'sidebar', 'colors.primary'], collect($bad['meta']['problems'])->pluck('path')->all());
    }

    public function test_assets_must_be_the_tenants_own_of_the_right_kind(): void
    {
        $logo = $this->postJson('/api/v1/branding/assets', ['kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 400, 120)], $this->headersFor())
            ->assertCreated()->json('data');
        $this->assertStringContainsString('/api/v1/branding/assets/tenants/', $logo['url']);

        $other = $this->otherTenant();
        $foreign = $this->asTenant($other['user']->tenant_id, fn () => BrandAsset::create([
            'kind' => 'logo', 'disk' => 'media', 'path' => "tenants/{$other['user']->tenant_id}/branding/".fake()->uuid().'.png', 'mime' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1,
        ]));

        $draft = $this->save(['preset' => 'light', 'logo_light' => $logo['id'], 'logo_dark' => $foreign->id, 'favicon' => $logo['id']]);
        $this->assertSame(['logo_dark', 'favicon'], collect($draft['meta']['problems'])->where('code', 'asset_missing')->pluck('path')->values()->all());

        // The signed URL serves the file; a tampered one is refused.
        $this->get($logo['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($logo['url'].'x')->assertForbidden();

        // A favicon over 512 pixels is refused.
        $this->postJson('/api/v1/branding/assets', ['kind' => 'favicon', 'file' => UploadedFile::fake()->image('icon.png', 1024, 1024)], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_the_most_specific_published_theme_applies_branch_over_company_over_tenant(): void
    {
        $publish = function (array $scope, string $primary) {
            $id = $this->save(['preset' => 'light', 'colors' => ['primary' => $primary]], $scope)['data']['id'];
            $this->publish($id)->assertOk();
        };

        $this->getJson(self::URL.'/resolved', $this->headersFor())->assertOk()
            ->assertJsonPath('data.payload.preset', 'light')->assertJsonPath('data.source', null);

        $publish(['scope_type' => 'tenant'], '#0b5d6e');
        $publish(['scope_type' => 'company', 'scope_id' => $this->acme->id], '#1e3a8a');
        $publish(['scope_type' => 'branch', 'scope_id' => $this->branchA->id], '#7c2d12');

        $resolve = fn (string $query, $user = null) => $this->getJson(self::URL.'/resolved'.$query, $this->headersFor($user));

        $resolve('')->assertOk()->assertJsonPath('data.payload.colors.primary', '#0b5d6e')->assertJsonPath('data.source.scope.type', 'tenant');
        $resolve("?company={$this->acme->id}")->assertJsonPath('data.payload.colors.primary', '#1e3a8a');
        $resolve("?branch={$this->branchA->id}")->assertJsonPath('data.payload.colors.primary', '#7c2d12')
            ->assertJsonPath('data.payload.asset_urls.logo_light', null);
        $resolve("?branch={$this->branchB->id}")->assertJsonPath('data.payload.colors.primary', '#1e3a8a');
        $resolve("?location={$this->locationA->id}")->assertJsonPath('data.payload.colors.primary', '#7c2d12');

        // A cashier at branch B sees B's (the company's) theme, and is refused branch A's place.
        $cashier = $this->userWith('cashier', Scope::branch($this->branchB->id));
        $resolve("?branch={$this->branchB->id}", $cashier)->assertOk()->assertJsonPath('data.payload.colors.primary', '#1e3a8a');
        $resolve("?branch={$this->branchA->id}", $cashier)->assertUnprocessable();
    }

    public function test_editing_needs_theme_permissions_at_the_scope(): void
    {
        // A branch manager may see layouts (core.config.view) but holds no theme permission.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->postJson(self::URL, ['scope_type' => 'branch', 'scope_id' => $this->branchA->id, 'payload' => ['preset' => 'light']], $this->headersFor($manager))->assertForbidden();
        $this->getJson(self::URL, $this->headersFor($manager))->assertForbidden();
        $this->postJson('/api/v1/branding/assets', ['kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png')], $this->headersFor($manager))->assertForbidden();

        // A role with core.theme.edit at branch A edits A's theme, not the company's; publishing needs core.theme.publish.
        $editor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Brand editor', ['core.theme.view', 'core.theme.edit']), Scope::branch($this->branchA->id));

            return $user;
        });
        $id = $this->save(['preset' => 'warm'], ['scope_type' => 'branch', 'scope_id' => $this->branchA->id], $this->headersFor($editor))['data']['id'];
        $this->postJson(self::URL, ['scope_type' => 'company', 'scope_id' => $this->acme->id, 'payload' => ['preset' => 'warm']], $this->headersFor($editor))->assertForbidden();
        $this->publish($id, $this->headersFor($editor))->assertForbidden();
        $this->publish($id)->assertOk();

        // Location scope is not a theme scope (BR-08: tenant, company or branch).
        $this->postJson(self::URL, ['scope_type' => 'location', 'scope_id' => $this->locationA->id, 'payload' => ['preset' => 'light']], $this->headersFor())->assertUnprocessable();
    }

    public function test_another_tenant_never_sees_or_changes_the_theme(): void
    {
        $id = $this->save(['preset' => 'executive'])['data']['id'];
        $this->publish($id)->assertOk();

        $other = $this->otherTenant();
        $headers = $this->bearer($this->tokenFor($other['user']));

        $this->getJson(self::URL."/{$id}", $headers)->assertNotFound();
        $this->postJson(self::URL."/{$id}/publish", [], $headers)->assertNotFound();
        $this->getJson(self::URL, $headers)->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::URL.'/resolved', $headers)->assertOk()->assertJsonPath('data.source', null)->assertJsonPath('data.payload.preset', 'light');
        $this->getJson('/api/v1/branding/assets', $headers)->assertOk()->assertJsonPath('data', []);
    }
}
