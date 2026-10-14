<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Requests\Concerns\ListsSetup;

/** Lists of company setup rows (HR-38): subclasses name the view permission and whether rows archive. */
abstract class SetupListRequest extends FormRequest
{
    use ListsSetup;
}
