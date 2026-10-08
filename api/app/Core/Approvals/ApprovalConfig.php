<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\Identity\Models\User;
use App\Core\Workflow\Definitions\GraphValidator;
use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentType;
use Illuminate\Support\Str;

/**
 * An approval node's configuration (APR-01..APR-08), as the builder writes
 * it on the node:
 *
 *   approval:   {approver: {type, ...params}, chain?: [approver, ...], mode: any|all|majority,
 *                allow_delegation, allow_email, require_reason, allow_bulk}
 *   escalation: {after: {amount, unit}, to: {type: next_level|role|user, role?, user_id?}, final: approve|reject|null}
 *   reminders:  [{amount, unit}, ...]
 *   due:        {amount, unit}
 *
 * `chain`, when given, replaces `approver`: each approver in turn
 * (sequential chain), the mode applying within each step. A node without
 * an approver is approved by the holders of its exit roles, else by the
 * people holding the document type's act permission (`stage_roles`).
 * `to: "next_level"` (a plain string) is accepted for `{type: next_level}`.
 * Defaults: mode any, delegation and email allowed, no reason required,
 * bulk approval allowed unless a reason is required.
 */
class ApprovalConfig
{
    public const MODES = ['any', 'all', 'majority'];

    public const ESCALATION_TARGETS = ['next_level', 'role', 'user'];

    public const FINAL = ['approve', 'reject'];

    public const MAX_CHAIN = 10;

    public const MAX_REMINDERS = 5;

    public const STAGE_ROLES = 'stage_roles';

    public function __construct(
        private readonly ApproverResolvers $resolvers,
        private readonly RoleRefs $roles,
    ) {}

    /**
     * The node's settings with defaults filled in (what a request stores).
     *
     * @param  array<string, mixed>  $node
     * @return array{chain: list<array<string, mixed>>, mode: string, allow_delegation: bool, allow_email: bool, require_reason: bool, allow_bulk: bool, escalation: array{after: ?array, to: ?array, final: ?string}, reminders: list<array{amount: int, unit: string}>, due: ?array}
     */
    public static function normalise(array $node): array
    {
        $approval = is_array($node['approval'] ?? null) ? $node['approval'] : [];
        $chain = is_array($approval['chain'] ?? null) && $approval['chain'] !== []
            ? array_values(array_filter($approval['chain'], 'is_array'))
            : (is_array($approval['approver'] ?? null) ? [$approval['approver']] : [['type' => self::STAGE_ROLES]]);
        $requireReason = ($approval['require_reason'] ?? false) === true;
        $escalation = is_array($node['escalation'] ?? null) ? $node['escalation'] : [];
        $to = $escalation['to'] ?? null;

        return [
            'chain' => $chain,
            'mode' => in_array($approval['mode'] ?? null, self::MODES, true) ? $approval['mode'] : 'any',
            'allow_delegation' => ($approval['allow_delegation'] ?? true) !== false,
            'allow_email' => ($approval['allow_email'] ?? true) !== false,
            'require_reason' => $requireReason,
            'allow_bulk' => is_bool($approval['allow_bulk'] ?? null) ? $approval['allow_bulk'] && ! $requireReason : ! $requireReason,
            'escalation' => [
                'after' => self::duration($escalation['after'] ?? null),
                'to' => is_string($to) ? ['type' => $to] : (is_array($to) ? $to : null),
                'final' => in_array($escalation['final'] ?? null, self::FINAL, true) ? $escalation['final'] : null,
            ],
            'reminders' => array_values(array_filter(array_map(self::duration(...), is_array($node['reminders'] ?? null) && array_is_list($node['reminders']) ? $node['reminders'] : []))),
            'stage_exit_roles' => is_array($node['exit_roles'] ?? null) ? array_values($node['exit_roles']) : [],
            'due' => self::duration($node['due'] ?? null),
        ];
    }

