<?php

namespace App\Core\Workflow\DocumentTypes;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\FieldRules;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\Str;

/**
 * The display value of a reference field (AUTO-03 placeholders, AUTO-04
 * test mode, webhook `<field>_label`): the name of the record it points at
 * for the platform's own targets (core.party, core.company, core.branch,
 * core.location, core.user), read inside the tenant's context (row-level
 * security keeps it to the tenant). A party's name hidden from $viewer by
 * the party field rules (RBAC-05) shows as an empty string; a target the
 * platform does not know is left out (the caller falls back to the id).
 */
class ReferenceLabels
{
    /** target => model class */
    private const MODELS = [
        'core.party' => Party::class,
        'core.company' => Company::class,
        'core.branch' => Branch::class,
        'core.location' => Location::class,
        'core.user' => User::class,
    ];

    /** The party field rules resource (RBAC-05) its name follows. */
    private const PARTY_FIELD_RULES = 'party';

    public function __construct(private readonly FieldRules $fieldRules) {}

    public static function knows(?string $target): bool
    {
        return $target !== null && isset(self::MODELS[$target]);
    }

    /**
     * @param  list<FieldDefinition>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string> field name => display value, for the reference fields with a known target and a value
     */
    public function of(array $fields, array $values, ?User $viewer = null): array
    {
        $wanted = [];

        foreach ($fields as $field) {
            $id = $values[$field->name] ?? null;

            if ($field->type === 'reference' && self::knows($field->reference) && is_string($id) && Str::isUuid($id)) {
                $wanted[$field->reference][$field->name] = $id;
            }
        }

        $labels = [];

        foreach ($wanted as $target => $byField) {
            $names = (self::MODELS[$target])::query()->whereKey(array_values(array_unique($byField)))->pluck('name', 'id')->all();
            $hidden = $target === 'core.party' && $viewer !== null
                && in_array('name', $this->fieldRules->for($viewer, self::PARTY_FIELD_RULES)['hidden'], true);

            foreach ($byField as $name => $id) {
                $labels[$name] = $hidden ? '' : (string) ($names[$id] ?? '');
            }
        }

        return $labels;
    }
}
