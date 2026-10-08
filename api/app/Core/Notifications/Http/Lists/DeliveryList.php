<?php

namespace App\Core\Notifications\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Identity\Models\User;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Notifications\Http\Resources\DeliveryResource;
use App\Core\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** NOT-06: every delivery of the tenant, for admins (EXP-01). No field rules apply. */
class DeliveryList extends ListDefinition
{
    public function name(): string
    {
        return 'notification-deliveries';
    }

    public function auditAction(): string
    {
        return 'core.notification_delivery.export';
    }

    public function title(array $filters): string
    {
        return __('notifications.delivery.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return DeliveryResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'status' => ListSort::column('status'),
            'channel' => ListSort::column('channel'),
            'event_type' => ListSort::column('event_type'),
            'attempts' => ListSort::column('attempts'),
            'sent_at' => ListSort::column('sent_at'),
            'user' => ListSort::by(['user'], fn (Builder $query, string $direction) => $query->orderBy(
                User::query()->select('name')->whereColumn('users.id', $query->qualifyColumn('user_id')),
                $direction,
            )),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function exportRelations(): array
    {
        return ['user'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('created_at', 'notifications.delivery.columns.created_at', ['created_at'],
                fn (array $row, NotificationDelivery $delivery, ExportValues $values) => $values->dateTime($row['created_at'])),
            ListColumn::make('user', 'notifications.delivery.columns.user', ['user'], fn (array $row) => $row['user']['name'] ?? null),
            ListColumn::text('recipient', 'notifications.delivery.columns.recipient'),
            ListColumn::text('type', 'notifications.delivery.columns.type', 'event_label'),
            ListColumn::make('channel', 'notifications.delivery.columns.channel', ['channel'], fn (array $row) => __('notifications.channels.'.$row['channel'])),
            ListColumn::make('status', 'notifications.delivery.columns.status', ['status'], fn (array $row) => __('notifications.statuses.'.$row['status'])),
            ListColumn::text('reason', 'notifications.delivery.columns.reason', 'reason_label'),
            ListColumn::text('attempts', 'notifications.delivery.columns.attempts'),
            ListColumn::text('error', 'notifications.delivery.columns.error', 'error_label'),
            ListColumn::make('sent_at', 'notifications.delivery.columns.sent_at', ['sent_at'],
                fn (array $row, NotificationDelivery $delivery, ExportValues $values) => $values->dateTime($row['sent_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('notifications.statuses.'.($filters['status'] ?? 'all'));

        if (isset($filters['channel'])) {
            $summary[__('notifications.delivery.filters.channel')] = __('notifications.channels.'.$filters['channel']);
        }

        return $summary;
    }
}
