<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Webhooks\WebhookRefused;
use App\Core\Automation\Webhooks\WebhookSender;
use App\Core\Workflow\DocumentTypes\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

/**
 * AUTO-03 "call webhook": `{"type": "webhook", "url": "https://…"}` POSTs
 * JSON to an HTTPS endpoint, signed with the rule's secret (Signature):
 *
 *   {"event": "automation.rule_run", "id": "<run id>", "occurred_at": "…Z",
 *    "rule": {"id", "name", "version"}, "trigger": {"type", …},
 *    "document": {"type", "id"} | null, "fields": {…the document's values}}
 *
 * `fields` holds only the fields the rule's user may see (RBAC-05).
 * SSRF protection lives in WebhookSender. A refused address fails the run
 * at once; an unreachable receiver or a 5xx, 408 or 429 answer is retried;
 * other answers fail the run. The log keeps the status and the first
 * kilobyte of the answer, never the secret. `X-Webhook-Id` is the run id,
 * the same on every attempt, so receivers can ignore repeats.
 */
class WebhookAction implements AutomationAction
{
    public const KEY = 'webhook';

    private const RETRY_STATUSES = [408, 429];

    public function __construct(private readonly WebhookSender $sender) {}

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

        if (! is_string($secret) || $secret === '') {
            throw new ActionFailed(__('automation.errors.no_webhook_secret'));
        }

        try {
            $target = $this->sender->target((string) ($action['url'] ?? ''));
        } catch (WebhookRefused $e) {
            throw new ActionFailed(__('automation.webhook.refused.'.$e->reason));
        }

        $id = $context->run?->id ?? (string) Str::uuid7();
        $body = (string) json_encode($this->payload($id, $context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = $this->sender->send($target, $body, $secret, $id);
        } catch (ConnectionException) {
            throw new TransientFailure(__('automation.webhook.unreachable', ['url' => self::shown($target->url)]));
        }

        $status = $response['status'];
        $result = ['url' => self::shown($target->url), 'status' => $status, 'response' => $response['body']];

        if ($status >= 200 && $status < 300) {
            return $result;
        }

        $message = __('automation.webhook.status', ['status' => $status]);

        if ($status >= 500 || in_array($status, self::RETRY_STATUSES, true)) {
            throw new TransientFailure($message);
        }

        throw new ActionFailed($message);
    }

    /** @return array<string, mixed> */
    private function payload(string $id, AutomationContext $context): array
    {
        $run = $context->run;

        return [
            'event' => 'automation.rule_run',
            'id' => $id,
            'occurred_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            'rule' => ['id' => $context->rule->id, 'name' => $context->rule->name, 'version' => $context->rule->version],
            'trigger' => ['type' => $run?->trigger_type ?? ($context->rule->trigger['type'] ?? null), ...($run?->trigger ?? [])],
            'document' => $context->documentId === null ? null : ['type' => $context->type->key(), 'id' => $context->documentId],
            'fields' => $context->documentId === null ? (object) [] : (object) $context->visibleValues(),
        ];
    }

    /** The URL without its query string (which may carry a token) for logs and test mode. */
    public static function shown(string $url): string
    {
        return Str::before(Str::before($url, '#'), '?');
    }
}
