<?php

namespace App\Core\DataExport;

/**
 * EXP-02: which columns a full tenant export leaves out. A reviewed list:
 * every column whose name looks like a secret (SECRET_LIKE) must be
 * classified here, as DENIED (left out of every row) or ALLOWED (exported,
 * with the reason it is not a secret). ExportColumnsTest lists every column
 * of every exported table and fails on a secret-like one left unclassified,
 * so a new table or column cannot slip into exports unreviewed.
 *
 * Secrets are never exported, hashed or not: a hash of a password, PIN or
 * token can be attacked offline, and an encrypted credential is only as
 * safe as the key it was encrypted with.
 */
final class ExportColumns
{
    /** Names that look like secrets: they must be classified below. */
    public const SECRET_LIKE = '/(password|secret|token|pin|otp|two_factor|api_key|credential|hash)/i';

    /** `table.column` => what it holds. Left out of every exported row. */
    public const DENIED = [
        'users.password' => 'the password hash (AUTH-02)',
        'users.two_factor_secret' => 'the TOTP secret (AUTH-03)',
        'users.two_factor_last_used_step' => 'TOTP replay-guard state (AUTH-03)',
        'imports.run_token' => 'one-time worker claim token for an import run (IMP-02)',
        'user_pins.pin_hash' => 'POS PIN hash (AUTH-06)',
        'user_pins.pin_salt' => 'POS PIN salt (AUTH-06)',
        'user_pins.pin_key' => 'POS PIN verification key sent to tills (AUTH-06)',
        'user_pins.card_hash' => 'staff card hash (AUTH-06)',
        'user_pins.card_salt' => 'staff card salt (AUTH-06)',
        'user_pins.card_key' => 'staff card verification key sent to tills (AUTH-06)',
        'device_secrets.secret' => 'a device\'s signing secret (AUTH-08)',
        'devices.pairing_code_hash' => 'one-time pairing code hash (TEN-05)',
        'invitations.token_hash' => 'invitation link token hash (AUTH-05)',
        'approval_email_tokens.token_hash' => 'emailed approve and reject token hash (APR-08)',
        'document_shares.token_hash' => 'shared document link token hash (TPL-04)',
        'payment_methods.secrets' => 'payment provider credentials (encrypted)',
        'payment_methods.callback_token' => 'payment provider callback token (encrypted)',
        'payment_methods.callback_token_hash' => 'payment provider callback token hash',
        'company_fiscal_settings.credentials' => 'tax authority credentials (encrypted)',
        'automation_rules.webhook_secret' => 'webhook signing secret (AUTO-03, encrypted)',
        'automation_webhook_deliveries.url' => 'the webhook URL called, which may carry a token (AUTO-03, encrypted)',
        // Denied rather than decrypted: the app only ever shows it masked, and
        // decrypting would put the application key in the export path.
        'payment_intents.phone' => 'the payer\'s phone number, shown only masked (encrypted)',
        'billing_payment_methods.token' => 'a card provider\'s token for charging the subscription (SUB-04, encrypted)',
        'billing_payments.redirect_url' => 'a hosted card checkout link for a subscription payment, which may carry a session token (SUB-04)',
        'billing_payment_methods.phone' => 'the wallet phone number of a saved subscription payment method, shown only masked (encrypted)',
    ];

    /**
     * `table.column` => a SQL expression over the row alias `t` exported in
     * its place. Webhook URLs are write-only (AUTO-03): actions are exported
     * as the API shows them (ActionList::shown), with `url_display` (scheme,
     * host and path, no query or fragment) and `has_url`.
     */
    public const TRANSFORMS = [
        'automation_rules.actions' => <<<'SQL'
            case when jsonb_typeof(t."actions") = 'array' then (
                select coalesce(jsonb_agg(case
                    when jsonb_typeof(e.a) = 'object' and e.a->>'type' = 'webhook' then
                        (e.a - 'url') || jsonb_build_object(
                            'url_display', case when jsonb_typeof(e.a->'url') = 'string' then split_part(split_part(e.a->>'url', '#', 1), '?', 1) end,
                            'has_url', jsonb_typeof(e.a->'url') = 'string')
                    else e.a end order by e.o), '[]'::jsonb)
                from jsonb_array_elements(t."actions") with ordinality as e(a, o)
            ) else t."actions" end
            SQL,
    ];

    /** `table.column` => why it is not a secret although its name looks like one. */
    public const ALLOWED = [
        'loyalty_gift_cards.code_hash' => 'H(H(code)) of a 16-character random gift card code (about 79 bits): not reversible, and not the H(code) proof a till must present to redeem; every till of the company holds it to find cards offline (LOY-20, LOY-22, M8)',
        'approval_requests.token_id' => 'the id of a workflow token row, not a credential',
        'audit_chain_heads.hash' => 'audit chain integrity hash (AUD-03), needed to verify the exported log',
        'audit_logs.hash' => 'audit chain integrity hash (AUD-03)',
        'audit_logs.prev_hash' => 'audit chain integrity hash (AUD-03)',
        'import_mappings.mapping' => '"pin" inside "mapping": a remembered file-column mapping (IMP-01)',
        'imports.mapping' => '"pin" inside "mapping": the file-column mapping of an import (IMP-01)',
        'document_workflow_links.mapping' => '"pin" inside "mapping": a field mapping between documents',
        'fiscal_submissions.request_hash' => 'fingerprint of the document sent to the tax authority',
        'pos_cash_movements.payload_hash' => 'idempotency fingerprint of an uploaded record (NFR-04)',
        'pos_refunds.payload_hash' => 'idempotency fingerprint of an uploaded record (NFR-04)',
        'pos_sale_voids.payload_hash' => 'idempotency fingerprint of an uploaded record (NFR-04)',
        'pos_sales.payload_hash' => 'idempotency fingerprint of an uploaded record (NFR-04)',
        'roles.requires_two_factor' => 'a role\'s policy flag',
        'tenant_domains.verification_token' => 'the public value of a DNS TXT record (BR-05)',
        'user_pins.pin_iterations' => 'a hashing parameter, not the PIN',
        'user_pins.pin_digits' => 'the PIN\'s length',
        'user_pins.pin_set_at' => 'when the PIN was set',
        'users.two_factor_confirmed_at' => 'when two-step sign-in was turned on',
        'users.two_factor_method' => 'which two-step method the user chose',
    ];

    /** @return list<string> the denied columns of $table */
    public static function deniedIn(string $table): array
    {
        $denied = [];

        foreach (array_keys(self::DENIED) as $key) {
            [$t, $column] = explode('.', $key, 2);

            if ($t === $table) {
                $denied[] = $column;
            }
        }

        return $denied;
    }

    public static function isSecretLike(string $column): bool
    {
        return preg_match(self::SECRET_LIKE, $column) === 1;
    }
}
