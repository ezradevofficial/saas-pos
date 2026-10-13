<?php

namespace App\Core\Imports;

/**
 * IMP-01: one column an importer reads. Built-in columns are labelled from
 * `imports.columns.<key>` (with help from `imports.help.<key>`) in the
 * reader's language; custom field columns (`cf_<key>`, CF-03) carry the
 * field's label, single-language tenant text typed once.
 *
 * Types say how a cell is read (ImportCells): text, integer, decimal,
 * date, boolean, list (several values separated by ; or ,), enum (one of
 * `options`, by value or by its label in either language) and the custom
 * field types.
 */
final class ImportColumn
{
    /**
     * @param  list<string>  $options  enum values
     * @param  string|null  $optionsKey  translation prefix of the enum labels (`<prefix>.<value>`)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type = 'text',
        public readonly bool $required = false,
        public readonly array $options = [],
        public readonly ?string $optionsKey = null,
        public readonly ?string $label = null,
        public readonly ?string $help = null,
        public readonly ?string $example = null,
    ) {}

    public static function text(string $key, bool $required = false, ?string $example = null): self
    {
        return new self($key, 'text', $required, example: $example);
    }

    public static function of(string $key, string $type, bool $required = false, ?string $example = null): self
    {
        return new self($key, $type, $required, example: $example);
    }

    /** @param list<string> $options */
    public static function enum(string $key, array $options, string $optionsKey, bool $required = false, ?string $example = null): self
    {
        return new self($key, 'enum', $required, $options, $optionsKey, example: $example);
    }

    public function isCustom(): bool
    {
        return str_starts_with($this->key, 'cf_');
    }

    public function label(?string $locale = null): string
    {
        return $this->label ?? __("imports.columns.{$this->key}", [], $locale);
    }

    public function help(?string $locale = null): string
    {
        if ($this->help !== null) {
            return $this->help;
        }

        $key = "imports.help.{$this->key}";
        $help = __($key, [], $locale);

        return $help === $key ? '' : $help;
    }

    /** @return list<string> the header texts this column is recognised by (every language) */
    public function headerNames(): array
    {
        $names = [$this->key, $this->label('en'), $this->label('fr')];

        if ($this->isCustom()) {
            $names[] = substr($this->key, 3);
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label(),
            'help' => $this->help(),
            'type' => $this->type,
            'required' => $this->required,
            'options' => array_map(fn (string $value) => [
                'value' => $value,
                'label' => $this->optionsKey === null ? $value : __("{$this->optionsKey}.{$value}"),
            ], $this->options),
        ];
    }
}
