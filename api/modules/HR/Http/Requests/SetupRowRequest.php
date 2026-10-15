<?php

namespace Modules\HR\Http\Requests;

use App\Core\Rbac\HasScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Reading or changing one setup row (HR-38): not found unless the reader
 * reads its company's setup (the view permission at, above or below it);
 * changing needs the manage permission at the row's own scope (its
 * company, or a roster's branch).
 */
abstract class SetupRowRequest extends HrRequest
{
    abstract protected function parameter(): string;

    abstract protected function viewPermission(): string;

    abstract protected function managePermission(): string;

    /** @return array<string, mixed> */
    protected function fields(): array
    {
        return [];
    }

    public function row(): Model&HasScope
    {
        return $this->route($this->parameter());
    }

    public function authorize(): bool
    {
        $row = $this->row();
        abort_unless($this->access()->readsCompany($this->user(), $this->viewPermission(), $row->company_id) || $this->access()->at($this->user(), $this->managePermission(), $row->scope()), 404);

        return $this->isMethod('GET') || $this->access()->at($this->user(), $this->managePermission(), $row->scope());
    }

    public function rules(): array
    {
        return match (true) {
            $this->isMethod('PATCH') || $this->isMethod('PUT') => $this->fields(),
            // A read takes no action fields (TEN-01: no query parameter the isolation suite does not send).
            $this->isMethod('GET') || $this->isMethod('HEAD') => [],
            default => $this->actionFields(),
        };
    }

    /** @return array<string, mixed> the body of a POST action */
    protected function actionFields(): array
    {
        return [];
    }
}
