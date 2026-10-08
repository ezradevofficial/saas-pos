<?php

namespace Tests\Feature;

use Tests\TestCase;

// CORS origins come from the environment (CORS_ALLOWED_ORIGINS), falling
// back to FRONTEND_URL alone; nothing local is hard-coded.
class CorsTest extends TestCase
{
    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saved = getenv('CORS_ALLOWED_ORIGINS') === false ? null : getenv('CORS_ALLOWED_ORIGINS');
    }

    protected function tearDown(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', $this->saved);

        parent::tearDown();
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    /** @return list<string> */
    private function origins(): array
    {
        return (require config_path('cors.php'))['allowed_origins'];
    }

    public function test_origins_are_a_comma_separated_list(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', 'https://app.example.com, https://pos.example.com,,');

        $this->assertSame(['https://app.example.com', 'https://pos.example.com'], $this->origins());
    }

    public function test_without_the_list_only_the_frontend_url_is_allowed(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', null);

        $this->assertSame([(string) env('FRONTEND_URL', 'http://localhost:3008')], $this->origins());
        $this->assertNotContains('http://localhost:3009', $this->origins());
    }

    public function test_a_listed_origin_is_answered_and_another_is_not(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com', 'https://pos.example.com']]);

        $this->withHeaders(['Origin' => 'https://app.example.com'])->getJson('/api/v1/me')
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        $this->withHeaders(['Origin' => 'https://evil.example.com'])->getJson('/api/v1/me')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
