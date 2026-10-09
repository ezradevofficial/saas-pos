<?php

namespace App\Core\Layouts\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;

/**
 * LAY-01: a server data source a dashboard widget is bound to. A module
 * registers its sources with DashboardSources; a source of an inactive
 * module is not found (RBAC-08).
 *
 * - `widgets()`: the widget types it can feed (kpi, chart, list,
 *   shortcut, approval_count);
 * - `permissions()`: any one of them, held anywhere, opens the source
 *   (none: every signed-in user, e.g. their own approvals); a widget whose
 *   source the reader can't open is not rendered;
 * - `rules()`: Laravel rules for the widget's parameters, checked when a
 *   dashboard is published and when the data is asked for;
 * - `data()` returns only what the reader reaches (RBAC-04, RBAC-05).
 *
 * Shapes by widget type: kpi and approval_count `{kind: number|money,
 * value, currency?, count?, complete?}`, chart `{kind, currency?, points:
 * [{label, value}]}`, list `{rows: [{id, title, subtitle, to, at}],
 * total}`, shortcut `{links: [route]}`. Money is in minor units, as
 * strings, with its currency (no floats).
 */
abstract class DashboardSource
{
    public const KPI = 'kpi';

    public const CHART = 'chart';

    public const LIST = 'list';

    public const SHORTCUT = 'shortcut';

    public const APPROVAL_COUNT = 'approval_count';

    public const WIDGETS = [self::KPI, self::CHART, self::LIST, self::SHORTCUT, self::APPROVAL_COUNT];

    /** `module.name`, e.g. `pos.sales_today`. */
    abstract public function key(): string;

    /** @return list<string> */
    abstract public function widgets(): array;

    /**
     * @param  array<string, mixed>  $params  validated against rules()
     * @return array<string, mixed>
     */
    abstract public function data(User $user, array $params): array;

    public function module(): string
    {
        return 'core';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [];
    }

    /** @return array<string, mixed> Laravel validation rules of the parameters */
    public function rules(): array
    {
        return [];
    }

    /** The source's name in the designer (a translation key). */
    public function label(): string
    {
        return 'layouts.sources.'.str_replace('.', '_', $this->key());
    }

    /** Whether $user may read this source anywhere (RBAC-09). */
    public function available(User $user): bool
    {
        $permissions = $this->permissions();

        if ($permissions === []) {
            return true;
        }

        $resolver = app(ScopeResolver::class);

        foreach ($permissions as $permission) {
            if ($resolver->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function feeds(string $widget): bool
    {
        return in_array($widget, $this->widgets(), true);
    }
}
