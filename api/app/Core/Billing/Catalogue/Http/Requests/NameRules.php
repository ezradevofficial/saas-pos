<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

/** Names typed by staff in both languages. */
trait NameRules
{
    /** @return array<string, list<mixed>> */
    protected function nameRules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'array:en,fr'],
            'name.en' => [$presence, 'string', 'max:120'],
            'name.fr' => [$presence, 'string', 'max:120'],
        ];
    }
}
