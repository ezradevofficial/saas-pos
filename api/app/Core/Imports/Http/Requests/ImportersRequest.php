<?php

namespace App\Core\Imports\Http\Requests;

use App\Core\MasterData\CompanyReach;
use Illuminate\Foundation\Http\FormRequest;

/** IMP-01: the importers the user may run, with their columns (`core.import.run`). */
class ImportersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CompanyReach::class)->anywhere($this->user(), ['core.import.run']);
    }

    public function rules(): array
    {
        return [];
    }
}
