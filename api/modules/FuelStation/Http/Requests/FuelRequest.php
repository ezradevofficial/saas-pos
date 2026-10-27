<?php

namespace Modules\FuelStation\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Modules\FuelStation\Support\Access;

/**
 * What the module's requests share (RBAC-04, RBAC-09): the user must hold the
 * request's permission at the station of the record the route names (a route
 * record carries its `location_id`), or somewhere when the route names none;
 * once the body is valid, the places it names are checked too: a `location_id`
 * field, and records named by id (`bodyRecords()`: field => table), so a user
 * acts only at stations where they hold the permission. Ids are checked with
 * `exists` under row-level security: another tenant's id does not exist (422),
 * and a record of another tenant in the route is not found (404).
 */
abstract class FuelRequest extends FormRequest
{
    abstract protected function permission(): string;

    /** The route parameter bound to a model with a `location_id` (a station record), if the route names one. */
    protected function routeRecord(): ?string
    {
        return null;
    }

    /** The route parameter bound to a model with a `company_id` (a company record), if the route names one. */
    protected function routeCompanyRecord(): ?string
    {
        return null;
    }

    /** Whether a company record's changes are company-wide: the permission must be held for the whole company. */
    protected function wide(): bool
    {
        return false;
    }

    /** The body field holding a location id the permission is needed at. */
    protected function locationField(): ?string
    {
        return null;
    }

    /** The body field holding a company id the permission is needed at. */
    protected function companyField(): ?string
    {
        return null;
    }

    /** @return array<string, string> body field (a record id) => table of a station record, whose location the permission is needed at */
    protected function bodyRecords(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $access = app(Access::class);

        if (($name = $this->routeRecord()) !== null) {
            $record = $this->route($name);

            return $record instanceof Model && $access->can($user, $this->permission(), $record->getAttribute('location_id'));
        }

        if (($name = $this->routeCompanyRecord()) !== null) {
            $record = $this->route($name);

            return $record instanceof Model && ($this->wide()
                ? $access->canCompanyWide($user, $this->permission(), $record->getAttribute('company_id'))
                : $access->canCompany($user, $this->permission(), $record->getAttribute('company_id')));
        }

        return app(ScopeResolver::class)->can($user, $this->permission());
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $access = app(Access::class);

            if (($field = $this->locationField()) !== null && $this->filled($field)) {
                $access->require($this->user(), $this->permission(), (string) $this->input($field));
            }

            if (($field = $this->companyField()) !== null && $this->filled($field)) {
                $access->requireCompany($this->user(), $this->permission(), (string) $this->input($field), $this->wide());
            }

            foreach ($this->bodyRecords() as $field => $table) {
                if ($this->filled($field)) {
                    $location = DB::table($table)->where('id', $this->input($field))->value('location_id');

                    if ($location !== null) {
                        $access->require($this->user(), $this->permission(), (string) $location);
                    }
                }
            }
        }];
    }

    /** A model the route bound (see routes/api.php), or null. */
    protected function bound(string $parameter): ?Model
    {
        $model = $this->route($parameter);

        return $model instanceof Model ? $model : null;
    }
}
