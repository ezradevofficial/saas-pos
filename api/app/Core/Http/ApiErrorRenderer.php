<?php

namespace App\Core\Http;

use App\Core\Api\ApiKeyToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every API error has one shape: `{message, code, errors?}` with a
 * translated message and a stable machine code. A request made with an API
 * key gets the same body as `application/problem+json` (RFC 9457) with
 * `type`, `title`, `status` and `detail` added (ADR 020).
 */
class ApiErrorRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! ($request->is('api/*') || $request->expectsJson())) {
            return null;
        }

        $response = self::response($e);

        return $response !== null && ApiKeyToken::claims($request->bearerToken()) ? self::problem($response) : $response;
    }

    private static function response(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::json(
                $e->getStatusCode(), $e->errorCode, $e->getMessage(), $e->errors, $e->extra, $e->getHeaders(),
            ),
            $e instanceof ValidationException => self::json(
                $e->status, 'validation_failed', __('core.errors.validation_failed'), $e->errors(),
            ),
            $e instanceof AuthenticationException => self::json(401, 'unauthenticated', __('core.errors.unauthenticated')),
            $e instanceof HttpExceptionInterface => self::fromHttp($e),
            // Keep the framework's detailed page while debugging.
            (bool) config('app.debug') => null,
            default => self::json(500, 'server_error', __('core.errors.server_error')),
        };
    }

    private static function fromHttp(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $headers = $e->getHeaders();

        [$code, $message] = match ($status) {
            401 => ['unauthenticated', __('core.errors.unauthenticated')],
            403 => ['forbidden', __('core.errors.forbidden')],
            404 => ['not_found', __('core.errors.not_found')],
            405 => ['method_not_allowed', __('core.errors.method_not_allowed')],
            429 => ['too_many_requests', self::retryMessage((int) ($headers['Retry-After'] ?? 60))],
            default => $status >= 500
                ? ['server_error', __('core.errors.server_error')]
                : ['http_error', __('core.errors.http_error')],
        };

        return self::json($status, $code, $message, headers: $headers);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $extra
     */
    private static function json(
        int $status,
        string $code,
        string $message,
        array $errors = [],
        array $extra = [],
        array $headers = [],
    ): JsonResponse {
        $body = ['message' => $message, 'code' => $code];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse(array_merge($body, $extra), $status, $headers);
    }

    /** RFC 9457 members over the usual body, for API key clients. */
    private static function problem(JsonResponse $response): JsonResponse
    {
        $body = $response->getData(true);
        $status = $response->getStatusCode();
        $code = (string) ($body['code'] ?? 'http_error');
        $message = (string) ($body['message'] ?? '');

        $response->setData(array_merge([
            'type' => '/developers/errors#'.$code,
            'title' => $message,
            'status' => $status,
            'detail' => $message,
        ], $body));
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    /** "Try again in N second(s)", pluralised per locale. */
    public static function retryMessage(int $seconds): string
    {
        return trans_choice('core.errors.too_many_requests', $seconds, ['seconds' => $seconds]);
    }
}
