// MKT-01, MKT-02: pieces shared by the marketplace, the consent screen,
// installed apps and the developer portal. Token classes only (BR-01).
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Money } from '@/components/ds'
import { Field } from '@/components/ds/Field'
import { Textarea } from '@/components/ui/textarea'
import { useLocale } from '@/lib/useLocale'

/** An app's logo, or its first letter on a quiet tile when it has none. */
export function AppLogo({ name, url, size = 'md' }) {
  const box = size === 'lg' ? 'size-12 text-h3' : 'size-10 text-label'
  if (url) return <img src={url} alt="" className={`${box} shrink-0 rounded-md border border-border object-cover`} />
  return (
    <span aria-hidden="true" className={`${box} flex shrink-0 items-center justify-center rounded-md border border-border bg-surface-300 font-medium text-ink-muted`}>
      {(name ?? '?').trim().charAt(0).toUpperCase()}
    </span>
  )
}

/** "Free", or the monthly price in the tenant's currency (currency code first). */
export function AppPrice({ pricing }) {
  const { t } = useTranslation()
  const locale = useLocale()
  if (!pricing || pricing.model === 'free') return <span className="text-ink-muted">{t('marketplace.free')}</span>
  if (!pricing.available) return <span className="text-ink-muted">{t('marketplace.priceUnavailable')}</span>
  return (
    <span className="inline-flex items-baseline gap-1">
      <Money amount={pricing.monthly_minor} currency={pricing.currency} locale={locale} />
      <span className="text-caption text-ink-muted">{t('marketplace.perMonth')}</span>
    </span>
  )
}

/** What an app may do, in plain words, grouped by module. */
export function ScopeList({ scopes = [], emptyText }) {
  const { t } = useTranslation()
  if (scopes.length === 0) return <p className="text-ink-muted">{emptyText ?? t('marketplace.noScopes')}</p>
  const groups = scopes.reduce((all, scope) => {
    ;(all[scope.module_label] ??= []).push(scope)
    return all
  }, {})
  return (
    <div className="flex flex-col gap-4">
      {Object.entries(groups).map(([module, items]) => (
        <section key={module} className="flex flex-col gap-1">
          <h3 className="text-label text-ink-muted">{module}</h3>
          <ul className="flex flex-col gap-1">
            {items.map((scope) => (
              <li key={scope.scope} className="flex flex-wrap items-baseline gap-2 text-body text-ink">
                <span>{scope.label}</span>
                {scope.active === false ? <span className="text-caption text-ink-muted">{t('marketplace.moduleOff')}</span> : null}
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  )
}

/** The webhook events an app receives, by their public names. */
export function EventList({ events = [] }) {
  const { t } = useTranslation()
  if (events.length === 0) return <p className="text-ink-muted">{t('marketplace.noEvents')}</p>
  return (
    <ul className="flex flex-wrap gap-2">
      {events.map((event) => (
        <li key={event} className="rounded-md border border-border px-2 py-1 font-mono text-caption text-ink">
          {event}
        </li>
      ))}
    </ul>
  )
}

/** A multi-line text field (descriptions, redirect URIs). */
export function TextArea({ label, help, error, value, onChange, rows = 4, mono = false, ...rest }) {
  const id = useId()
  return (
    <Field id={id} label={label} help={help} error={error}>
      <Textarea
        id={id}
        rows={rows}
        value={value}
        onChange={onChange}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={error || help ? `${id}-msg` : undefined}
        className={mono ? 'rounded-md border-border-strong bg-surface-200 font-mono text-caption text-ink' : 'rounded-md border-border-strong bg-surface-200 text-body text-ink'}
        {...rest}
      />
    </Field>
  )
}

/** A secret shown once, with a copy button. */
export function SecretValue({ label, value, testId }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-1">
      <span className="text-label text-ink">{label}</span>
      <code data-testid={testId} className="block rounded-md border border-border bg-surface-100 p-3 font-mono text-caption break-all text-ink select-all">
        {value}
      </code>
      <button
        type="button"
        className="self-start text-label text-primary hover:text-primary-hover"
        onClick={() => navigator.clipboard?.writeText(value).catch(() => {})}
      >
        {t('marketplace.copy')}
      </button>
    </div>
  )
}
