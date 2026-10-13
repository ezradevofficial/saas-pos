<?php

namespace App\Core\Imports;

use App\Core\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Throwable;

/**
 * IMP-01, RBAC-05: runs the API's own Form Request for one imported row,
 * as the importing user: its authorisation (policies, scopes), the field
 * rules guard, its rules and its after-hooks (cross-field checks and
 * CustomFieldValidator, CF-03). An imported row never meets weaker rules
 * than the same record typed in the app.
 */
class DomainRequests
{
    /**
     * The validated input, or RowRejected with the errors.
     *
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $input  the JSON body the API would receive
     * @param  array<string, mixed>  $parameters  route parameters (the record being changed)
     * @return array<string, mixed>
     *
     * @throws RowRejected
     */
    public function validate(string $class, array $input, User $user, array $parameters = []): array
    {
        /** @var FormRequest $request */
        $request = $class::create('/imports', 'POST', $input);
        $request->setContainer(app())->setRedirector(app(Redirector::class));
        $request->setUserResolver(fn () => $user);

        $route = (new Route(['POST'], 'imports', []))->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(fn () => $route);

        try {
            $request->validateResolved();
        } catch (Throwable $e) {
            throw RowRejected::from($e) ?? $e;
        }

        return $request->validated();
    }
}
