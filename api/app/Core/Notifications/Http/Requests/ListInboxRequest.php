<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Notifications\Http\Lists\InboxList;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * NOT-01: GET notifications, the signed-in user's own inbox: `?status=`
 * active (default: not archived), unread (not archived), archived or all;
 * `?search=` (subject or message), `?sort` (newest first by default),
 * pages of `?per_page` (50, at most 200) and an export (InboxList, EXP-01).
 * Every user has an inbox: no permission is needed.
 */
class ListInboxRequest extends FormRequest
{
    use SortsAndExports;

    public const STATUSES = ['active', 'unread', 'archived', 'all'];

    public function list(): ListDefinition
    {
        return new InboxList;
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
