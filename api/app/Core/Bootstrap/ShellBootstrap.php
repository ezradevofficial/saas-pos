<?php

namespace App\Core\Bootstrap;

use App\Core\Platform\SupportAccess\Http\EnforceSupportSession;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * NFR-02: the payloads the app shell asks for on every page, in one answer.
 *
 * Each part is the body of an existing endpoint, produced by that
 * endpoint's own controller and Form Request (never a copy of their logic):
 * the part is run as a GET sub-request of the signed-in user, so the same
 * authorisation, scope and field rules apply. A part the user may not see,
 * whose module is off, or that fails, is null; nothing is leaked or
 * invented. The route's own `module:` middleware is honoured (RBAC-08).
 */
class ShellBootstrap
{
    /** Request attribute marking a sub-request (the per-request memos are kept for it). */
    public const ATTRIBUTE = 'shell.part';

    /** @var array<string, string> part => the endpoint it stands for */
    public const PARTS = [
        'permissions' => 'me/permissions',
        'navigation' => 'config/navigation/resolved',
        'theme' => 'config/theme/resolved',
        'usage' => 'usage',
        'subscription' => 'subscription/status',
        'trial' => 'onboarding/trial',
        'unread' => 'notifications/unread-count',
        'support_active' => 'support-access/active',
        'approvals_waiting' => 'approvals?status=waiting&per_page=1&page=1',
        'companies' => 'companies?per_page=200',
        'my_companies' => 'me/companies',
        'custom_form_types' => 'custom-form-types',
        'hr_me' => 'hr/me',
    ];

    public function __construct(private readonly Application $app, private readonly Router $router) {}

    /**
     * @param  array<string, string>  $place  company/branch/location the theme is also resolved at
     * @return array<string, mixed>
     */
    public function parts(Request $parent, array $place = []): array
    {
        $parts = [];

        foreach (self::PARTS as $name => $uri) {
            $parts[$name] = $this->part($parent, $uri);
        }

        // BR-08: the theme at the user's chosen place, when the client names one.
        $parts['theme_place'] = $place === [] ? null : $this->part($parent, 'config/theme/resolved?'.http_build_query($place));

        return $parts;
    }

    private function part(Request $parent, string $uri): mixed
    {
        $sub = Request::create('/api/v1/'.$uri, 'GET', [], $parent->cookies->all(), [], $parent->server->all());
        $sub->headers->replace(array_diff_key($parent->headers->all(), array_flip(['content-type', 'content-length'])));
        $sub->setUserResolver($parent->getUserResolver());
        $sub->attributes->set(self::ATTRIBUTE, true);

        $path = ltrim(parse_url($uri, PHP_URL_PATH) ?: '', '/');

        if ($parent->user()?->support_grant_id !== null && Str::is(EnforceSupportSession::REFUSED, $path)) {
            return null;
        }

        try {
            $route = $this->router->getRoutes()->match($sub);

            if (! $this->moduleAllows($route)) {
                return null;
            }

            $sub->setRouteResolver(fn () => $route);
            $route->bind($sub);

            return $this->run($parent, $sub, $route);
        } catch (Throwable $e) {
            // Refusals (403, 404, 422) are simply "not for this user"; anything else is reported.
            if (! $e instanceof HttpExceptionInterface
                && ! $e instanceof AuthorizationException
                && ! $e instanceof ValidationException
                && ! $e instanceof ModelNotFoundException) {
                report($e);
            }

            return null;
        }
    }

    /** RBAC-08: the route's own `module:` middleware, read from the route. */
    private function moduleAllows(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^(?:module|.*EnsureModuleActive):(.+)$/', $middleware, $m)
                && ! $this->app->make(ModuleRegistry::class)->anyActive(explode(',', $m[1]))) {
                return false;
            }
        }

        return true;
    }

    private function run(Request $parent, Request $sub, Route $route): mixed
    {
        $this->app->instance('request', $sub);

        try {
            $method = $route->getActionMethod();
            // An invokable controller's action name is its class.
            $result = (new ControllerDispatcher($this->app))->dispatch($route, $route->getController(), str_contains($method, '\\') ? '__invoke' : $method);
        } finally {
            $this->app->instance('request', $parent);
        }

        if ($result instanceof Responsable) {
            $result = $result->toResponse($sub);
        }

        if ($result instanceof SymfonyResponse) {
            if ($result->getStatusCode() >= 300 || $result->getStatusCode() === Response::HTTP_NO_CONTENT) {
                return null;
            }

            return json_decode((string) $result->getContent(), true);
        }

        return is_array($result) ? $result : null;
    }
}
