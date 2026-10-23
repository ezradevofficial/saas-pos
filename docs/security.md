# Security (NFR-06)

HTTPS everywhere (TLS 1.2+), encryption at rest, secrets in a vault, the OWASP Top 10 covered, an annual penetration test and dependency scanning in CI.

## In CI on every push

| Job | What it checks | Exceptions |
| --- | --- | --- |
| `security (composer audit)` | `composer audit --locked`: PHP packages against the advisory database; fails on any advisory | none |
| `security (npm audit)` | `scripts/npm-audit.mjs`: `npm audit --omit=dev`, fails on high or critical advisories in what ships (web, POS, packages) | `scripts/npm-audit-allowlist.json`: each with a reason and a `review_by` date (90 days at most); past it the advisory fails CI again. Today: `braces` (NativeWind's Tailwind 3 compiler) and `node-forge` (Expo CLI), both build-time only |
| `security (secret scan)` | `scripts/secret-scan.mjs`: every tracked file against committed-secret patterns (private keys, cloud and GitHub tokens, Sentry DSNs and tokens, Laravel keys, database URLs with passwords, assigned passwords and keys) | `scripts/secret-scan-allowlist.json` (path and rule, with a reason) for fixtures; empty today. The script's own test builds its fake secrets at run time |

Built-in tools only: no marketplace scanning actions. GitHub's Dependabot alerts and secret scanning are free for the repository and worth turning on in its settings (owner).

## Security headers

`App\Core\Observability\Http\SecurityHeaders` on every API response (`SecurityHeadersTest`):

- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`;
- `Referrer-Policy: no-referrer` unless a route sets its own;
- `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` on data responses (JSON); HTML pages, PDFs and images keep their route's own policy;
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` only when `APP_ENV=production` and the request is HTTPS, so dev and staging hosts are never pinned.

The web app's HTML is static files from the web server, so its CSP is set there (below).

## Web server: TLS 1.2+ and the web app's CSP

### nginx (web and API on one host)

```nginx
# /etc/nginx/conf.d/tls.conf: TLS 1.2 and 1.3 only, modern ciphers.
ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
ssl_session_timeout 1d;
ssl_session_cache shared:TLS:10m;
ssl_session_tickets off;
ssl_stapling on;
ssl_stapling_verify on;

server {
    listen 80;
    server_name app.example.com api.example.com;
    return 301 https://$host$request_uri;
}

# The web app (static build in <path>/web).
server {
    listen 443 ssl;
    http2 on;
    server_name app.example.com;
    ssl_certificate     /etc/letsencrypt/live/app.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.example.com/privkey.pem;
    root <path>/web;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    # Styles: 'unsafe-inline' is needed for tenant themes, applied at run time as CSS variables
    # on elements (BR-01). No inline scripts. connect-src names the API and, when Sentry is on,
    # its ingest host. Images come from the API, never straight from Object Storage
    # (NFR-07), so https://*.linodeobjects.com in img-src is no longer needed.
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.linodeobjects.com; font-src 'self' data:; connect-src 'self' https://api.example.com https://*.ingest.sentry.io; frame-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'" always;

    location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }
    location / { add_header Cache-Control "no-cache"; try_files $uri /index.html; }
}

# The API (<path>/api/public).
server {
    listen 443 ssl;
    http2 on;
    server_name api.example.com;
    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;
    root <path>/api/public;
    client_max_body_size 25m;

    # Never public: scraped on the private network (docs/observability.md), and the TLS ask
    # endpoint for custom domains (docs/integrations.md).
    # Also catches /index.php/metrics and a trailing slash, which reach the same route.
    location ~ ^/(index\.php/)?metrics/?$ { deny all; }
    location = /api/v1/tls/ask { deny all; }

    location / { try_files $uri /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Behind the NodeBalancer, terminate TLS on the NodeBalancer (TLS 1.2+ is its default; upload the certificate) or pass TCP through to nginx. Either way set `TRUSTED_PROXIES` to the NodeBalancer's backend range so client addresses are real.

### Caddy (custom domains, BR-05)

Caddy already serves tenant custom domains with on-demand TLS (docs/integrations.md). Caddy uses TLS 1.2 and 1.3 only by default; pin it:

```caddyfile
{
    on_demand_tls { ask http://127.0.0.1:8080/api/v1/tls/ask }
}
https:// {
    tls { protocols tls1.2 tls1.3; on_demand }
    header Strict-Transport-Security "max-age=31536000; includeSubDomains"
    header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.linodeobjects.com; font-src 'self' data:; connect-src 'self' https://api.example.com https://*.ingest.sentry.io; frame-ancestors 'none'; base-uri 'self'; object-src 'none'"
    root * <path>/web
    try_files {path} /index.html
    file_server
}
```

Check a host with `nmap --script ssl-enum-ciphers -p 443 <host>` or SSL Labs: only TLS 1.2 and 1.3 listed, grade A.

## Encryption at rest

- **Database**: Linode Managed PostgreSQL encrypts its storage and its backups at rest (Linode's managed database service; confirm on the cluster's settings page when it is created). The staging and pilot host (ADR 025) keeps its database on the Linode's own disk: create the Linode with disk encryption on (Linode Disk Encryption, where the region offers it; it cannot be turned on later without rebuilding). A self-managed PostgreSQL on a separate volume must use an encrypted volume: Linode Block Storage volumes are encrypted at rest when the volume's encryption option is on (the default for new volumes in supported regions; check it when creating the volume, it cannot be turned on later).
- **Linode instances**: create them with disk encryption on (Linode's local disk encryption, available in most regions); confirm per region.
- **Object Storage** (every tenant file, exports): Linode Object Storage is encrypted at rest; buckets are private and nothing is presigned: the API streams each file after its own checks, behind signed links of 5 to 15 minutes bound to the user (NFR-07, docs/deploy-linode.md, Object Storage).
- **In the application**: the most sensitive fields are encrypted with the app key on top (payment provider credentials, webhook signing secrets, HR identifiers with a separate digest key `HR_DIGEST_KEY`); passwords, PINs and tokens are stored only as hashes.
- **Owner action**: record in this file the region, the cluster and the encryption setting of each environment once provisioned (docs/deploy-linode.md).

## Secrets in a vault

Today secrets live in each host's `api/.env` (owned by root, group = the deploy user, mode 0640; ADR 025), in `/root/provision.env` (root, 0600) on the single host, and in GitHub Actions secrets for the deploy. For staging and the pilot there is no vault: the owner keeps a copy of both files in their password manager (owner decision, 2026-10-22). Options to move them to a vault, in order of effort:

1. **GitHub Actions environments as the source, rendered on deploy**: keep every production secret as an environment secret and have the deploy write `.env` from them. No new service; secrets are encrypted at rest by GitHub and visible to no one after saving. Weakest audit trail.
2. **Doppler or Infisical (hosted)**: a secrets manager with audit logs, rotation and per-environment access; the host fetches at boot with a service token (`doppler run -- php artisan ...` or a rendered `.env`). Small monthly fee; Infisical can also be self-hosted.
3. **HashiCorp Vault (self-hosted on its own Linode)**: the most control (dynamic database credentials, leases), and the most operations work. Worth it once there is a team to run it.

Recommendation: option 2 before production launch. Whatever is chosen: rotate `HR_DIGEST_KEY` with `hr:rekey-digests`, database passwords with `ALTER ROLE` (ADR 002), and `APP_KEY` with the runbook below.

## Rotating `APP_KEY` (runbook)

`APP_KEY` encrypts the sensitive columns (the models' `encrypted` casts and the procurement tender keys), queued jobs marked encrypted, and signs URLs. Rotate it once a year, when someone who could read it leaves, and at once if it may have leaked. `php artisan app:reencrypt` re-encrypts every encrypted column with the new key.

**What the command does.** It finds the columns itself: every `encrypted`, `encrypted:<type>` or `AsEncrypted` cast of a model in `app/` and `modules/`, plus the columns encrypted by hand (`EncryptedColumns::MANUAL`, today `procurement_tenders.seal_secret` and `public_token_secret`); `KeyRotationTest` fails if code elsewhere starts calling `Crypt`. Tenant tables are processed tenant by tenant, every tenant whatever its status (the security-definer function `app_tenants_for_key_rotation()`, ids only), each under its own row-level security as the runtime role; `platform_users` through the platform connection. It never uses the owner's credentials, so it runs on the API host with its normal `.env` (it needs `DB_PLATFORM_*`). Rows are read in chunks by primary key (`--chunk`, default 500), one transaction each. A value the new key already opens is left alone, and a row is written only if its value is still the one read, so the command is idempotent, can be stopped and run again, and never overwrites a value saved meanwhile. It prints counts per column (re-encrypted, already current, failed), never a value, a ciphertext or a row id, and exits 1 if any value opens with no key.

**Steps.**

1. Make the new key on a workstation: `php artisan key:generate --show`. Store it in the vault with the date.
2. On **every** API and queue host (and the scheduler), set the same values: `APP_KEY` to the new key and the old one at the front of `APP_PREVIOUS_KEYS` (comma-separated, newest first, keeping any older key still listed). New values now use the new key; old ones still open. Rehearsed on 2026-10-22:

   ```sh
   cd <path>/api
   OLD="$(grep '^APP_KEY=' .env | cut -d= -f2-)"
   NEW="base64:$(openssl rand -base64 32)"   # or the key made in step 1
   # Keep any earlier keys: APP_PREVIOUS_KEYS is comma-separated, newest first.
   sed -i "s|^APP_KEY=.*|APP_KEY=$NEW|" .env
   grep -q '^APP_PREVIOUS_KEYS=' .env && sed -i "s|^APP_PREVIOUS_KEYS=|APP_PREVIOUS_KEYS=$OLD,|" .env || echo "APP_PREVIOUS_KEYS=$OLD" >> .env
   php artisan config:cache
   sudo systemctl reload php8.3-fpm
   php artisan horizon:terminate        # systemd starts Horizon on the new key
   php artisan app:preflight
   ```

   What the rehearsal showed: with the old key in `APP_PREVIOUS_KEYS`, a device secret encrypted before the rotation still verified and sign-in worked. Without it, the same request answered 500 (`DecryptException: The MAC is invalid`): every encrypted column (device secrets, 2FA secrets, payment and SSO credentials, HR identifiers) is unreadable until `app:reencrypt` has run. Sign-in tokens are hashes and `HR_DIGEST_KEY` is separate, so neither changes.
3. `php artisan app:reencrypt --dry-run`: how many values each column will re-encrypt. A failure here means a value no listed key opens: stop and investigate before going on.
4. `php artisan app:reencrypt`. On a large database it can be staged in quiet hours with `--stop-after=50000`; run it again until it no longer says it stopped.
5. Run it once more: it must report `Re-encrypted: 0` and `failed: 0`.
6. Wait for what still carries the old key outside the columns: queued encrypted jobs (let Horizon's queues drain; retry or flush `failed_jobs`), signed download links (15 minutes; Laravel accepts previous keys meanwhile) and staff sign-in challenges (minutes). A day is plenty.
7. Remove the old key from `APP_PREVIOUS_KEYS` on every server and deploy. Run `php artisan app:reencrypt --dry-run`: `failed: 0` proves everything opens with the new key alone. If it fails, put the old key back and repeat from step 4.
8. Note the date and the counts in the security log. `HR_DIGEST_KEY` is separate (`hr:rekey-digests`); so are the procurement bid seals, which use the tender keys (ADR 019; `procurement:rewrap-keys` still works and `app:reencrypt` covers the same columns).

**Backups.** A restore from before the rotation holds values under the old key. Keep the old key in the vault (never in an `.env`) for as long as such backups are kept (docs/backups.md); after that kind of restore, put it in `APP_PREVIOUS_KEYS` and run steps 3 to 7.

### Decisions (accepted by the owner 2026-10-22)

- **Rotation schedule**: once a year and after any suspected exposure or the departure of someone who held the key. The owner may choose another interval.
- **How long to keep an old key** in the vault: as long as the backups made before the rotation (the backup retention period), so a restore stays readable.
- **Who runs it**: whoever deploys, from the API host, as part of a planned change; it is not scheduled.

## OWASP Top 10 (2021) and the controls that cover it

| Risk | Controls in this codebase | Tests |
| --- | --- | --- |
| A01 Broken access control | PostgreSQL row-level security on every tenant table (ADR 002); policies and Form Requests on every endpoint, scoped by company, branch and location (RBAC-01..09); field and limit rules; four-eyes approvals; no client id trusted without a scoped lookup | tenant-isolation suite (seven CI shards), `RlsCoverageTest`, permission-denied tests per endpoint |
| A02 Cryptographic failures | TLS 1.2+ only (above), HSTS in production; secrets hashed or encrypted (above); signed URLs for downloads; HMAC-signed webhooks | `SecurityHeadersTest`, `ExportColumnsTest` |
| A03 Injection | Eloquent and bound parameters; identifiers quoted where SQL is built; Blade escaping; the template engine escapes every value; CSV export guards formula injection | export and template tests |
| A04 Insecure design | modular monolith with explicit seams (ADR 001), threat-reviewed ADRs per feature, security-definer functions returning ids only (ADR 002) | `SecurityDefinerFunctionsTest` |
| A05 Security misconfiguration | `EnvironmentGuard` refuses development drivers outside local and testing; `app:preflight` before every migration (APP_DEBUG, keys, roles, platform role, error tracking, metrics access); security headers; `/metrics` and `/api/v1/tls/ask` private | `EnvironmentGuardTest`, `PreflightTest` |
| A06 Vulnerable components | `composer audit`, `npm audit` (dated exceptions) in CI | CI jobs |
| A07 Identification and authentication failures | OTP verification, password policy, 2FA, lockout, idle timeouts, device PINs with actor proofs, SSO (AUTH-01..13) | Identity feature tests |
| A08 Software and data integrity failures | CI-gated deploys of the exact tested commit; hash-chained, append-only audit log (AUD-03); append-only consent records; idempotent sync | audit chain tests, `ConsentRecordTest` |
| A09 Security logging and monitoring failures | audit of every create, update, approval, void, refund, override and role change (AUD-01, RBAC-12); JSON logs with request ids; Sentry; alert rules (docs/observability.md) | `RequestIdTest`, `ScrubberTest` |
| A10 Server-side request forgery | tenant webhooks resolve the host once, refuse private, loopback and link-local addresses and pin the call to the checked address (`WebhookSender`, `HostResolver`); provider calls (`OutboundHttp`) are HTTPS-only, without redirects, proxies or credentials in the URL, to platform-configured hosts | webhook and automation tests |

## Annual penetration test

Once a year and before the production launch, by an external firm (owner chooses; CREST- or OSCP-certified testers). Scope: the web app, the API (including `/api/v1` with API keys and OAuth), the storefront, the POS app and its sync API, the platform console, and the Linode hosts' public surface. Provide two staging tenants with sample data and test accounts for each role. Findings are tracked as issues with the requirement IDs they touch; high and critical ones are fixed and retested before the report is closed.
