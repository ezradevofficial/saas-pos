<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET procurement/branches/{branch}/committee-candidates (PRC-06): for
 * whoever drafts tenders there (`procurement.tender.create` at the branch,
 * no `core.user.view` needed): the active users who may sit on a committee
 * at the branch, `?role=opener|evaluator`.
 */
class CommitteeCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');

        return app(ScopeResolver::class)->can($this->user(), 'procurement.tender.create', Scope::branch($branch->id));
    }

    public function rules(): array
    {
        return ['role' => ['sometimes', 'string', Rule::in(['opener', 'evaluator'])]];
    }
}
