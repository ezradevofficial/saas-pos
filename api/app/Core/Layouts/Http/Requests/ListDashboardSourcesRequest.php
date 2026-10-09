<?php

namespace App\Core\Layouts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAY-01: GET dashboard/sources: the data sources the signed-in user may
 * read (active modules only, RBAC-08), for the designer's widget picker
 * and the dashboard. Every signed-in user may ask; the list is theirs.
 */
class ListDashboardSourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
