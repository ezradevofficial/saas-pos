<?php

namespace App\Core\Automation\Runtime;

use App\Core\Audit\Auditor;
use App\Core\Automation\Actions\WebhookAction;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Triggers\ScheduleRecurrence;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Saving automation rules (AUTO-01..AUTO-03): create (disabled unless asked),
 * edit (each change of the definition raises the version), enable,
 * disable and archive (never delete, TEN-06). Every change is audited as
 * `core.automation.*` with the definition before and after (AUD-01); the
 * webhook signing secret is generated here (when a rule first gets a
 * webhook, or on rotate), handed back once on the returned model
 * (`revealedSecret`), stored encrypted, and never in the audit log, only
 * whether there is one.
 * Validation is the caller's (RuleValidator through the Form Requests).
 */
class Rules
{
    /** Fields whose change makes a new version. */
    private const VERSIONED = ['name', 'document_type', 'company_id', 'trigger', 'conditions', 'actions'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly RuleTimezone $timezones,
    ) {}

    /** @param array<string, mixed> $data name, document_type, company_id, trigger, conditions, actions, enabled */
    public function create(array $data, User $by, array $auditExtra = []): AutomationRule
    {
        return DB::transaction(function () use ($data, $by, $auditExtra) {
            $rule = new AutomationRule([
                'name' => $data['name'],
                'document_type' => $data['document_type'],
                'company_id' => $data['company_id'] ?? null,
                'trigger_type' => $data['trigger']['type'],
                'trigger' => $data['trigger'],
                'conditions' => self::conditions($data['conditions'] ?? null),
                'actions' => $data['actions'],
                'enabled' => (bool) ($data['enabled'] ?? false),
                'created_by' => $by->id,
                'updated_by' => $by->id,
            ]);
            $rule->next_run_at = $this->nextRunAt($rule);
            $this->secretForWebhooks($rule);
            $rule->save();

            $this->auditor->record('core.automation.create', $rule, null, [...self::snapshot($rule), ...$auditExtra]);

            return $rule;
        });
    }

    /** @param array<string, mixed> $data the fields to change */
    public function update(AutomationRule $rule, array $data, User $by): AutomationRule
    {
        return DB::transaction(function () use ($rule, $data, $by) {
            $rule = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $before = self::snapshot($rule);

            foreach (self::VERSIONED as $key) {
                if (array_key_exists($key, $data)) {
                    $rule->{$key} = $key === 'conditions' ? self::conditions($data[$key]) : $data[$key];
                }
            }

            if (isset($data['trigger']['type'])) {
                $rule->trigger_type = $data['trigger']['type'];
            }

            if (! $rule->isDirty()) {
                return $rule;
            }

            $secretCreated = $this->secretForWebhooks($rule);

            $rule->version++;
            $rule->updated_by = $by->id;
            $rule->next_run_at = $this->nextRunAt($rule);
            $rule->save();

            $this->auditor->record('core.automation.update', $rule, $before, [...self::snapshot($rule), 'secret_created' => $secretCreated]);

            return $rule;
        });
    }

    public function setEnabled(AutomationRule $rule, bool $enabled, User $by): AutomationRule
    {
        return DB::transaction(function () use ($rule, $enabled, $by) {
            $rule = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            if ($rule->isArchived()) {
                throw new ApiException(422, 'rule_archived', __('automation.errors.rule_archived'));
            }

            if ($rule->enabled === $enabled) {
                return $rule;
            }

            $rule->enabled = $enabled;
            // The rule now acts as the person who switched it on.
            $rule->updated_by = $enabled ? $by->id : $rule->updated_by;
            $rule->next_run_at = $this->nextRunAt($rule);
            $rule->save();

            $this->auditor->record($enabled ? 'core.automation.enable' : 'core.automation.disable', $rule, ['enabled' => ! $enabled], ['enabled' => $enabled, 'version' => $rule->version]);

            return $rule;
        });
    }

    /**
     * The system switches a rule off (its user can no longer run it); audited
     * with the reason. False when it was already off.
     */
    public function switchOff(AutomationRule $rule, string $reason): bool
    {
        return DB::transaction(function () use ($rule, $reason) {
            $locked = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            if (! $locked->enabled) {
                return false;
            }

            $locked->enabled = false;
            $locked->next_run_at = null;
            $locked->save();

            $this->auditor->record('core.automation.disable', $locked, ['enabled' => true], ['enabled' => false, 'version' => $locked->version, 'reason' => $reason], ['user_id' => null]);

            return true;
        });
    }

    public function archive(AutomationRule $rule, User $by): AutomationRule
    {
        return DB::transaction(function () use ($rule, $by) {
            $rule = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            if ($rule->isArchived()) {
                return $rule;
            }

            $before = ['enabled' => $rule->enabled, 'archived_at' => null];
            $rule->enabled = false;
            $rule->next_run_at = null;
            $rule->archived_at = CarbonImmutable::now();
            $rule->updated_by = $by->id;
            $rule->save();

            $this->auditor->record('core.automation.archive', $rule, $before, ['enabled' => false, 'archived_at' => $rule->archived_at->toIso8601ZuluString()]);

            return $rule;
        });
    }

    /** A schedule's next occurrence while the rule is on, else null. */
    public function nextRunAt(AutomationRule $rule, ?CarbonImmutable $after = null): ?CarbonImmutable
    {
        if (! $rule->enabled || $rule->archived_at !== null || ($rule->trigger['type'] ?? null) !== Triggers::SCHEDULE) {
            return null;
        }

        $timezone = $this->timezones->forCompany($rule->company_id) ?? 'UTC';

        return ScheduleRecurrence::next($rule->trigger, $after ?? CarbonImmutable::now(), $timezone);
    }

    /** @return array<string, mixed> what the audit log keeps of a rule (never the secret) */
    public static function snapshot(AutomationRule $rule): array
    {
        return [
            'name' => $rule->name,
            'document_type' => $rule->document_type,
            'company_id' => $rule->company_id,
            'trigger' => $rule->trigger,
            'conditions' => $rule->conditions,
            'actions' => $rule->actions,
            'enabled' => $rule->enabled,
            'version' => $rule->version,
            'has_webhook_secret' => $rule->webhook_secret !== null,
        ];
    }

    private static function conditions(mixed $conditions): ?array
    {
        return is_array($conditions) && $conditions !== [] ? $conditions : null;
    }

    /** A new secret for the rule's webhooks, revealed once on the returned model; audited without it. */
    public function rotateSecret(AutomationRule $rule, User $by): AutomationRule
    {
        return DB::transaction(function () use ($rule, $by) {
            $rule = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $had = $rule->webhook_secret !== null;
            $rule->webhook_secret = $rule->revealedSecret = self::newSecret();
            $rule->updated_by = $by->id;
            $rule->save();

            $this->auditor->record('core.automation.rotate_secret', $rule, ['has_webhook_secret' => $had], ['has_webhook_secret' => true]);

            return $rule;
        });
    }

    /** A rule with a webhook and no secret gets one (revealed once). */
    private function secretForWebhooks(AutomationRule $rule): bool
    {
        $hasWebhook = in_array(WebhookAction::KEY, array_column(array_filter((array) $rule->actions, 'is_array'), 'type'), true);

        if (! $hasWebhook || $rule->webhook_secret !== null) {
            return false;
        }

        $rule->webhook_secret = $rule->revealedSecret = self::newSecret();

        return true;
    }

    /** 32 random bytes, base64url without padding (43 characters). */
    public static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
