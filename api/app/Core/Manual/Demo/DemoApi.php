<?php

namespace App\Core\Manual\Demo;

use App\Core\Manual\DemoTenantCommand;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The demo tenant's data is created through the application's own API
 * (validation, policies, services, events), signed in as a demo user, so no
 * rule is skipped. Requests run in this process.
 */
class DemoApi
{
    /** @var array<string, self> signed-in clients by role key, so each demo user signs in once */
    private static array $clients = [];

    private static int $addresses = 0;

    private ?string $token = null;

    /** Each client has an address of its own, so the sign-in limit per address never stops the seeding. */
    private readonly string $ip;

    public function __construct(private readonly string $email, private readonly string $password)
    {
        $this->ip = '10.77.'.intdiv(++self::$addresses, 250).'.'.(self::$addresses % 250 + 1);
    }

    /** A client signed in as another demo user. */
    public function as(string $key): self
    {
        return self::$clients[$key] ??= new self('demo-'.str_replace('_', '-', $key).'@'.DemoTenantCommand::DOMAIN, $this->password);
    }

    /** A client that sends a device or user token it already holds. */
    public function bearer(string $token): self
    {
        $client = new self('', $this->password);
        $client->token = $token;

        return $client;
    }

    /** @return array<string, mixed> */
    public function get(string $uri, array $query = []): array
    {
        return $this->send('GET', $uri, $query);
    }

    /** @return array<string, mixed> */
    public function post(string $uri, array $data = []): array
    {
        return $this->send('POST', $uri, $data);
    }

    /** @return array<string, mixed> */
    public function put(string $uri, array $data = []): array
    {
        return $this->send('PUT', $uri, $data);
    }

    /** @return array<string, mixed> */
    public function patch(string $uri, array $data = []): array
    {
        return $this->send('PATCH', $uri, $data);
    }

    /** The `data` rows of a list endpoint. @return list<array<string, mixed>> */
    public function rows(string $uri, array $query = []): array
    {
        return $this->get($uri, $query + ['per_page' => 100])['data'] ?? [];
    }

    /** @return array<string, mixed> */
    public function send(string $method, string $uri, array $data = [], bool $signedIn = true): array
    {
        if ($signedIn && $this->token === null) {
            $this->token = (string) $this->send('POST', 'auth/sign-in', ['login' => $this->email, 'password' => $this->password], false)['token'];
        }

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip];

        if ($signedIn) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }

        $get = $method === 'GET';
        $request = Request::create('/api/v1/'.ltrim($uri, '/'), $method, $get ? $data : [], [], [], $server, $get ? null : json_encode($data));

        Auth::forgetGuards();
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $body = json_decode((string) $response->getContent(), true);

        if ($response->getStatusCode() >= 400) {
            $detail = is_array($body) ? json_encode($body['results'] ?? $body['errors'] ?? $body['message'] ?? $body) : (string) $response->getContent();

            throw new RuntimeException("{$method} {$uri} answered {$response->getStatusCode()}: {$detail}");
        }

        return is_array($body) ? $body : [];
    }
}
