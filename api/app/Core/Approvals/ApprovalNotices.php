<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalDelegation;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Exports\ExportValues;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;

/**
 * APR-03..APR-06 notifications, sent through the Notifier (NOT-02) with
 * relative links to the request (`/approvals/{id}`). Default texts live in
 * lang/{en,fr}/approvals.php under `notifications.<event>`; approval
 * requests may be made mandatory per channel by the tenant (NOT-04).
 * Approve-by-email links are added to the email at send time
 * (EmailApprovals, APR-08), never stored in the message.
 */
class ApprovalNotices
{
    public const REQUESTED = 'core.approval.requested';

    public const DECIDED = 'core.approval.decided';

    public const RETURNED = 'core.approval.returned';

    public const INFO_REQUESTED = 'core.approval.info_requested';

    public const REMINDER = 'core.approval.reminder';

    public const ESCALATED = 'core.approval.escalated';

    public const DELEGATED = 'core.approval.delegated';

    /** Events whose email carries approve and reject links (APR-08). */
    public const ACTIONABLE = [self::REQUESTED, self::REMINDER, self::ESCALATED];

    private const DOCUMENT = [
        'document_type' => 'Purchase requisition',
        'document_number' => 'PR-0042',
        'document_title' => 'Laptops for the Westlands branch',
        'amount' => 'KES 120,000.00',
        'step' => 'Branch manager approves',
        'requester_name' => 'Amina Otieno',
    ];

    public function __construct(
        private readonly Notifier $notifier,
        private readonly DocumentTypeRegistry $types,
        private readonly CurrencyDecimals $decimals,
    ) {}

    public static function register(EventTypes $types): void
    {
        $events = [
            self::REQUESTED => self::DOCUMENT,
            self::DECIDED => [...self::DOCUMENT, 'outcome' => 'approved', 'decided_by' => 'Juma Mwangi', 'comment' => 'Within budget.'],
            self::RETURNED => [...self::DOCUMENT, 'decided_by' => 'Juma Mwangi', 'comment' => 'Add the supplier quote.'],
            self::INFO_REQUESTED => [...self::DOCUMENT, 'decided_by' => 'Juma Mwangi', 'comment' => 'Which supplier is this from?'],
            self::REMINDER => [...self::DOCUMENT, 'due' => '8 Oct 2026, 17:00'],
            self::ESCALATED => [...self::DOCUMENT, 'waiting_for' => 'Juma Mwangi'],
            self::DELEGATED => ['delegator_name' => 'Juma Mwangi', 'starts_on' => '12 Oct 2026', 'ends_on' => '16 Oct 2026', 'document_types' => 'Purchase requisition'],
        ];

        foreach ($events as $key => $placeholders) {
            $types->register(new EventType(
                key: $key,
                placeholders: $placeholders,
                defaultChannels: [Channels::IN_APP, Channels::EMAIL],
                mandatoryAllowed: true,
                langKey: 'approvals.notifications.'.substr($key, strlen('core.approval.')),
            ));
        }
    }

    public static function link(string $requestId): string
    {
        return '/approvals/'.$requestId;
    }

    /** @param list<string> $userIds */
    public function requested(ApprovalRequest $request, array $userIds, string $event = self::REQUESTED, array $extra = []): void
    {
        $this->send($event, $request, $userIds, $extra);
    }

    public function decided(ApprovalRequest $request, string $outcome, ?User $by, ?string $comment): void
    {
        if ($request->requester_id !== null && $request->requester_id !== $by?->id) {
            $this->send(self::DECIDED, $request, [$request->requester_id], [
                'outcome' => __('approvals.outcomes.'.$outcome),
                'decided_by' => $by?->name ?? __('approvals.system'),
                'comment' => $comment ?? '',
            ]);
        }
    }

    public function returned(ApprovalRequest $request, User $by, string $reason): void
    {
        if ($request->requester_id !== null && $request->requester_id !== $by->id) {
            $this->send(self::RETURNED, $request, [$request->requester_id], ['decided_by' => $by->name, 'comment' => $reason]);
        }
    }

    public function infoRequested(ApprovalRequest $request, User $by, string $message): void
    {
        if ($request->requester_id !== null && $request->requester_id !== $by->id) {
            $this->send(self::INFO_REQUESTED, $request, [$request->requester_id], ['decided_by' => $by->name, 'comment' => $message]);
        }
    }

    public function delegated(ApprovalDelegation $delegation, User $from): void
    {
        $types = $delegation->document_types === null
            ? __('approvals.delegations.all_types')
            : implode(', ', array_map(fn (string $key) => ($type = $this->types->find($key)) === null ? $key : __($type->label()), $delegation->document_types));

        $this->notifier->send(new NotificationEvent(self::DELEGATED, [$delegation->to_user_id], [
            'delegator_name' => $from->name,
            'starts_on' => $delegation->starts_on->format('Y-m-d'),
            'ends_on' => $delegation->ends_on->format('Y-m-d'),
            'document_types' => $types,
        ], '/approvals'));
    }

    /** @param list<string> $userIds */
    private function send(string $event, ApprovalRequest $request, array $userIds, array $extra = []): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return;
        }

        $this->notifier->send(new NotificationEvent($event, $userIds, [...$this->document($request), ...$extra], self::link($request->id)));
    }

    /** @return array<string, string> */
    private function document(ApprovalRequest $request): array
    {
        $type = $this->types->find($request->document_type);
        $values = new ExportValues(app()->getLocale(), 'UTC', [], $this->decimals);

        return [
            'document_type' => $type === null ? $request->document_type : __($type->label()),
            'document_number' => (string) $request->document_number,
            'document_title' => (string) $request->document_title,
            'amount' => (string) ($values->money($request->amount()) ?? ''),
            'step' => (string) $request->node_name,
            'requester_name' => (string) ($request->requester_id === null ? '' : User::query()->whereKey($request->requester_id)->value('name')),
        ];
    }
}
