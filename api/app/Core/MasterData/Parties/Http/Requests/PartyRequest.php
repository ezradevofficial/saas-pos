<?php

namespace App\Core\MasterData\Parties\Http\Requests;

use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-01: one party. Not reached with any party permission: 404 (RBAC-04);
 * reached without this request's ability: 403 (PartyPolicy).
 */
class PartyRequest extends FormRequest
{
    /** The PartyPolicy ability: view, update, archive. */
    protected string $ability = 'view';

    public function authorize(): bool
    {
        $policy = app(PartyPolicy::class);

        abort_unless($policy->reach($this->user(), $this->party()), 404);

        return $policy->{$this->ability}($this->user(), $this->party());
    }

    public function rules(): array
    {
        return [];
    }

    public function party(): Party
    {
        return $this->route('party');
    }
}
