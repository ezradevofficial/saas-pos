<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;
use Throwable;

/**
 * Settings screens that would otherwise be empty in the manual's pictures: a custom field, a form type, a
 * workflow draft, an automation rule (from a template, switched off), a webhook and an API key. Names only:
 * the URL is a placeholder that is never called and the API key's secret is thrown away. Each step checks what
 * exists first, so a second run adds nothing.
 */
class SettingsSection
{
    public function run(DemoData $demo): void
    {
        $api = $demo->api;
        $company = $demo->ids['company'];

        $this->attempt(function () use ($api) {
            if (collect($api->rows('custom-fields', ['entity' => 'item']))->firstWhere('key', 'licence_no') === null) {
                $api->post('custom-fields', ['entity' => 'item', 'key' => 'licence_no', 'label' => 'Licence number', 'type' => 'text', 'help' => 'The supplier licence, if the item needs one.', 'required' => false]);
            }
        });

        $this->attempt(function () use ($api) {
            if (collect($api->rows('custom-form-types'))->firstWhere('key', 'petty_cash') === null) {
                $api->post('custom-form-types', ['key' => 'petty_cash', 'name' => 'Petty cash request', 'description' => 'Small cash needs of a branch.', 'workflow' => true, 'has_lines' => false, 'attachments' => true, 'line_fields' => [], 'role_ids' => []]);
            }
        });

        $this->attempt(function () use ($api, $company) {
            if ($api->rows('workflows') === []) {
                $types = $api->get('workflow/document-types')['data'] ?? [];
                $type = collect($types)->first(fn ($t) => str_contains($t['key'], 'purchase')) ?? ($types[0] ?? null);

                if ($type !== null) {
                    $api->post('workflows', ['document_type' => $type['key'], 'company_id' => $company]);
                }
            }
        });

        $this->attempt(function () use ($api, $company) {
            if ($api->rows('automation-rules') !== []) {
                return;
            }

            $types = collect($api->get('automation/catalogue')['data'] ?? [])->pluck('key');
            $type = $types->contains('procurement.po') ? 'procurement.po' : $types->first();

            if ($type !== null) {
                $api->post('automation-rules', [
                    'name' => 'Tell the branch manager when an order changes stage', 'document_type' => $type, 'company_id' => $company,
                    'trigger' => ['type' => 'stage_entered'], 'conditions' => null,
                    'actions' => [['type' => 'notify', 'to' => ['role:template:branch_manager'], 'subject' => 'An order moved to a new stage', 'message' => 'Please have a look at it.']],
                ]);
            }
        });

        $this->attempt(function () use ($api, $company) {
            if ($api->rows('webhooks') !== []) {
                return;
            }

            $events = collect($api->get('webhooks/catalogue')['data'] ?? [])->map(fn ($e) => $e['type'] ?? $e['key'] ?? null)->filter()->values();
            $event = $events->first(fn ($e) => str_contains($e, 'sale')) ?? $events->first();

            if ($event !== null) {
                $api->post('webhooks', ['url' => 'https://hooks.example.co.ke/accounting/demo', 'description' => 'Accounting sync', 'events' => [$event], 'company_id' => $company, 'branch_id' => null]);
            }
        });

        $this->attempt(function () use ($api) {
            if ($api->rows('api-keys') !== []) {
                return;
            }

            $role = collect($api->rows('roles'))->firstWhere('template_key', 'read_only_auditor') ?? collect($api->rows('roles'))->firstWhere('template_key', 'accountant');
            $api->post('api-keys', ['name' => 'Accounting sync', 'role_id' => $role['id'], 'scope_type' => 'tenant', 'scope_id' => null, 'scopes' => ['core.item.read'], 'expires_at' => null]);
        });
    }

    /** A step that cannot run here (a module off, a rule that changed) must not stop the others. */
    private function attempt(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            fwrite(STDERR, 'Demo data: settings step skipped: '.$e->getMessage().PHP_EOL);
        }
    }
}
