<?php

namespace Tests\Feature\Core\Api;

use App\Core\Api\ApiScopes;
use App\Core\Api\Docs\OpenApiDocument;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// API-04 (ADR 020): the OpenAPI document generates (Scramble), holds the
// public routes only, each with an id, a scope and en/fr summaries; the
// committed baseline is current; a removal needs a deprecation entry; the
// portal reads it without a credential. Scramble reads models' column
// types from the schema, so the database is migrated first.
class OpenApiDocumentTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_the_document_holds_only_public_routes_each_documented(): void
    {
        $spec = app(OpenApiDocument::class)->generate();
        $operations = [];

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations[strtoupper($method).' '.$path] = $operation;
                $this->assertNotSame('', $operation['summary'], "{$method} {$path}");
                $this->assertNotEmpty($operation['x-i18n']['fr']['summary'] ?? null, "{$method} {$path}");
                $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+\.(read|write)$/', $operation['x-scope']);
                $this->assertSame([['apiKey' => []]], $spec['security']);
            }
        }

        // Exactly the routes marked public-api, nothing else.
        $public = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => ApiScopes::resourceOf($route) !== null);
        $this->assertCount(collect($public)->sum(fn ($route) => count(array_diff($route->methods(), ['HEAD']))), $operations);

        foreach (array_keys($operations) as $key) {
            foreach (['/me', '/auth', '/api-keys', '/sandbox', '/subscription', '/platform', '/devices', '/sync', '/users', '/roles', '/onboarding', '/developers'] as $internal) {
                $this->assertStringNotContainsString(' '.$internal, $key);
            }
        }

        $this->assertArrayHasKey('GET /items', $operations);
        $this->assertSame('core.item.index', $operations['GET /items']['operationId']);
        $this->assertSame('core.item.read', $operations['GET /items']['x-scope']);
        $this->assertSame('core.item.write', $operations['POST /items']['x-scope']);
        $this->assertSame('/api/v1', $spec['servers'][0]['url']);
    }

    public function test_the_committed_baseline_is_current(): void
    {
        $this->artisan('api:docs', ['--check' => true])->assertSuccessful();
    }

    public function test_a_removed_operation_needs_a_deprecation_entry(): void
    {
        $baseline = json_decode((string) file_get_contents(base_path(OpenApiDocument::BASELINE)), true);
        $baseline['paths']['/legacy-things'] = ['get' => ['operationId' => 'core.legacy.index', 'parameters' => []]];
        $path = tempnam(sys_get_temp_dir(), 'openapi-baseline-');
        file_put_contents($path, json_encode($baseline));

        $this->artisan('api:docs', ['--check' => true, '--baseline' => $path])
            ->expectsOutputToContain('GET legacy-things: removed without a deprecation entry')
            ->assertFailed();

        config(['api.deprecations' => ['GET legacy-things' => ['deprecated_at' => '2026-10-01', 'sunset' => '2027-04-01']]]);
        $this->artisan('api:docs', ['--check' => true, '--baseline' => $path])
            ->doesntExpectOutputToContain('GET legacy-things: removed')
            ->expectsOutputToContain('is not the generated document')
            ->assertFailed();

        unlink($path);
    }

    public function test_a_deprecated_operation_is_marked_in_the_document(): void
    {
        config(['api.deprecations' => ['GET items' => ['deprecated_at' => '2026-10-01', 'sunset' => '2027-04-01']]]);

        $operation = app(OpenApiDocument::class)->generate()['paths']['/items']['get'];

        $this->assertTrue($operation['deprecated']);
        $this->assertSame('2027-04-01', $operation['x-sunset']);
    }

    public function test_the_portal_reads_the_document_without_a_credential(): void
    {
        $this->getJson('/api/v1/developers/openapi.json')->assertOk()
            ->assertJsonPath('openapi', fn (string $version) => str_starts_with($version, '3.1.'))
            ->assertJsonPath('info.x-i18n.fr.title', __('api_reference.title', [], 'fr'))
            ->assertJsonPath('paths./items.get.operationId', 'core.item.index');
    }
}