    /**
     * Problems with the node's approval settings, translated (empty when
     * valid). The graph validator checks `due` itself.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public function validate(array $node, DocumentType $type): array
    {
        $problems = [];
        $approval = $node['approval'] ?? [];

        if (! is_array($approval) || ($approval !== [] && array_is_list($approval))) {
            return [__('approvals.validation.config')];
        }

        if (isset($approval['chain'])) {
            $chain = $approval['chain'];

            if (! is_array($chain) || ! array_is_list($chain) || $chain === [] || count($chain) > self::MAX_CHAIN) {
                $problems[] = __('approvals.validation.chain', ['max' => self::MAX_CHAIN]);
            } else {
                foreach ($chain as $i => $approver) {
                    array_push($problems, ...$this->approver($approver, $type, $i + 1));
                }
            }
        } elseif (array_key_exists('approver', $approval)) {
            array_push($problems, ...$this->approver($approval['approver'], $type, null));
        }

        if (isset($approval['mode']) && ! in_array($approval['mode'], self::MODES, true)) {
            $problems[] = __('approvals.validation.mode');
        }

        foreach (['allow_delegation', 'allow_email', 'require_reason', 'allow_bulk'] as $flag) {
            if (isset($approval[$flag]) && ! is_bool($approval[$flag])) {
                $problems[] = __('approvals.validation.flag', ['setting' => $flag]);
            }
        }

        array_push($problems, ...$this->escalation($node));

        $reminders = $node['reminders'] ?? null;

        if ($reminders !== null && (! is_array($reminders) || ! array_is_list($reminders) || count($reminders) > self::MAX_REMINDERS
            || in_array(null, array_map(self::duration(...), $reminders), true))) {
            $problems[] = __('approvals.validation.reminders', ['max' => self::MAX_REMINDERS]);
        }

        return array_values(array_unique($problems));
    }

    /** @return list<string> */
    private function approver(mixed $approver, DocumentType $type, ?int $step): array
    {
        $prefix = fn (string $message) => $step === null ? $message : __('approvals.validation.step', ['step' => $step, 'problem' => $message]);

        if (! is_array($approver) || ! is_string($approver['type'] ?? null)) {
            return [$prefix(__('approvals.validation.approver'))];
        }

        $resolver = $this->resolvers->find($approver['type']);

        if ($resolver === null) {
            return [$prefix(__('approvals.validation.approver_type', ['type' => $approver['type']]))];
        }

        return array_map($prefix, $resolver->validate($approver, $type));
    }

    /** @return list<string> */
    private function escalation(array $node): array
    {
        $escalation = $node['escalation'] ?? null;

        if ($escalation === null) {
            return [];
        }

        if (! is_array($escalation) || ($escalation !== [] && array_is_list($escalation))) {
            return [__('approvals.validation.escalation')];
        }

        $problems = [];

        if (isset($escalation['after']) && self::duration($escalation['after']) === null) {
            $problems[] = __('approvals.validation.escalation_after');
        }

        $to = $escalation['to'] ?? null;
        $to = is_string($to) ? ['type' => $to] : $to;

        if ($to !== null) {
            $target = is_array($to) ? ($to['type'] ?? null) : null;

            if (! in_array($target, self::ESCALATION_TARGETS, true)) {
                $problems[] = __('approvals.validation.escalation_to');
            } elseif ($target === 'role' && (! is_string($to['role'] ?? null) || $this->roles->unknown([$to['role']]) !== [])) {
                $problems[] = __('approvals.validation.escalation_role');
            } elseif ($target === 'user' && ! self::knownUser($to['user_id'] ?? null)) {
                $problems[] = __('approvals.validation.escalation_user');
            }

            if (! isset($escalation['after'])) {
                $problems[] = __('approvals.validation.escalation_needs_after');
            }
        }

        if (array_key_exists('final', $escalation) && $escalation['final'] !== null && ! in_array($escalation['final'], self::FINAL, true)) {
            $problems[] = __('approvals.validation.escalation_final');
        } elseif (($escalation['final'] ?? null) !== null && ! isset($escalation['after']) && ! isset($node['due'])) {
            $problems[] = __('approvals.validation.final_needs_time');
        }

        return $problems;
    }

    public static function knownUser(mixed $id): bool
    {
        return is_string($id) && Str::isUuid($id) && User::query()->whereKey($id)->exists();
    }

    /** @return array{amount: int, unit: string}|null */
    public static function duration(mixed $value): ?array
    {
        if (! is_array($value) || ! is_int($value['amount'] ?? null) || $value['amount'] < 1 || $value['amount'] > 10000
            || ! in_array($value['unit'] ?? null, GraphValidator::DUE_UNITS, true)) {
            return null;
        }

        return ['amount' => $value['amount'], 'unit' => $value['unit']];
    }
}
