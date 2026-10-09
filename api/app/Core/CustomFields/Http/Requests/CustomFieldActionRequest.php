<?php

namespace App\Core\CustomFields\Http\Requests;

/** TEN-06: archive or restore a custom field (`core.custom_field.manage`). No body. */
class CustomFieldActionRequest extends CustomFieldRequest
{
    protected bool $manages = true;
}
