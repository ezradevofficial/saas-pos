<?php

namespace App\Core\DocumentTemplates;

use App\Core\CustomFields\CustomFieldMergeFields;
use Closure;

/**
 * TPL-01: the registry of data sources, one per document type, and the
 * merge field catalogue the designer offers.
 *
 * Standard groups every type has: company, branch, location, customer,
 * totals, payments and fiscal; the source adds its document fields and
 * line columns.
 *
 * Custom fields (CF-03) join with one line per entity group; parties and
 * items are wired in DocumentTemplatesServiceProvider:
 *
 *   app(DataSources::class)->customFields('customer', fn () => [...]);
 *
 * The closure returns `[{key, label, type}]` (label as typed by the
 * tenant, type text|money|date|qty|rate); the fields appear as
 * `customer.custom.{key}` (for `lines`: the `custom.{key}` line column).
 * Data builders put the record's custom values as display text into the
 * data (`customer.custom`, each line's `custom`; see DataSources::customValues).
 */
class DataSources
{
    public const TYPES = ['text', 'money', 'date', 'qty', 'rate'];

    /** Standard fields: path => type. */
    public const STANDARD = [
        'company.name' => 'text',
        'company.legal_name' => 'text',
        'company.tax_id' => 'text',
        'company.address' => 'text',
        'company.phone' => 'text',
        'company.email' => 'text',
        'branch.name' => 'text',
        'branch.code' => 'text',
        'branch.address' => 'text',
        'location.name' => 'text',
        'location.code' => 'text',
        'document.number' => 'text',
        'document.date' => 'date',
        'document.currency' => 'text',
        'customer.name' => 'text',
        'customer.tax_id' => 'text',
        'customer.phone' => 'text',
        'customer.email' => 'text',
        'customer.address' => 'text',
        'totals.subtotal' => 'money',
        'totals.discount' => 'money',
        'totals.tax' => 'money',
        'totals.total' => 'money',
        'fiscal.invoice_number' => 'text',
        'fiscal.receipt_number' => 'text',
        'fiscal.receipt_signature' => 'text',
        'fiscal.internal_data' => 'text',
        'fiscal.control_unit_id' => 'text',
        'fiscal.authority_time' => 'text',
    ];

    /** @var array<string, DataSource> */
    private array $sources = [];

    /** @var array<string, list<Closure(): list<array{key: string, label: string, type?: string}>>> */
    private array $custom = [];

    public function register(DataSource $source): void
    {
        $this->sources[$source->type()] = $source;
    }

    public function find(string $type): ?DataSource
    {
        return $this->sources[$type] ?? null;
    }

    public function get(string $type): DataSource
    {
        return $this->find($type) ?? throw new \InvalidArgumentException("No data source for [{$type}].");
    }

    /** CF-01 hook: custom fields of an entity group (customer, company, lines). */
    public function customFields(string $group, Closure $definitions): void
    {
        $this->custom[$group][] = $definitions;
    }

    /**
     * Every merge field of $type: path => type.
     *
     * @return array<string, string>
     */
    public function fields(string $type): array
    {
        $fields = [...self::STANDARD, ...$this->get($type)->documentFields()];

        foreach ($this->custom as $group => $providers) {
            if ($group === 'lines') {
                continue;
            }

            foreach ($this->customDefinitions($group) as $definition) {
                $fields["{$group}.custom.{$definition['key']}"] = $definition['type'];
            }
        }

        return $fields;
    }

    /** @return array<string, string> column => type */
    public function columns(string $type): array
    {
        $columns = $this->get($type)->lineColumns();

        foreach ($this->customDefinitions('lines') as $definition) {
            $columns['custom.'.$definition['key']] = $definition['type'];
        }

        return $columns;
    }

    /**
     * Labels the tenant typed for custom fields (printed as entered), by path.
     *
     * @return array<string, string>
     */
    public function customLabels(): array
    {
        $labels = [];

        foreach (array_keys($this->custom) as $group) {
            foreach ($this->customDefinitions($group) as $definition) {
                $labels[$group === 'lines' ? 'custom.'.$definition['key'] : "{$group}.custom.{$definition['key']}"] = $definition['label'];
            }
        }

        return $labels;
    }

    /** @return list<array{key: string, label: string, type: string}> */
    private function customDefinitions(string $group): array
    {
        $definitions = [];

        foreach ($this->custom[$group] ?? [] as $provider) {
            foreach ($provider() as $definition) {
                $key = (string) ($definition['key'] ?? '');

                if (preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key) === 1) {
                    $type = (string) ($definition['type'] ?? 'text');
                    $definitions[] = ['key' => $key, 'label' => (string) ($definition['label'] ?? $key), 'type' => in_array($type, self::TYPES, true) ? $type : 'text'];
                }
            }
        }

        return $definitions;
    }

    /**
     * CF-03: a record's custom values as display text, keyed by field key,
     * for a data builder (`'custom' => DataSources::customValues('party', $party->custom)`).
     *
     * @return array<string, string>
     */
    public static function customValues(string $entity, ?array $custom): array
    {
        $values = [];

        foreach (app(CustomFieldMergeFields::class)->values($entity, null, $custom) as $name => $text) {
            if ($text !== '') {
                $values[substr($name, strlen(CustomFieldMergeFields::PREFIX))] = $text;
            }
        }

        return $values;
    }
}
