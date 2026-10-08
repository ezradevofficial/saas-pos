<?php

namespace App\Core\MasterData\Items\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-02: a file behind a temporary signed URL (`signed` middleware). The
 * signature is the credential; MediaController enters the file's tenant
 * and checks the signed-for user may still view the item.
 */
class MediaRequest extends FormRequest
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
