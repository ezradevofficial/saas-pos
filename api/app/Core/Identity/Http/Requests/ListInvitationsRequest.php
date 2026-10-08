<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Http\Lists\InvitationList;
use App\Core\Identity\Models\Invitation;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * AUTH-05: GET invitations, for holders of `core.user.invite` (the list is
 * filtered to their scope by the controller, RBAC-04): `?search=` (name,
 * email or phone), `?status` (open by default: pending or expired, the
 * ones still to act on; or pending, expired, accepted, revoked, all),
 * `?per_page` (50, at most 200), `?sort` (newest first by default) and an
 * export (`?format`, `?columns[]`; InvitationList, EXP-01). The status
 * filter applies to the JSON list and the export alike.
 */
class ListInvitationsRequest extends ListRequest
{
    use SortsAndExports;

    private ?InvitationList $definition = null;

    public function list(): ListDefinition
    {
        return $this->definition ??= new InvitationList($this->user());
    }

    public function authorize(): bool
    {
        return $this->user()->can(InvitationList::PERMISSION);
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
        ];
    }

    public const STATUSES = ['open', Invitation::STATUS_PENDING, Invitation::STATUS_EXPIRED, Invitation::STATUS_ACCEPTED, Invitation::STATUS_REVOKED, 'all'];

    public function invitationStatus(): string
    {
        return (string) $this->validated('status', 'open');
    }

    /**
     * `?status`, as Invitation::status() reads a row: accepted wins over
     * revoked, which wins over expired; "open" is pending or expired.
     */
    public function applyStatus(Builder $query): Builder
    {
        $unanswered = fn (Builder $q) => $q->whereNull('accepted_at')->whereNull('revoked_at');

        return match ($this->invitationStatus()) {
            'open' => $unanswered($query),
            Invitation::STATUS_PENDING => $unanswered($query)->where('expires_at', '>', now()),
            Invitation::STATUS_EXPIRED => $unanswered($query)->where('expires_at', '<=', now()),
            Invitation::STATUS_ACCEPTED => $query->whereNotNull('accepted_at'),
            Invitation::STATUS_REVOKED => $query->whereNull('accepted_at')->whereNotNull('revoked_at'),
            default => $query,
        };
    }
}
