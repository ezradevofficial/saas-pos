<?php

namespace App\Core\Automation\Actions;

use App\Core\Automation\Capabilities\Capabilities;
use App\Core\Automation\Capabilities\LinksDocuments;
use App\Core\Automation\Runtime\FieldText;
use App\Core\Identity\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Handlers\NotifyRecipients;
use App\Core\Workflow\WorkflowAccess;
use Illuminate\Support\Str;

/**
 * AUTO-03 "send notification" through the Notifier (NOT-02), on the
 * recipients' channels (in-app, email, SMS, WhatsApp when configured):
 *
 *   {"type": "notify", "to": ["role:accountant", "user:<id>", "field:owner"],
 *    "subject": "Contract {title} ends soon", "message": "It ends on {ends_on}."}
 *
 * `role:` holders at a scope covering the document (RBAC-04), named
 * active users, and the user a `reference` field to `core.user` holds.
 * With a document, only people who may see it are notified (the others
 * are listed as skipped in the run log). Subject and message are the
 * tenant's one text (NOT-03 owner decision) with `{field}` placeholders
 * filled from the document in the organisation's language (a reference
 * field as its display value: a party's name, not its id). The link is
 * the document's page when the type offers one (relative paths only).
 */
class NotifyAction implements AutomationAction
{
    public const KEY = 'notify';

    public const EVENT = 'core.automation.notify';

    public const SUBJECT_MAX = 150;

    public const MESSAGE_MAX = 1000;

    public function __construct(
        private readonly NotifyRecipients $recipients,
        private readonly Notifier $notifier,
        private readonly WorkflowAccess $access,
        private readonly FieldText $text,
    ) {}

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
        $to = $action['to'] ?? null;

        if (! is_array($to) || ! array_is_list($to) || $to === [] || count($to) > 20 || count(array_filter($to, 'is_string')) !== count($to)) {
            $problems[] = __('automation.validation.notify_to');
        } else {
            $unknown = $this->recipients->unknown(['to' => array_values(array_filter($to, fn ($e) => ! str_starts_with($e, 'field:')))]);
            $userFields = $rule->hasDocument ? Capabilities::userFields($rule->type) : [];

            foreach ($to as $entry) {
                if (str_starts_with($entry, 'field:') && ! in_array(substr($entry, 6), $userFields, true)) {
                    $unknown[] = $entry;
                }
            }

            if ($unknown !== []) {
                $problems[] = __('automation.validation.notify_unknown', ['recipients' => implode(', ', $unknown)]);
            }
        }

        foreach (['subject' => self::SUBJECT_MAX, 'message' => self::MESSAGE_MAX] as $key => $max) {
            $value = $action[$key] ?? null;

            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
                $problems[] = __('automation.validation.notify_'.$key, ['max' => $max]);
            } elseif (($unknown = FieldText::unknownPlaceholders($value, $rule->type, $rule->hasDocument)) !== []) {
                $problems[] = __('automation.validation.unknown_placeholders', ['placeholders' => implode(', ', array_map(fn ($p) => '{'.$p.'}', $unknown))]);
            }
        }

        if (array_diff(array_keys($action), ['type', 'to', 'subject', 'message']) !== []) {
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
        [$sent, $skipped] = $this->resolve($action, $context);

        return __('automation.actions.notify.describe', [
            'count' => count($sent),
            'recipients' => implode(', ', User::query()->whereKey($sent)->orderBy('name')->pluck('name')->all()) ?: __('automation.values.nobody'),
            'subject' => $this->render((string) ($action['subject'] ?? ''), $context),
            'message' => $this->render((string) ($action['message'] ?? ''), $context),
        ]).($skipped === [] ? '' : ' '.__('automation.actions.notify.skipped', ['count' => count($skipped)]));
    }

    public function run(array $action, AutomationContext $context): array
    {
        [$sent, $skipped] = $this->resolve($action, $context);

        if ($sent !== []) {
            $this->notifier->send(new NotificationEvent(
                self::EVENT,
                $sent,
                [
                    'subject' => $this->render((string) ($action['subject'] ?? ''), $context),
                    'message' => $this->render((string) ($action['message'] ?? ''), $context),
                    'rule_name' => $context->rule->name,
                    'document_type' => __($context->type->label(), [], $context->locale),
                ],
                $context->documentId !== null && $context->type instanceof LinksDocuments ? $context->type->documentLink($context->documentId) : null,
            ));
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'skipped_reason' => $skipped === [] ? null : 'cannot_see_document'];
    }

    /**
     * Active recipients, split into those notified and those skipped
     * because they may not see the document.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function resolve(array $action, AutomationContext $context): array
    {
        $to = array_values(array_filter((array) ($action['to'] ?? []), 'is_string'));
        $ids = $this->recipients->resolve(['to' => array_values(array_filter($to, fn ($e) => ! str_starts_with($e, 'field:')))], $context->scope);

        foreach ($to as $entry) {
            if (str_starts_with($entry, 'field:') && $context->documentId !== null) {
                $value = $context->values[substr($entry, 6)] ?? null;

                if (is_string($value) && Str::isUuid($value)) {
                    $ids[] = $value;
                }
            }
        }

        $sent = [];
        $skipped = [];

        foreach (User::query()->whereKey(array_values(array_unique($ids)))->where('status', User::STATUS_ACTIVE)->orderBy('id')->get() as $user) {
            if ($context->documentId === null || $this->access->seesDocument($user, $context->type, $context->scope)) {
                $sent[] = $user->id;
            } else {
                $skipped[] = $user->id;
            }
        }

        return [$sent, $skipped];
    }

    private function render(string $text, AutomationContext $context): string
    {
        // RBAC-05: a field hidden from the rule's user fills in as nothing.
        return $this->text->render($text, $context->type, $context->documentId === null ? [] : $context->visibleValues(), [
            'document_type' => __($context->type->label(), [], $context->locale),
            'rule_name' => $context->rule->name,
        ], $context->locale, $context->timezone, $context->visibleDisplayValues());
    }
}
