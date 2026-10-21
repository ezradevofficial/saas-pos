# Observability (NFR-10, NFR-01)

Errors go to Sentry, logs are JSON lines with a request id, metrics are a Prometheus endpoint on the private network, and a short list of alert rules says when someone is woken up. Nothing here sends personal data off the servers or the devices.

## One scrubber for three apps

`packages/scrub/rules.json` is the single list of what is never sent: values under sensitive keys (passwords, tokens, PINs, OTPs, card and bank numbers, ID and tax numbers, emails, phones, addresses, dates of birth, IP addresses) and secret- or PII-looking text in any string (bearer tokens, JWTs, Sanctum tokens, `?token=` and `?signature=` in URLs, emails, card-length numbers, Kenyan and Congolese phone numbers). Ids, the release, `tenant_id` and `request_id` are kept.

| App | Implementation | Used by |
| --- | --- | --- |
| API | `App\Core\Observability\Scrubber` reads `api/config/scrub-rules.json` (a copy: only `api/` reaches the servers) | Sentry hooks (`SentryEvents`), every log channel (`ScrubLogs` tap) |
| Web | `@app/scrub` | `web/src/lib/sentry.js` |
| POS | `@app/scrub` | `pos/src/lib/sentry.js` |

`packages/scrub/fixtures.json` is run by both implementations (`node --test packages/scrub/test/*.test.js` in CI's i18n job, and `ScrubberTest`), and `ScrubberTest` fails when the API copy of the rules differs. To change a rule: edit `packages/scrub/rules.json`, copy it to `api/config/scrub-rules.json`, add a fixture.

## Sentry

Off until the owner sets a DSN. With no DSN the SDKs send nothing (API) or are never loaded (web, POS).

| | API | Web | POS |
| --- | --- | --- | --- |
| DSN | `SENTRY_LARAVEL_DSN` in `api/.env` | repository variable `SENTRY_WEB_DSN` (baked into the build) | `EXPO_PUBLIC_SENTRY_DSN` at build time (EAS) |
| Release | `api/REVISION`, written by the deploy (the commit) | `VITE_SENTRY_RELEASE` (the commit) | `EXPO_PUBLIC_SENTRY_RELEASE` |
| Environment | `APP_ENV` | the deploy target | `EXPO_PUBLIC_SENTRY_ENVIRONMENT` |
| Tenant tag | `tenant_id` on every tenant switch (`TenantContext::onChange`) | the signed-in user's `tenant_id` | the device settings' `tenant_id` |
| Never sent | default PII, SQL bindings | default PII | default PII, screenshots, view hierarchies |
| Source maps | n/a | built hidden, uploaded with `sentry-cli`, deleted, only when the `SENTRY_AUTH_TOKEN` secret is set | uploaded by the Sentry Expo plugin during an EAS build when `SENTRY_AUTH_TOKEN`, `SENTRY_ORG` and `SENTRY_PROJECT` are set |

Tracing is off (`SENTRY_TRACES_SAMPLE_RATE` unset). Start at `0.05` once the plan's event quota is known. No user names, emails or phones are ever tagged; Sentry users carry an id only.

## Logs

- **Format.** Production sets `LOG_STACK=json` (one JSON object per line in `storage/logs/app.json.log`, rotated daily, 14 days) or `json_stderr` (the systemd journal). Every channel, the plain-text ones included, is scrubbed.
- **Request id.** `RequestId` is the first middleware. It keeps a well-formed `X-Request-Id` from the load balancer (8 to 128 characters of `A-Za-z0-9._-`), else makes a UUID v7, returns it on the response and puts it in Laravel's `Context`. Context is written on every log line and serialised into every job the request queues, so a job's logs carry the id of the request that caused it (`RequestIdTest` runs a real worker). The tenant id is in Context too.
- **Shipping.** Logs stay on the host until the owner chooses a central store. Options: Linode's Object Storage with a nightly `logrotate` upload (cheapest), Grafana Loki (self-hosted or Grafana Cloud's free tier) with Promtail reading the JSON file, or Better Stack Logs. All read the JSON lines as they are.

## Metrics: `GET /metrics`

