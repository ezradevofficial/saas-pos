import { useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Icon, Money } from '@/components/ds'
import { moneyText } from '@/lib/money'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { usePageTitle } from '@/lib/usePageTitle'
import { cn } from '@/lib/utils'
import { useOnboarding, useOnboardingWrite } from './useOnboarding'

const COUNTERS = [
  { unit: 'outlet', min: 1 },
  { unit: 'device', min: 0 },
  { unit: 'employee', min: 1 },
]

function Counter({ unit, value, min, onChange, help }) {
  const { t } = useTranslation()
  const label = t(`onboarding.modules.counters.${unit}`)
  return (
    <div className="flex flex-col gap-2">
      <span className="text-label text-ink" id={`counter-${unit}`}>
        {label}
      </span>
      <div className="flex items-center gap-2" role="group" aria-labelledby={`counter-${unit}`}>
        <Button aria-label={t('onboarding.modules.fewer', { what: label })} disabled={value <= min} onClick={() => onChange(Math.max(min, value - 1))}>
          <Icon name="minus" />
        </Button>
        <output aria-live="polite" className="min-w-10 text-center text-amount tabular-nums">
          {value}
        </output>
        <Button aria-label={t('onboarding.modules.more', { what: label })} onClick={() => onChange(value + 1)}>
          <Icon name="plus" />
        </Button>
      </div>
      <span className="text-caption text-ink-muted">{help}</span>
    </div>
  )
}

/**
 * ONB-03, ONB-04 (OnboardModules): modules grouped, quantities, the live
 * price from the catalogue's quote (debounced) in KES or USD by country,
 * the cheaper bundle when there is one, monthly or yearly, then the free
 * trial starts with that selection.
 */
export default function ModulePicker() {
  const { t } = useTranslation()
  usePageTitle(t('onboarding.modules.title'))
  const { data: state } = useOnboarding()
  if (!state) return <p className="text-ink-muted">{t('common.loading')}</p>
  return <Picker state={state} />
}

/** The picker opens on the saved selection (the business type's suggestion). */
function initialDraft(state) {
  const selection = state.selection ?? {}
  return {
    modules: selection.modules ?? [],
    quantities: { outlet: 1, device: 2, employee: 1, ...(selection.quantities ?? {}) },
    cycle: selection.cycle ?? 'monthly',
    bundle: selection.bundle ?? null,
  }
}

