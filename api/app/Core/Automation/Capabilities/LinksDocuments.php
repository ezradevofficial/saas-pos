<?php

namespace App\Core\Automation\Capabilities;

/**
 * Where a document opens in the web app, for links in automation
 * notifications. Only a relative app path (`/purchase-orders/…`) is ever
 * sent (Notifier::safeLink); without this capability the notification
 * carries no link.
 */
interface LinksDocuments
{
    public function documentLink(string $documentId): string;
}