Prometheus text format. **Never public**: the endpoint answers 404 unless the caller sends `Authorization: Bearer $METRICS_TOKEN` or comes from an address in `METRICS_ALLOW` (comma-separated CIDRs: name the scraper's own private address, for example `192.168.140.25/32`, never the whole Linode private range `192.168.128.0/17`, which contains the NodeBalancer's backend range). Whatever the token or allow-list, a request whose address is in `TRUSTED_PROXIES` or the NodeBalancer's range `192.168.255.0/24` is refused: it came through the load balancer, from the internet. In production `app:preflight` fails when `METRICS_ALLOW` is set without `METRICS_TOKEN`. With neither set nobody can read it. Block `/metrics` at the public web server as well (`location ~ ^/(index\.php/)?metrics/?$ { deny all; }`, docs/security.md).

| Metric | Type | Labels | Source |
| --- | --- | --- | --- |
| `http_requests_total` | counter | `method`, `route` (the pattern, never ids), `status` (`2xx`...) | `RecordMetrics` middleware, kept in Redis (`METRICS_STORE=redis`), shared by every API host |
| `http_request_duration_seconds` | histogram | `method`, `route`; buckets 25 ms to 5 s | same |
| `app_queue_size` | gauge | `queue` (every Horizon queue: default, notifications, automation, payments, fiscal, exports, imports, webhooks) | Redis |
| `app_horizon_failed_jobs` | gauge | `window` (`recent`: last hour; `total`) | Horizon |
| `app_horizon_masters` | gauge | | Horizon |
| `app_database_up`, `app_database_ping_seconds` | gauge | | `select 1` on the runtime role |
| `app_sync_lag_seconds` | gauge | | the oldest transaction holding back device sync (ADR 004) |

Health probes and the scrape itself are not counted. `-1` means the value could not be read.

## Health

| Route | Answers | For |
| --- | --- | --- |
| `GET /up` | 200 when the app boots (Laravel), with `X-Sync-Lag-*` headers | process managers, existing monitors |
| `GET /health/live` | 200 `{"status":"ok"}`, no dependency | liveness |
| `GET /health/ready` | 200 when the database, Redis, the queue connection and the media disk (write, read, delete a probe) all answer; 503 otherwise, naming only which check failed | the NodeBalancer's health check and the uptime monitor |

Horizon is reported in `/health/ready` but only counts when `HEALTH_REQUIRE_HORIZON=true`, so a stopped worker host never takes the API out of the load balancer (the alert below catches it). `php artisan health:status` (every minute) writes `status.json` with component states only.

## Alert rules

Prometheus-style rules for whichever alerting the owner picks (Grafana Cloud, a self-hosted Prometheus with Alertmanager, or the uptime monitor for the first two).

| Alert | Condition | For | Severity |
| --- | --- | --- | --- |
| API down | uptime monitor: `/health/ready` not 200 from 2 regions | 2 min | page |
| Readiness failing | `app_database_up == 0` | 1 min | page |
| Error rate | `sum(rate(http_requests_total{status="5xx"}[5m])) / sum(rate(http_requests_total[5m])) > 0.02` | 5 min | page |
| Slow reads (NFR-02) | `histogram_quantile(0.95, sum by (le) (rate(http_request_duration_seconds_bucket{method="GET"}[10m]))) > 0.5` | 15 min | ticket |
| Queue backlog | `app_queue_size{queue=~"default|notifications|payments|fiscal|webhooks"} > 500` | 10 min | ticket; page for `payments` or `fiscal` |
| Workers stopped | `app_horizon_masters == 0` | 5 min | page |
| Failed jobs | `app_horizon_failed_jobs{window="recent"} > 20` | 15 min | ticket |
| Device sync held back (NFR-04) | `app_sync_lag_seconds > 120` | 10 min | ticket |
| Error spike | Sentry: a new issue with more than 50 events in an hour, or any issue in `payments` or `fiscal` code | | ticket |
| Backups | Linode: a daily backup missing, or the monthly `backup:verify` failing (docs/backups.md) | | ticket |
| Certificates | uptime monitor: TLS certificate expires within 14 days | | ticket |

Page: the on-call phone. Ticket: the team channel, handled the same working day.
