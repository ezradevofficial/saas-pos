<?php

namespace Modules\HR\Http\Resources;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\HR\Models\Employee;

/** Small references to records HR's resources name (as entered, single language). */
final class Refs
{
    /** @return array{id: string, name: string}|null a company, branch or location */
    public static function named(?Model $record): ?array
    {
        return $record === null ? null : ['id' => (string) $record->getKey(), 'name' => (string) $record->getAttribute('name')];
    }

    /** @return array{id: string, code: string, name: string}|null a department, shift or leave type */
    public static function coded(?Model $record): ?array
    {
        return $record === null ? null : ['id' => (string) $record->getKey(), 'code' => (string) $record->getAttribute('code'), 'name' => (string) $record->getAttribute('name')];
    }

    /** @return array{id: string, number: string, name: string}|null */
    public static function employee(?Employee $employee): ?array
    {
        return $employee === null ? null : ['id' => $employee->id, 'number' => $employee->number, 'name' => $employee->displayName()];
    }

    /** @return array{id: string, name: string}|null */
    public static function user(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    public static function at(mixed $value): ?string
    {
        return $value === null ? null : $value->toIso8601String();
    }

    public static function date(mixed $value): ?string
    {
        return $value === null ? null : $value->toDateString();
    }

    /** @return array{amount_minor: string, currency: string} */
    public static function money(int|string $minor, string $currency): array
    {
        return ['amount_minor' => (string) $minor, 'currency' => $currency];
    }
}
