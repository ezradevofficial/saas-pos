<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\DisciplinaryList;
use Modules\HR\Http\Requests\Concerns\ListsHr;
use Modules\HR\Support\Sensitive;

/** GET hr/disciplinary-cases (HR-29): `hr.disciplinary.view`, field `disciplinary` not hidden (RBAC-05). */
class ListDisciplinaryRequest extends FormRequest
{
    use ListsHr {
        ListsHr::authorize as listAuthorize;
    }

    public function list(): ListDefinition
    {
        return new DisciplinaryList;
    }

    protected function viewPermission(): string
    {
        return 'hr.disciplinary.view';
    }

    protected function statuses(): array
    {
        return ['open', 'closed'];
    }

    public function authorize(): bool
    {
        return $this->listAuthorize() && ! app(Sensitive::class)->hides($this->user(), 'disciplinary', $this);
    }
}
