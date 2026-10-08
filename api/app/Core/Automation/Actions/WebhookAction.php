<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Jobs\SendWebhookDelivery;
use App\Core\Automation\Models\WebhookDelivery;
use App\Core\Automation\Runtime\WebhookPayload;
use App\Core\Automation\Webhooks\WebhookSender;
use App\Core\Workflow\DocumentTypes\DocumentType;
use Illuminate\Support\Str;

/**
 * AUTO-03 "call webhook": `{"type": "webhook", "url": "https://..."}` POSTs
 * JSON to an HTTPS endpoint, signed with the rule's secret (Signature):
 *
 *   {"event": "automation.rule_run", "id": "<run id>:<action index>", "occurred_at": "...Z",
 *    "rule": {"id", "name", "version"}, "trigger": {"type", ...},
 *    "document": {"type", "id"} | null, "fields": {...the document's values}}
 *
 * A reference field keeps its id and gains `<field>_label`, its display
 * value (DocumentType::displayValues(), e.g. the party's name).
 *
 * Running the action only writes a WebhookDelivery (an outbox row) in the
 * run's transaction: the run never waits on HTTP, and nothing is sent for
 * a run that rolled back. The row holds the whole payload, `fields`
 * snapshotted now (after the rule's earlier actions) without the fields
 * the rule's user may not see (WebhookPayload, RBAC-05). WebhookDeliveries
 * sends it after commit, with its own retries, always that same snapshot.
 */
class WebhookAction implements AutomationAction
{
    public const KEY = 'webhook';

    public function key(): string
    {
        return self::KEY;
    }

    public function needsDocument(): bool
    {
        return false;
    }

    public function validate(array $action, RuleContext $rule): array
    {
        $problems = [];

        if (($reason = WebhookSender::urlProblem($action['url'] ?? null)) !== null) {
            $problems[] = __('automation.webhook.refused.'.$reason);
        }

        if (array_diff(array_keys($action), ['type', 'url']) !== []) {
            $problems[] = __('automation.validation.action_extra');
        }

        return $problems;
    }

    public function requiredPermissions(array $action, DocumentType $type): array
    {
        return [];
    }

    public function describe(array $action, AutomationContext $context): string
    {
        return __('automation.actions.webhook.describe', ['url' => self::shown((string) ($action['url'] ?? ''))]);
    }

    public function run(array $action, AutomationContext $context): array
    {
        $secret = $context->rule->webhook_secret;

        if (! is_string($secret) || $secret === '' || $context->run === null) {
            throw new ActionFailed(__('automation.errors.no_webhook_secret'));
        }

        $url = (string) ($action['url'] ?? '');

        if (($reason = WebhookSender::urlProblem($url)) !== null) {
            throw new ActionFailed(__('automation.webhook.refused.'.$reason));
        }

        // The outbox: written with the run's other changes, sent only once they are committed.
        $delivery = WebhookDelivery::create([
            'run_id' => $context->run->id,
            'rule_id' => $context->rule->id,
            'action_index' => $context->actionIndex,
            'url' => $url,
            'payload' => app(WebhookPayload::class)->snapshot([
                'event' => 'automation.rule_run',
                'id' => $context->run->id.':'.$context->actionIndex,
                'rule' => ['id' => $context->rule->id, 'name' => $context->rule->name, 'version' => $context->rule->version],
                'trigger' => ['type' => $context->run->trigger_type, ...($context->run->trigger ?? [])],
                'document' => $context->documentId === null ? null : ['type' => $context->type->key(), 'id' => $context->documentId],
            ], $context->rule),
            'status' => WebhookDelivery::PENDING,
        ]);

        SendWebhookDelivery::dispatch($delivery->tenant_id, $delivery->id)->afterCommit();

        return ['delivery_id' => $delivery->id, 'url' => self::shown($url)];
    }

    /** The URL without its query string (which may carry a token) for logs and test mode. */
    public static function shown(string $url): string
    {
        return Str::before(Str::before($url, '#'), '?');
    }
}
