// NFR-06: fails when a committed file holds something that looks like a
// real secret. Plain Node, no dependencies, run by CI on every push.
// Scans the files git tracks (`git ls-files`), or SECRET_SCAN_ROOT for the
// script's own test. Findings in scripts/secret-scan-allowlist.json (a
// path and the rule, with a reason: test fixtures, documented examples)
// are skipped.
import { execFileSync } from 'node:child_process'
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

// Each pattern is built from parts, so this file never matches itself.
const j = (...parts) => new RegExp(parts.join(''), 'g')

export const RULES = [
  { id: 'private-key', pattern: j('-----BEGIN ((RSA|EC|DSA|OPENSSH|PGP|ENCRYPTED) )?', 'PRIVATE KEY( BLOCK)?-----') },
  { id: 'aws-access-key', pattern: j('\\b(AKIA|ASIA)', '[0-9A-Z]{16}\\b') },
  { id: 'github-token', pattern: j('\\bgh[pousr]_', '[A-Za-z0-9]{36,}\\b') },
  { id: 'github-fine-grained-token', pattern: j('\\bgithub_pat_', '[A-Za-z0-9_]{60,}\\b') },
  { id: 'slack-token', pattern: j('\\bxox[abprs]-', '[A-Za-z0-9-]{10,}') },
  { id: 'stripe-live-key', pattern: j('\\b(sk|rk)_live_', '[A-Za-z0-9]{16,}') },
  { id: 'sentry-dsn', pattern: j('https://[0-9a-f]{32}', '@[a-z0-9.-]*sentry\\.io/\\d+') },
  { id: 'sentry-auth-token', pattern: j('\\bsntrys_', '[A-Za-z0-9+/=_-]{40,}') },
  { id: 'laravel-app-key', pattern: j('APP_KEY=base64:', '[A-Za-z0-9+/]{43}=') },
  { id: 'linode-token', pattern: j('LINODE_(API_)?TOKEN\\s*[=:]\\s*["\']?', '[0-9a-f]{64}') },
  { id: 'db-url-with-password', pattern: j('\\b(postgres(ql)?|mysql|redis)://[^:\\s/]+:', '[^@\\s/]{6,}@(?!localhost|127\\.0\\.0\\.1|postgres|redis)') },
  { id: 'assigned-secret', pattern: j('\\b(DB_PASSWORD|DB_OWNER_PASSWORD|DB_PLATFORM_PASSWORD|REDIS_PASSWORD|MPESA_CONSUMER_SECRET|MPESA_PASSKEY|HR_DIGEST_KEY|METRICS_TOKEN|SENTRY_AUTH_TOKEN)=', '(?!null\\b|\\$|\\{|<|app\\b|app_owner\\b|app_platform\\b)[^\\s#]{8,}') },
]

const SKIP_EXTENSIONS = /\.(png|jpe?g|gif|webp|ico|svg|woff2?|ttf|otf|eot|pdf|zip|gz|lock|snap)$/i
const SKIP_PATHS = [/(^|\/)node_modules\//, /(^|\/)vendor\//, /^package-lock\.json$/, /(^|\/)dist\//]

function walk(root, dir = '') {
  return readdirSync(join(root, dir)).flatMap((entry) => {
    const path = dir ? `${dir}/${entry}` : entry
    return statSync(join(root, path)).isDirectory() ? walk(root, path) : [path]
  })
}

export function trackedFiles(root) {
  if (process.env.SECRET_SCAN_ROOT) return walk(root)
  return execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean)
}

/** Findings in one file's text: [{ rule, line }]. */
export function scanText(text) {
  const findings = []
  const lines = text.split('\n')
  lines.forEach((line, index) => {
    for (const rule of RULES) {
      rule.pattern.lastIndex = 0
      if (rule.pattern.test(line)) findings.push({ rule: rule.id, line: index + 1 })
    }
  })
  return findings
}

export function scan(root, allowlist) {
  const allowed = new Set(allowlist.map((entry) => `${entry.path}#${entry.rule}`))
  const findings = []
  for (const path of trackedFiles(root)) {
    if (SKIP_EXTENSIONS.test(path) || SKIP_PATHS.some((skip) => skip.test(path))) continue
    const full = join(root, path)
    if (!existsSync(full) || statSync(full).size > 2_000_000) continue
    const text = readFileSync(full, 'utf8')
    if (text.includes('\0')) continue
    for (const finding of scanText(text)) {
      if (!allowed.has(`${path}#${finding.rule}`)) findings.push({ path, ...finding })
    }
  }
  return findings
}

const run = process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]
if (run) {
  const root = process.env.SECRET_SCAN_ROOT ?? join(dirname(fileURLToPath(import.meta.url)), '..')
  const allowFile = join(root, 'scripts/secret-scan-allowlist.json')
  const allowlist = existsSync(allowFile) ? JSON.parse(readFileSync(allowFile, 'utf8')).entries : []
  const findings = scan(root, allowlist)
  if (findings.length > 0) {
    for (const f of findings) console.error(`${relative('.', join(root, f.path)) || f.path}:${f.line}: looks like a committed secret (${f.rule})`)
    console.error('Remove the secret and rotate it. A test fixture or documented example goes in scripts/secret-scan-allowlist.json with a reason.')
    process.exit(1)
  }
  console.log('No committed secrets found.')
}
