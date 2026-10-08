<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAction;
use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalAttachment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Approvals\Resolvers\ApproverResolvers;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\Conditions\ConditionDescriber;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\Models\DocumentWorkflowEvent;
use App\Core\Workflow\Models\WorkflowVersion;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Listeners\SendWorkflowNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * What the approvals inbox shows (APR-04): a request as a list item for
 * its viewer (document summary, step, waiting since, due and escalation,
 * "delegated from", what the viewer may do), and the detail page (who
 * must act and who did, the history, attachments behind signed URLs,
 * where the document may be returned to, and "why this route").
 *
 * "Why this route" lists the condition steps the document passed before
 * reaching the approval, with the fields compared and whether each held,
 * never the stored values; a viewer who may see the document itself
 * (WF-10) also gets sentences built from its current values.
 */
class ApprovalPresenter
{
    public const URL_MINUTES = 15;

    /** @var array<string, ?string> */
    private array $names = [];

    public function __construct(
        private readonly ApprovalAccess $access,
        private readonly ApprovalRouting $routing,
        private readonly ApprovalActions $actions,
        private readonly Delegations $delegations,
        private readonly DocumentTypeRegistry $types,
        private readonly ApproverResolvers $resolvers,
        private readonly ConditionEvaluator $evaluator,
        private readonly ConditionDescriber $describer,
        private readonly ApprovalClock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function item(ApprovalRequest $request, User $viewer, ?Collection $delegations = null): array
    {
        $type = $this->types->find($request->document_type);
        $acting = $this->access->acting($request, $viewer, $delegations ?? $this->delegations->to($viewer));
        $excluded = in_array($viewer->id, $this->routing->excluded($request), true)
            || ($acting !== null && $acting[1] !== null && in_array($acting[1], $this->routing->excluded($request), true));
        $mayDecide = $acting !== null && ! $excluded;
        $pending = $request->isPending();
        $now = CarbonImmutable::now();
        $config = $request->config;
        $mine = $acting === null
            ? $request->assignments()->where(fn ($q) => $q->where('decided_by', $viewer->id)->orWhere('user_id', $viewer->id))
                ->orderByDesc('updated_at')->first()
            : $acting[0];

        return [
            'id' => $request->id,
            'status' => $request->status,
            'outcome' => $request->outcome,
            'auto_decided' => $request->auto_decided,
            'blocked_reason' => $request->blocked_reason,
            'blocked_label' => $request->blocked_reason === null ? null : __('approvals.blocked.'.$request->blocked_reason),
            'document' => [
                'type' => $request->document_type,
                'type_label' => $type === null ? $request->document_type : __($type->label()),
                'id' => $request->document_id,
                'number' => $request->document_number,
                'title' => $request->document_title,
                'amount' => $request->amount(),
            ],
            'step' => ['node_id' => $request->node_id, 'name' => $request->node_name, 'index' => $request->step + 1, 'count' => $request->steps],
            'mode' => $request->mode,
            'company' => $request->company_id === null ? null : ['id' => $request->company_id, 'name' => $this->companyName($request->company_id)],
            'requester' => $this->user($request->requester_id),
            'received_at' => $request->received_at?->toIso8601ZuluString(),
            'waiting_since' => $request->level_started_at?->toIso8601ZuluString(),
            'due_at' => $request->due_at?->toIso8601ZuluString(),
            'overdue' => $pending && $request->due_at !== null && $request->due_at->lessThan($now),
            'escalation' => $pending && $request->escalate_at !== null && ($config['escalation']['after'] ?? null) !== null
                ? ['at' => $request->escalate_at->toIso8601ZuluString(), 'to' => $this->routing->describeEscalation($request)]
                : null,
            'final' => $pending && ($config['escalation']['final'] ?? null) !== null
                ? ['outcome' => $config['escalation']['final'], 'at' => ($config['escalation']['after'] ?? null) === null ? $request->escalate_at?->toIso8601ZuluString() : null]
                : null,
            'decided_at' => $request->decided_at?->toIso8601ZuluString(),
            'my_assignment' => $mine === null ? null : [
                'id' => $mine->id,
                'status' => $mine->status,
                'delegated_from' => $acting !== null && $acting[1] !== null ? $this->user($acting[1]) : null,
                'decided_at' => $mine->decided_at?->toIso8601ZuluString(),
                'on_behalf_of' => $this->user($mine->on_behalf_of),
            ],
            'can' => [
                'approve' => $mayDecide,
                'reject' => $mayDecide,
                'return' => $mayDecide,
                'request_info' => $mayDecide && $request->requester_id !== null,
                'bulk_approve' => $mayDecide && ($config['allow_bulk'] ?? true) === true,
                'comment' => $pending,
                'attach' => $pending && ($acting !== null || $request->requester_id === $viewer->id),
                'reassign' => $pending && $this->access->mayReassign($viewer, $request),
            ],
            'require_reason' => (bool) ($config['require_reason'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(ApprovalRequest $request, User $viewer): array
    {
        $version = WorkflowVersion::query()->find($request->version_id);
        $seesDocument = $this->access->seesDocument($viewer, $request);

        return [
            ...$this->item($request, $viewer),
            'version' => $version === null ? null : ['id' => $version->id, 'number' => $version->version],
            'settings' => [
                'mode' => $request->mode,
                'chain' => array_map(fn (array $approver) => [
                    'type' => $approver['type'] ?? null,
                    'label' => $this->resolvers->find((string) ($approver['type'] ?? ''))?->describe($approver),
                ], $request->config['chain'] ?? []),
                'require_reason' => (bool) ($request->config['require_reason'] ?? false),
                'allow_bulk' => (bool) ($request->config['allow_bulk'] ?? true),
                'allow_delegation' => (bool) ($request->config['allow_delegation'] ?? true),
                'allow_email' => (bool) ($request->config['allow_email'] ?? true),
            ],
            'document_link' => $seesDocument ? SendWorkflowNotification::link($request->document_type, $request->document_id) : null,
            'route' => $this->route($request, $seesDocument),
            'approvers' => $request->assignments()->orderBy('step')->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (ApprovalAssignment $a) => [
                    'id' => $a->id,
                    'user' => $this->user($a->user_id),
                    'step' => $a->step + 1,
                    'source' => $a->source,
                    'status' => $a->status,
                    'decided_by' => $this->user($a->decided_by),
                    'on_behalf_of' => $this->user($a->on_behalf_of),
                    'decided_at' => $a->decided_at?->toIso8601ZuluString(),
                    'comment' => $a->comment,
                    'reassigned_from' => $this->user($a->reassigned_from),
                    'reassigned_by' => $this->user($a->reassigned_by),
                ])->values()->all(),
            'history' => $request->actions()->orderBy('occurred_at')->orderBy('id')->get()
                ->map(fn (ApprovalAction $a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'label' => __('approvals.history.'.$a->type),
                    'user' => $this->user($a->user_id),
                    'on_behalf_of' => $this->user($a->on_behalf_of),
                    'comment' => $a->comment,
                    'data' => $a->data,
                    'occurred_at' => $a->occurred_at?->toIso8601ZuluString(),
                ])->values()->all(),
            'attachments' => $request->attachments()->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (ApprovalAttachment $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'mime' => $f->mime,
                    'size' => $f->size,
                    'uploaded_by' => $this->user($f->uploaded_by),
                    'created_at' => $f->created_at?->toIso8601ZuluString(),
                    'url' => $this->url($f, $viewer),
                ])->values()->all(),
            'return_targets' => $request->isPending() ? $this->actions->returnTargets($request) : [],
        ];
    }

    /** A temporary URL for $viewer: the object store's own on s3, else the signed route bound to the viewer. */
    public function url(ApprovalAttachment $file, User $viewer): string
    {
        $expires = now()->addMinutes(self::URL_MINUTES);

        if (config("filesystems.disks.{$file->disk}.driver") === 's3') {
            return Storage::disk($file->disk)->temporaryUrl($file->path, $expires, [
                'ResponseContentDisposition' => 'attachment; filename="'.addcslashes($file->name, '"\\').'"',
            ]);
        }

        return URL::temporarySignedRoute('approvals.attachment', $expires, ['path' => $file->path, 'user' => $viewer->id]);
    }

    /**
     * "Why this route": the conditions passed (and optional steps skipped)
     * before the document reached this approval.
     *
     * @return list<array<string, mixed>>
     */
    private function route(ApprovalRequest $request, bool $seesDocument): array
    {
        $type = $this->types->find($request->document_type);
        $flow = WorkflowVersion::query()->find($request->version_id)?->flow();

        if ($type === null || $flow === null) {
            return [];
        }

        $fields = $type->fieldsByName();
        $events = DocumentWorkflowEvent::query()->where('workflow_id', $request->workflow_id)
            ->whereIn('type', ['condition', 'skipped'])->where('occurred_at', '<=', $request->received_at)
            ->orderBy('occurred_at')->orderBy('id')->get();
        $values = null;
        $timezone = $this->clock->timezone($request->company_id);

        return $events->map(function (DocumentWorkflowEvent $event) use ($flow, $fields, $type, $request, $seesDocument, &$values, $timezone) {
            $node = $flow->node((string) $event->node_id) ?? [];
            $outlines = $event->type === 'condition' ? ($event->data['results'] ?? []) : [['branch' => null, ...($event->data['condition'] ?? [])]];
            $checks = [];

            foreach ($outlines as $outline) {
                foreach ($outline['checks'] ?? [] as $check) {
                    $checks[] = [
                        'branch' => $outline['branch'] ?? null,
                        'field' => $check['field'] ?? null,
                        'label' => isset($fields[$check['field'] ?? '']) ? __($fields[$check['field']]->label) : ($check['field'] ?? null),
                        'op' => $check['op'] ?? null,
                        'passed' => (bool) ($check['passed'] ?? false),
                    ];
                }
            }

            $explanations = null;

            if ($seesDocument) {
                $values ??= $type->fieldValues($request->document_id);
                $conditions = $event->type === 'skipped'
                    ? [$node['entry'] ?? null]
                    : (isset($node['branches']) && is_array($node['branches']) ? array_column($node['branches'], 'condition') : [$node['condition'] ?? null]);
                $explanations = [];

                foreach (array_filter($conditions, 'is_array') as $condition) {
                    foreach ($this->evaluator->evaluate($condition, $values, $fields, $timezone)->checks as $check) {
                        $explanations[] = $this->describer->describe($check, $fields, $timezone);
                    }
                }

                $explanations = array_values(array_unique($explanations));
            }

            return [
                'node_id' => $event->node_id,
                'node_name' => $flow->name((string) $event->node_id),
                'kind' => $event->type,
                'branch' => $event->type === 'condition' ? ($event->data['branch'] ?? null) : 'skipped',
                'checks' => $checks,
                'explanations' => $explanations,
            ];
        })->values()->all();
    }

    /** @return array{id: string, name: ?string}|null */
    private function user(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }

        if (! array_key_exists($id, $this->names)) {
            $this->names[$id] = User::query()->whereKey($id)->value('name');
        }

        return ['id' => $id, 'name' => $this->names[$id]];
    }

    private function companyName(string $id): ?string
    {
        return $this->names['company:'.$id] ??= Company::query()->whereKey($id)->value('name');
    }
}
