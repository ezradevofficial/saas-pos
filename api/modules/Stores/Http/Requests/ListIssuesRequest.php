<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Lists\ListDefinition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Stores\Http\Lists\IssueList;
use Modules\Stores\Models\Issue;

/**
 * GET stores/issues (STR-04, STR-05, STR-18): `stores.issue.view`; issues
 * at the reader's stores; `?status=issued` (waiting for acknowledgement)
 * or `acknowledged`, `?department=`, `?cost_centre=`, `?item=`, `?from=`,
 * `?to=`, `?search=` (number or note).
 */
class ListIssuesRequest extends FormRequest
{
    use ListsStores;

    public function list(): ListDefinition
    {
        return new IssueList;
    }

    protected function viewPermission(): string
    {
        return 'stores.issue.view';
    }

    protected function statuses(): array
    {
        return Issue::STATUSES;
    }

    protected function documentsWithItem(string $itemId): Closure
    {
        return fn ($q) => $q->select('issue_id')->from('stores_issue_lines')->where('item_id', $itemId);
    }
}
