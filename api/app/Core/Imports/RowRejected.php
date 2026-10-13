<?php

namespace App\Core\Imports;

use App\Core\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * IMP-02: a row the domain refused, with its errors by input field
 * (`code`, `barcodes.0.barcode`, `custom.colour`, or `_` for the row as a
 * whole). Built from what the Form Requests, policies and writers throw
 * for the API, so an imported row meets exactly the API's rules.
 */
final class RowRejected extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) collect($errors)->flatten()->first());
    }

    public static function row(string $message): self
    {
        return new self(['_' => [$message]]);
    }

    /** The errors in $e when the domain refused the row; null for any other failure (rethrown). */
    public static function from(Throwable $e): ?self
    {
        return match (true) {
            $e instanceof self => $e,
            $e instanceof ValidationException => new self($e->errors()),
            $e instanceof ApiException && $e->getStatusCode() < 500 => new self($e->errors !== [] ? $e->errors : ['_' => [$e->getMessage()]]),
            $e instanceof AuthorizationException => self::row(__('imports.errors.forbidden')),
            $e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [403, 404], true) => self::row(__('imports.errors.not_reached')),
            default => null,
        };
    }
}
