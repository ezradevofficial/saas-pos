<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

// Every endpoint that changes something goes through a Form Request
// (global constraint), even when it has no body to validate.
class FormRequestCoverageTest extends TestCase
{
    public function test_every_writing_api_route_takes_a_form_request(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || array_diff($route->methods(), ['GET', 'HEAD']) === []) {
                continue;
            }

            [$class, $method] = array_pad(explode('@', $route->getActionName()), 2, '__invoke');

            if (! class_exists($class)) {
                continue;
            }

            $typed = collect((new ReflectionMethod($class, $method))->getParameters())
                ->contains(fn ($p) => $p->getType() instanceof ReflectionNamedType && is_subclass_of($p->getType()->getName(), FormRequest::class));

            if (! $typed) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $missing);
    }
}
