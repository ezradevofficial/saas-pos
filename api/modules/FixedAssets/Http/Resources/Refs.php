<?php

namespace Modules\FixedAssets\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\FixedAssets\Assets\Custodians;
use Modules\FixedAssets\Models\Asset;

/** How the module's resources show related records: `{id, name}` (and a code or number where it has one). */
final class Refs
{
    /** @return array{id: string, name: string}|null */
    public static function of(?Model $model, string $label = 'name'): ?array
    {
        return $model === null ? null : ['id' => (string) $model->getKey(), 'name' => (string) $model->getAttribute($label)];
    }

    /** @return array{id: string, code: string, name: string}|null */
    public static function category(?Model $model): ?array
    {
        return $model === null ? null : ['id' => (string) $model->getKey(), 'code' => (string) $model->getAttribute('code'), 'name' => (string) $model->getAttribute('name')];
    }

    /** @return array{id: string, number: string, name: string, location_id: string}|null */
    public static function asset(?Asset $asset): ?array
    {
        return $asset === null ? null : ['id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name, 'location_id' => $asset->location_id];
    }

    /**
     * FA-04: a custodian as `{type: user|employee, id, name}`; an employee's
     * name comes through HR's read contract (kept per request), empty
     * while HR is off.
     *
     * @return array{type: string, id: string, name: string}|null
     */
    public static function custodian(Request $request, ?Model $user, ?string $employeeId): ?array
    {
        if ($user !== null) {
            return ['type' => 'user', 'id' => (string) $user->getKey(), 'name' => (string) $user->getAttribute('name')];
        }

        if ($employeeId === null) {
            return null;
        }

        $names = $request->attributes->get('fixed_assets.employees', []);

        if (! array_key_exists($employeeId, $names)) {
            $names[$employeeId] = app(Custodians::class)->employeeNames([$employeeId])[$employeeId] ?? '';
            $request->attributes->set('fixed_assets.employees', $names);
        }

        return ['type' => 'employee', 'id' => $employeeId, 'name' => (string) $names[$employeeId]];
    }

    public static function date(mixed $value): ?string
    {
        return $value?->toDateString();
    }
}
