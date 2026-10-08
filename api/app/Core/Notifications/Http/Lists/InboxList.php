<?php

namespace App\Core\Notifications\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Notifications\Http\Resources\InAppNotificationResource;
use App\Core\Notifications\Models\InAppNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** NOT-01: the signed-in user's own notifications (EXP-01). No field rules apply. */
class InboxList extends ListDefinition
{
    public function name(): string
    {
        return 'notifications';
    }

    public function auditAction(): string
    {
        return 'core.notification.export';
    }

    public function title(array $filters): string
    {
        return __('notifications.inbox.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return InAppNotificationResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'subject' => ListSort::column('subject'),
            'read_at' => ListSort::column('read_at'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('created_at', 'notifications.inbox.columns.created_at', ['created_at'],
                fn (array $row, InAppNotification $notification, ExportValues $values) => $values->dateTime($row['created_at'])),
            ListColumn::text('type', 'notifications.inbox.columns.type', 'event_label'),
            ListColumn::text('subject', 'notifications.inbox.columns.subject'),
            ListColumn::text('body', 'notifications.inbox.columns.body'),
            ListColumn::make('read', 'notifications.inbox.columns.read', ['read_at'],
                fn (array $row) => __('core.list.'.($row['read_at'] === null ? 'no' : 'yes'))),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);
        $summary[__('core.list.status')] = __('notifications.inbox_statuses.'.($filters['status'] ?? 'active'));

        return $summary;
    }
}
