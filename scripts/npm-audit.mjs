// NFR-06: dependency scanning for the JavaScript workspaces. Runs
// `npm audit --omit=dev --json` (what ships: web, POS, packages) and fails
// on any high or critical advisory, except those listed in
// scripts/npm-audit-allowlist.json with a reason and a `review_by` date.
// An entry past its date no longer counts, so every exception is looked at
// again. Plain Node, no dependencies.
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const LEVELS = ['high', 'critical']

/** Advisories at high or critical: [{ id, package, severity, title, url }]. */
export function advisories(report) {
  const found = new Map()
  for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
    for (const via of vulnerability.via ?? []) {
      if (typeof via !== 'object' || !LEVELS.includes(via.severity)) continue
      const id = String(via.url ?? '').split('/').pop() || String(via.source)
      found.set(id, { id, package: via.name, severity: via.severity, title: via.title, url: via.url })
    }
  }
  return [...found.values()].sort((a, b) => a.id.localeCompare(b.id))
}

/**
 * Whether `npm audit` produced a real report. When the registry times out npm
 * still exits with JSON, but it holds an `error` and no `metadata`: that must
 * fail the check, never read as "no advisories".
 */
export function completed(report) {
  return report != null && typeof report === 'object' && !report.error && report.metadata?.vulnerabilities != null
}

/** The last day an exception may name as its review date: 90 days from `today` (YYYY-MM-DD). */
export function latestReview(today) {
  const date = new Date(`${today}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + 90)
  return date.toISOString().slice(0, 10)
}

/** What fails the check: advisories not allowed, and allow-list entries expired or no longer needed. */
export function evaluate(report, allowlist, today) {
  const latest = latestReview(today)
  const live = new Map(allowlist.filter((entry) => entry.review_by >= today && entry.review_by <= latest).map((entry) => [entry.id, entry]))
  const tooFar = allowlist.filter((entry) => entry.review_by > latest).map((entry) => entry.id)
  const found = advisories(report)
  const failing = found.filter((advisory) => !live.has(advisory.id))
  const unused = allowlist.filter((entry) => !found.some((advisory) => advisory.id === entry.id)).map((entry) => entry.id)
  const expired = allowlist.filter((entry) => entry.review_by < today).map((entry) => entry.id)
  return { failing, unused, expired, tooFar, allowed: found.filter((advisory) => live.has(advisory.id)) }
}

const run = process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]
if (run) {
  const root = join(dirname(fileURLToPath(import.meta.url)), '..')
  const audit = () => {
    try {
      return execFileSync('npm', ['audit', '--omit=dev', '--json'], { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
    } catch (error) {
      // npm audit exits non-zero when it finds anything; the JSON is still on stdout.
      if (!error.stdout) throw error
      return error.stdout
    }
  }
  // One retry: the registry's bulk advisory endpoint times out now and then.
  let report = JSON.parse(audit())
  if (!completed(report)) {
    console.log('::warning::npm audit did not complete; retrying once')
    report = JSON.parse(audit())
  }
  if (!completed(report)) {
    console.error(`npm audit did not complete: ${report?.error?.summary ?? 'no report'}. The dependency check cannot pass without one; rerun the job.`)
    process.exit(1)
  }
  const allowlist = JSON.parse(readFileSync(join(root, 'scripts/npm-audit-allowlist.json'), 'utf8')).entries
  const today = new Date().toISOString().slice(0, 10)
  const { failing, unused, expired, tooFar, allowed } = evaluate(report, allowlist, today)

  for (const a of allowed) console.log(`allowed until review: ${a.id} ${a.package} (${a.severity}) ${a.title}`)
  for (const id of unused) console.log(`::notice::npm-audit-allowlist entry ${id} is no longer needed; remove it`)
  for (const id of expired) console.log(`::warning::npm-audit-allowlist entry ${id} is past its review_by date`)
  for (const id of tooFar) console.log(`::warning::npm-audit-allowlist entry ${id} has a review_by more than 90 days out; it does not count until shortened`)
  if (failing.length > 0) {
    for (const a of failing) console.error(`${a.severity}: ${a.package}: ${a.title} ${a.url}`)
    console.error('Upgrade the package, or add a reviewed entry with a reason and review_by date to scripts/npm-audit-allowlist.json.')
    process.exit(1)
  }
  console.log('No unreviewed high or critical advisories in production dependencies.')
}