function Picker({ state }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const country = state.country
  const [draft, setDraft] = useState(() => initialDraft(state))

  const catalogue = useQuery({
    queryKey: ['catalogue', country, locale],
    queryFn: () => api.get(`catalogue?country=${country}`),
    enabled: Boolean(country),
  })
  const offer = catalogue.data?.data

  const request = useMemo(
    () =>
      country
        ? {
            country,
            cycle: draft.cycle,
            modules: draft.modules,
            bundle: draft.bundle,
            quantities: { outlet: draft.quantities.outlet, device: draft.quantities.device, employee: draft.quantities.employee },
          }
        : null,
    [draft, country],
  )
  const settled = useDebounced(request, 400)
  const quote = useQuery({
    queryKey: ['catalogue', 'quote', settled],
    queryFn: () => api.post('catalogue/quote', settled),
    enabled: Boolean(settled) && settled.modules.length + (settled.bundle ? 1 : 0) > 0,
    placeholderData: (previous) => previous,
  })
  const result = quote.data?.data
  // A selection that cannot be sold (an add-on without its bundle) answers 422, sometimes with a bundle that fits.
  const refusal = quote.error
  const suggestion = result?.suggestion ?? refusal?.data?.suggestion ?? null

  const start = useOnboardingWrite(() => api.put('onboarding/modules', request), { onSuccess: () => navigate('/onboarding/setup') })
  const startErrors = formErrors(start.error, [])

  if (!offer) return <p className="text-ink-muted">{t('common.loading')}</p>

  const currency = offer.currency
  const name = (key) => offer.modules.find((m) => m.key === key)?.name ?? offer.bundles.find((b) => b.key === key)?.name ?? key
  const groups = Object.entries(state.module_groups ?? {})
  const listed = groups.flatMap(([, keys]) => keys)
  const sellable = offer.modules.filter((m) => m.kind === 'module')
  const grouped = groups.map(([key, keys]) => ({ key, modules: sellable.filter((m) => keys.includes(m.key)) }))
  const rest = sellable.filter((m) => !listed.includes(m.key))
  if (rest.length) grouped[grouped.length - 1]?.modules.push(...rest)

  const toggle = (key) =>
    setDraft((current) => ({
      ...current,
      modules: current.modules.includes(key) ? current.modules.filter((m) => m !== key) : [...current.modules, key],
      bundle: null,
    }))
  const setQuantity = (unit) => (value) => setDraft((current) => ({ ...current, quantities: { ...current.quantities, [unit]: value } }))
  const bundle = draft.bundle ? offer.bundles.find((b) => b.key === draft.bundle) : null
  const included = (offer.modules.find((m) => m.key === 'pos')?.includes?.pos_device ?? 0) * draft.quantities.outlet

  return (
    <div className="flex flex-col gap-10 lg:flex-row lg:items-start">
      <section className="flex min-w-0 flex-1 flex-col gap-6">
        <div className="flex flex-col gap-2">
          <h1 className="text-display text-ink">{t('onboarding.modules.title')}</h1>
          <p className="text-body-lg text-ink-muted">
            {state.business_type
              ? t('onboarding.modules.introFor', { type: state.business_types.find((type) => type.key === state.business_type)?.name ?? '' })
              : t('onboarding.modules.intro')}
          </p>
        </div>

        <div className="grid gap-4 rounded-lg border border-border bg-surface-200 p-5 sm:grid-cols-3">
          {COUNTERS.map(({ unit, min }) => (
            <Counter
              key={unit}
              unit={unit}
              min={min}
              value={draft.quantities[unit]}
              onChange={setQuantity(unit)}
              help={unit === 'device' ? t('onboarding.modules.devicesIncluded', { count: included }) : t(`onboarding.modules.counterHelp.${unit}`)}
            />
          ))}
        </div>

        {grouped.map((group) => (
          <div key={group.key} className="flex flex-col gap-3">
            <h2 className="text-h2 text-ink">{t(`onboarding.moduleGroups.${group.key}`)}</h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {group.modules.map((module) => {
                const on = draft.modules.includes(module.key)
                return (
                  <button
                    key={module.key}
                    type="button"
                    aria-pressed={on}
                    onClick={() => toggle(module.key)}
                    className={cn(
                      'flex min-h-tile flex-col items-start gap-1 rounded-lg border p-4 text-left text-ink',
                      on ? 'border-primary bg-primary-tint' : 'border-border bg-surface-200 hover:bg-surface-300',
                    )}
                  >
                    <span className="flex w-full items-center justify-between gap-2">
                      <span className="text-body-lg font-medium">{module.name}</span>
                      <span
                        aria-hidden="true"
                        className={cn(
                          'flex size-5 shrink-0 items-center justify-center rounded-sm border',
                          on ? 'border-primary bg-primary text-on-primary' : 'border-border-strong bg-surface-200 text-transparent',
                        )}
                      >
                        <Icon name="check" size={12} />
                      </span>
                    </span>
                    <span className="text-caption text-ink-muted">{t(`onboarding.moduleDescriptions.${module.key}`)}</span>
                    <span className="text-caption text-ink tabular-nums">
                      {t(`onboarding.modules.per.${module.unit}`, { price: moneyText(module.amount, currency, locale) })}
                    </span>
                  </button>
                )
              })}
            </div>
          </div>
        ))}
      </section>

      <aside aria-label={t('onboarding.modules.priceTitle')} className="flex w-full flex-col gap-4 rounded-lg border border-border bg-surface-200 p-5 lg:sticky lg:top-6 lg:w-drawer">
        <h2 className="text-h3 text-ink">{draft.cycle === 'annual' ? t('onboarding.modules.priceTitleYear') : t('onboarding.modules.priceTitle')}</h2>
        <div role="group" aria-label={t('onboarding.modules.cycle')} className="flex gap-1 rounded-pill bg-surface-300 p-1">
          {['monthly', 'annual'].map((cycle) => (
            <button
              key={cycle}
              type="button"
              aria-pressed={draft.cycle === cycle}
              onClick={() => setDraft((current) => ({ ...current, cycle }))}
              className={cn(
                'h-control flex-1 rounded-pill text-label',
                draft.cycle === cycle ? 'bg-surface-200 text-ink shadow-sm' : 'text-ink-muted hover:text-ink',
              )}
            >
              {t(`onboarding.modules.cycles.${cycle}`)}
            </button>
          ))}
        </div>

        {refusal ? <Alert tone="warning" title={refusal.message} /> : null}
        {result && !result.quoted ? (
          <div className="flex flex-col gap-2" data-testid="quote-lines">
            {result.lines
              .filter((line) => line.amount > 0 || line.type === 'bundle')
              .map((line) => (
                <div key={`${line.type}:${line.key}`} className="flex items-baseline justify-between gap-3">
                  <span className="text-ink-muted">{name(line.key)}</span>
                  <Money amount={line.amount} currency={result.currency} />
                </div>
              ))}
          </div>
        ) : null}
        {draft.modules.length === 0 && !bundle ? <p className="text-ink-muted">{t('onboarding.modules.nothing')}</p> : null}

        {bundle ? (
          <div className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
            <span className="text-label text-ink">{t('onboarding.modules.bundleApplied', { name: bundle.name })}</span>
            <Button variant="ghost" onClick={() => setDraft((current) => ({ ...current, bundle: null }))}>
              {t('onboarding.modules.removeBundle')}
            </Button>
          </div>
        ) : suggestion ? (
          <div className="flex flex-col gap-2 rounded-md border border-primary bg-primary-tint p-3">
            <p className="text-body text-ink">
              {t('onboarding.modules.suggestion', {
                name: name(suggestion.bundle),
                total: moneyText(suggestion.total, suggestion.currency ?? currency, locale),
                saving: moneyText(suggestion.saving, suggestion.currency ?? currency, locale),
              })}
            </p>
            <Button
              onClick={() =>
                setDraft((current) => ({
                  ...current,
                  bundle: suggestion.bundle,
                  modules: [...new Set([...current.modules, ...(offer.bundles.find((b) => b.key === suggestion.bundle)?.modules ?? [])])],
                }))
              }
            >
              {t('onboarding.modules.useBundle', { name: name(suggestion.bundle) })}
            </Button>
          </div>
        ) : null}

        <div className="flex items-baseline justify-between gap-3 border-t border-border pt-3">
          <span className="text-body-lg font-medium text-ink">{draft.cycle === 'annual' ? t('onboarding.modules.perYear') : t('onboarding.modules.perMonth')}</span>
          {result && !result.quoted ? <Money amount={result.total} currency={result.currency} size="lg" /> : <span className="text-ink-muted">–</span>}
        </div>
        <p className="text-caption text-ink-muted">{t('onboarding.modules.terms')}</p>
        {startErrors.form ? <Alert tone="danger" title={startErrors.form} /> : null}
        <Button variant="primary" size="lg" block disabled={draft.modules.length === 0 && !bundle} loading={start.isPending} onClick={() => start.mutate()}>
          {t('onboarding.modules.startTrial')}
        </Button>
        <Link to="/onboarding/business" className="text-center text-label text-primary hover:text-primary-hover">
          {t('onboarding.back')}
        </Link>
      </aside>
    </div>
  )
}
