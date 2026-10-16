<?php

namespace App\Core\Api\Docs;

use App\Core\Api\ApiScopes;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * API-04 (ADR 020): the OpenAPI document of the public API. Scramble
 * generates it from the routes marked `public-api:<resource>`, their Form
 * Requests and API Resources; this class then gives each operation a
 * stable id (`<module>.<controller>.<method>`), its English summary and
 * description and a French `x-i18n.fr` copy (`lang/{en,fr}/api_reference.php`),
 * the scope it needs (`x-scope`), its tag (the resource), `deprecated` from
 * `api.deprecations`, and the API key security scheme. Nothing tenant- or
 * installation-specific is written, so the committed baseline
 * (`resources/openapi/v1.json`) is the same everywhere.
 */
class OpenApiDocument
{
    public const BASELINE = 'resources/openapi/v1.json';

    public function __construct(private readonly Router $router) {}

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $spec = app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
        $operations = $this->operations();
        $byTemplate = [];

        foreach ($operations as $key => $operation) {
            $byTemplate[self::template($key)] = $operation;
        }

        $spec['info'] = [
            'title' => trans('api_reference.title', [], 'en'),
            'version' => '1.0.0',
            'description' => trans('api_reference.description', [], 'en'),
            'x-i18n' => ['fr' => ['title' => trans('api_reference.title', [], 'fr'), 'description' => trans('api_reference.description', [], 'fr')]],
        ];
        $spec['servers'] = [['url' => '/api/v1']];
        $spec['components']['securitySchemes'] = [
            'apiKey' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'sk_live_<id>_<secret>',
                'description' => trans('api_reference.security', [], 'en'),
            ],
        ];
        $spec['security'] = [['apiKey' => []]];

        $paths = [];

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $known = $byTemplate[self::template(strtoupper($method).' '.$path)] ?? null;

                if ($known === null || ! is_array($operation)) {
                    continue;
                }

                $paths[$path][$method] = $this->describe($operation, $known);
            }
        }

        ksort($paths);
        $spec['paths'] = $paths;
        $spec['tags'] = collect($operations)->pluck('resource')->unique()->sort()->values()
            ->map(fn (string $resource) => ['name' => $resource])->all();

        return $spec;
    }

    /**
     * Every public operation, "METHOD /path" (path without /api/v1) =>
     * its id, resource, scope, method and uri.
     *
     * @return array<string, array{id: string, resource: string, scope: string, method: string, uri: string}>
     */
    public function operations(): array
    {
        $operations = [];

        foreach ($this->router->getRoutes() as $route) {
            $resource = ApiScopes::resourceOf($route);

            if ($resource === null) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $uri = preg_replace('#^api/v1/#', '', $route->uri());
                $operations[$method.' /'.$uri] = [
                    'id' => self::operationId($route, $resource),
                    'resource' => $resource,
                    'scope' => $resource.'.'.($method === 'GET' ? ApiScopes::READ : ApiScopes::WRITE),
                    'method' => $method,
                    'uri' => $uri,
                ];
            }
        }

        ksort($operations);

        return $operations;
    }

    /**
     * "METHOD /path" with every path parameter as `{}`: Scramble names
     * parameters after the controller's arguments (`{itemCategory}`), the
     * router after the route (`{item_category}`).
     */
    public static function template(string $operation): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $operation);
    }

    /** `<module>.<controller>.<method>`, e.g. `core.item.index`, `pos.sale.show`. */
    public static function operationId(Route $route, string $resource): string
    {
        [$class, $method] = array_pad(explode('@', $route->getActionName()), 2, '__invoke');
        $controller = Str::snake(Str::beforeLast(class_basename($class), 'Controller'));
        $action = $method === '__invoke' ? 'invoke' : Str::snake($method);

        return Str::before($resource, '.').".{$controller}.{$action}";
    }

    /** The translation key of an operation's summary or description. */
    public static function key(string $operationId, string $field): string
    {
        return 'api_reference.operations.'.str_replace('.', '_', $operationId).'.'.$field;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array{id: string, resource: string, scope: string, method: string, uri: string}  $known
     * @return array<string, mixed>
     */
    private function describe(array $operation, array $known): array
    {
        $text = fn (string $field, string $locale) => trans()->hasForLocale(self::key($known['id'], $field), $locale)
            ? trans(self::key($known['id'], $field), [], $locale)
            : null;

        // Scramble flattens `list.*: required` into a required `list[]`: the
        // items are required, not the list (`sometimes`).
        foreach ($operation['parameters'] ?? [] as $i => $parameter) {
            if (is_array($parameter) && ($parameter['in'] ?? null) === 'query' && str_ends_with((string) ($parameter['name'] ?? ''), '[]')) {
                unset($operation['parameters'][$i]['required']);
            }
        }

        $operation['operationId'] = $known['id'];
        $operation['tags'] = [$known['resource']];
        $operation['summary'] = $text('summary', 'en') ?? '';
        $operation['description'] = $text('description', 'en') ?? '';
        $operation['x-scope'] = $known['scope'];
        $operation['x-i18n'] = ['fr' => array_filter(['summary' => $text('summary', 'fr'), 'description' => $text('description', 'fr')])];

        if ($operation['description'] === '') {
            unset($operation['description']);
        }

        $deprecation = config('api.deprecations')[$known['method'].' '.$known['uri']] ?? null;

        if (is_array($deprecation)) {
            $operation['deprecated'] = true;
            $operation['x-sunset'] = $deprecation['sunset'] ?? null;
        }

        return $operation;
    }
}
