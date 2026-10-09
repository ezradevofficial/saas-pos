<?php

namespace App\Core\Layouts\Http\Requests;

use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Layouts\Dashboards\DashboardSources;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LAY-01: GET dashboard/sources/{dashboard_source}?<params>: one widget's
 * data. A source that is not registered, or whose module is off for the
 * tenant (RBAC-08), is not found; one the user may not read is refused
 * (403, RBAC-09). The parameters are checked by the source's own rules.
 */
class DashboardSourceRequest extends FormRequest
{
    private ?DashboardSource $resolved = null;

    public function authorize(): bool
    {
        return $this->source()->available($this->user());
    }

    public function rules(): array
    {
        return $this->source()->rules();
    }

    public function source(): DashboardSource
    {
        return $this->resolved ??= app(DashboardSources::class)->find((string) $this->route('dashboard_source')) ?? abort(404);
    }
}
