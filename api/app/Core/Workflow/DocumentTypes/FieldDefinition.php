<?php

namespace App\Core\Workflow\DocumentTypes;

use InvalidArgumentException;

/**
 * One field of a document type the engine may read (WF-01): conditions
 * (WF-04, WF-05, AUTO-02), next-document mappings (WF-07) and the builder's
 * pickers use it. Values come from the type's fieldValues() accessor:
 *
 *   string, enum, reference  a string (a reference is an id)
 *   number                   an int or a decimal string ("12.5"), never a float
 *   money                    ['amount_minor' => int|string, 'currency' => 'KES'] or a Money
 *   date                     'Y-m-d' or an ISO 8601 date-time string
 *   boolean                  true or false
 *   null                     no value (the `empty` comparison)
 */
final class FieldDefinition
{
    public const TYPES = ['string', 'number', 'money', 'date', 'enum', 'reference', 'boolean'];

    /**
     * @param  string  $name  the key in fieldValues(), e.g. `total`
     * @param  string  $label  translation key of the label
     * @param  list<string>  $values  enum values (labels: "{$label}_values.{value}" is not required; the builder shows them as given)
     * @param  ?string  $reference  for a reference, what it points at (e.g. `core.party`)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly array $values = [],
        public readonly ?string $reference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid field name [{$name}].");
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown field type [{$type}] for [{$name}].");
        }

        if ($type === 'enum' && $values === []) {
            throw new InvalidArgumentException("The enum field [{$name}] has no values.");
        }
    }

    public static function string(string $name, string $label): self
    {
        return new self($name, 'string', $label);
    }

    public static function number(string $name, string $label): self
    {
        return new self($name, 'number', $label);
    }

    public static function money(string $name, string $label): self
    {
        return new self($name, 'money', $label);
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, 'date', $label);
    }

    public static function boolean(string $name, string $label): self
    {
        return new self($name, 'boolean', $label);
    }

    /** @param list<string> $values */
    public static function enum(string $name, string $label, array $values): self
    {
        return new self($name, 'enum', $label, array_values($values));
    }

    public static function reference(string $name, string $label, string $to): self
    {
        return new self($name, 'reference', $label, [], $to);
    }

    /** @return array{name: string, type: string, label: string, values: list<string>, reference: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => __($this->label),
            'values' => $this->values,
            'reference' => $this->reference,
        ];
    }
}
