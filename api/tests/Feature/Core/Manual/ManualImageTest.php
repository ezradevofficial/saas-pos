<?php

namespace Tests\Feature\Core\Manual;

use App\Core\Identity\Models\User;
use App\Core\Manual\ManualBuilder;
use App\Core\Manual\TopicLibrary;
use App\Core\Manual\VisibleTopics;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-08: a topic's screenshot is served only to a user who can see the topic.
class ManualImageTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $manual;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manual = sys_get_temp_dir().'/manual-image-test-'.bin2hex(random_bytes(4));
        mkdir($this->manual.'/en/pos', 0775, true);
        mkdir($this->manual.'/images/en', 0775, true);

        $this->topic('seen', 'pos.sale.view');
        $this->topic('hidden', 'pos.sale.void');
        $this->topic('plain', 'pos.sale.view', picture: false);
        file_put_contents($this->manual.'/images/en/seen.png', base64_decode(self::PNG));
        file_put_contents($this->manual.'/images/en/hidden.png', base64_decode(self::PNG));

        config(['manual.path' => $this->manual, 'app.name' => 'Test App']);
        foreach ([TopicLibrary::class, ManualBuilder::class, VisibleTopics::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->cashier = $this->createUser(['name' => 'Cashier']);
        $this->asTenant($this->cashier->tenant_id, function () {
            app(ModuleRegistry::class)->activate('pos');
            $this->assign($this->cashier, $this->role('Till', ['pos.sale.create', 'pos.sale.view']), Scope::tenant());
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->manual);

        parent::tearDown();
    }

    private function topic(string $id, string $permission, bool $picture = true): void
    {
        file_put_contents($this->manual."/en/pos/{$id}.md", "---\nid: {$id}\ntitle: Topic {$id}\nmodule: pos\npermissions: [{$permission}]\nroles: [cashier]\nscreens: [/pos/sales]\n---\nDo the thing.\n");
    }

    public function test_a_visible_topics_image_is_served(): void
    {
        $response = $this->get('/api/v1/manual/topics/seen/image?locale=en', $this->bearer($this->tokenFor($this->cashier)))->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame(base64_decode(self::PNG), file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_the_topic_carries_its_image_url_and_alt_text(): void
    {
        $data = $this->getJson('/api/v1/manual/topics/seen?locale=en', $this->bearer($this->tokenFor($this->cashier)))->assertOk()->json('data');

        $this->assertStringEndsWith('/api/v1/manual/topics/seen/image?locale=en', $data['image_url']);
        $this->assertSame('Screenshot of Topic seen', $data['image_alt']);

        $plain = $this->getJson('/api/v1/manual/topics/plain?locale=en', $this->bearer($this->tokenFor($this->cashier)))->assertOk()->json('data');
        $this->assertNull($plain['image_url']);
    }

    public function test_a_hidden_topics_image_is_not_found(): void
    {
        $this->get('/api/v1/manual/topics/hidden/image?locale=en', $this->bearer($this->tokenFor($this->cashier)))->assertNotFound();
    }

    public function test_a_topic_without_an_image_and_an_unknown_topic_are_not_found(): void
    {
        $headers = $this->bearer($this->tokenFor($this->cashier));

        $this->get('/api/v1/manual/topics/plain/image?locale=en', $headers)->assertNotFound();
        $this->get('/api/v1/manual/topics/nothing/image?locale=en', $headers)->assertNotFound();
    }

    public function test_a_module_the_tenant_does_not_have_hides_the_image(): void
    {
        $this->asTenant($this->cashier->tenant_id, fn () => app(ModuleRegistry::class)->deactivate('pos'));

        $this->get('/api/v1/manual/topics/seen/image?locale=en', $this->bearer($this->tokenFor($this->cashier)))->assertNotFound();
    }

    public function test_signed_out_requests_are_refused(): void
    {
        $this->getJson('/api/v1/manual/topics/seen/image?locale=en')->assertUnauthorized();
    }

    public function test_the_pdf_builder_embeds_the_image_under_the_title(): void
    {
        $html = app(ManualBuilder::class)->html('en', 'cashier');

        $this->assertMatchesRegularExpression('#<h2 id="t-seen">Topic seen</h2><img class="shot" alt="Screenshot of Topic seen" src="data:image/png;base64,#', $html);
        $this->assertStringNotContainsString('alt="Screenshot of Topic plain"', $html);
        $this->assertStringStartsWith('%PDF-', app(ManualBuilder::class)->pdf('en', 'cashier'));
    }
}
