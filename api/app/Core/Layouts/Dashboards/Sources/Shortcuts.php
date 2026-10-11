<?php

namespace App\Core\Layouts\Dashboards\Sources;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;

/**
 * LAY-01: links to pages of the app. The routes are the widget's own
 * parameters; the web shows only those in the reader's navigation (its
 * permissions and modules, RBAC-09), so a shortcut never grants a page.
 */
class Shortcuts extends DashboardSource
{
    // An app path: one leading slash (never `//host`, a protocol-relative URL), no trailing newline.
    public const ROUTE = '/\A\/(?!\/)[a-z0-9\/_-]{0,120}\z/';

    public function key(): string
    {
        return 'shortcuts';
    }

    public function widgets(): array
    {
        return [self::SHORTCUT];
    }

    public function rules(): array
    {
        return [
            'links' => ['sometimes', 'array', 'max:12'],
            'links.*' => ['string', 'regex:'.self::ROUTE],
        ];
    }

    public function data(User $user, array $params): array
    {
        return ['links' => array_values(array_unique(array_map('strval', $params['links'] ?? [])))];
    }
}
