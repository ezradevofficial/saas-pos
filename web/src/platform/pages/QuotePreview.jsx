import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, formatAmount, Money, MultiSelect, Select, TextField } from '@/components/ds'
import { useLocale } from '@/lib/useLocale'
import { platformApi } from '../platformClient'

const UNITS = ['outlet', 'device', 'employee', 'company', 'online_store']

/**
 * ONB-03: the live quote the onboarding picker will show, over today's
 * catalogue (POST catalogue/quote), for staff to check prices and coupons.
 */
export function QuotePreview() {
  const { t } = useTranslation()
  const locale = useLocale()
  const [values, setValues] = useState({ country: 'KE', cycle: 'monthly', modules: ['pos', 'inventory', 'loyalty'], bundle: '', coupon: '', quantities: { outlet: '1', device: '2', employee: '1', company: '1', online_store: '1' } })
  const catalogue = useQuery({ queryKey: ['catalogue', values.country], queryFn: () => platformApi.get(`catalogue?country=${values.country}`) })
  const data = catalogue.data?.data

  const quote = useMutation({
    mutationFn: () =>
      platformApi.post('catalogue/quote', {
        country: values.country,
        cycle: values.cycle,
        modules: values.modules,
        bundle: values.bundle || null,
        coupon: values.coupon.trim() || null,
        quantities: Object.fromEntries(Object.entries(values.quantities).map(([unit, value]) => [unit, Number(value || 0)])),
      }),
  })

  const errors = formErrors(quote.error, ['country', 'cycle', 'modules', 'bundle', 'coupon'])
  const set = (field) => (value) => setValues((current) => ({ ...current, [field]: value }))
  const result = quote.data?.data
  const label = (key) => data?.modules.find((m) => m.key === key)?.name ?? data?.bundles.find((b) => b.key === key)?.name ?? key

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card title={t('platform.quote.title')} subtitle={t('platform.quote.intro')}>
        <form
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            quote.mutate()
          }}
        >
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <Select label={t('platform.fields.country')} value={values.country} onChange={(event) => set('country')(event.target.value)} options={['KE', 'CD'].map((value) => ({ value, label: t(`auth.countries.${value}`) }))} />
            <Select label={t('platform.quote.cycle')} value={values.cycle} onChange={(event) => set('cycle')(event.target.value)} options={['monthly', 'annual'].map((value) => ({ value, label: t(`platform.quote.cycles.${value}`) }))} />
          </div>
          <MultiSelect
            label={t('platform.quote.modules')}
            value={values.modules}
            onChange={set('modules')}
            options={(data?.modules ?? []).filter((m) => m.kind !== 'extra').map((m) => ({ value: m.key, label: m.name }))}
            error={errors.fields.modules}
          />
          <Select
            label={t('platform.quote.bundle')}
            value={values.bundle}
            onChange={(event) => set('bundle')(event.target.value)}
            options={[{ value: '', label: t('platform.quote.noBundle') }, ...(data?.bundles ?? []).map((b) => ({ value: b.key, label: b.name }))]}
            error={errors.fields.bundle}
          />
          <div className="grid gap-4 sm:grid-cols-3">
            {UNITS.map((unit) => (
              <TextField
                key={unit}
                label={t(`platform.quote.quantities.${unit}`)}
                type="number"
                inputMode="numeric"
                min={unit === 'device' ? 0 : 1}
                value={values.quantities[unit]}
                onChange={(event) => setValues((current) => ({ ...current, quantities: { ...current.quantities, [unit]: event.target.value } }))}
                error={formErrors(quote.error, [`quantities.${unit}`]).fields[`quantities.${unit}`]}
              />
            ))}
          </div>
          <TextField label={t('platform.quote.coupon')} value={values.coupon} onChange={(event) => set('coupon')(event.target.value)} error={errors.fields.coupon} />
          <div className="flex justify-end">
            <Button type="submit" variant="primary" loading={quote.isPending}>
              {t('platform.quote.submit')}
            </Button>
          </div>
        </form>
      </Card>

      <Card title={t('platform.quote.result')}>
        {!result ? (
          <p className="text-body text-ink-muted">{t('platform.quote.empty')}</p>
        ) : result.quoted ? (
          <Alert tone="info" title={t('platform.quote.quoted')} />
        ) : (
          <div className="flex flex-col gap-4" data-testid="quote-result">
            <DataTable
              caption={t('platform.quote.result')}
              columns={[
                { key: 'key', label: t('platform.quote.line'), render: (line) => <span className="whitespace-normal">{label(line.key)}</span> },
                {
                  key: 'quantity',
                  label: t('platform.quote.quantity'),
                  align: 'end',
                  render: (line) => (line.included ? t('platform.quote.withIncluded', { quantity: line.quantity, included: line.included }) : line.quantity),
                },
                {
                  key: 'amount',
                  label: t('platform.quote.monthly'),
                  align: 'end',
                  render: (line) => (
                    <div className="flex flex-col items-end">
                      <Money amount={line.amount} currency={result.currency} />
                      {line.minimum_applied ? <span className="text-caption text-ink-muted">{t('platform.quote.minimumApplied')}</span> : null}
                    </div>
                  ),
                },
              ]}
              rows={result.lines.map((line) => ({ ...line, id: `${line.type}:${line.key}` }))}
            />
            <dl className="flex flex-col gap-2">
              {[
                ['subtotal', result.subtotal],
                ...(result.annual_discount ? [['annualDiscount', -result.annual_discount]] : []),
                ...(result.coupon_discount ? [['couponDiscount', -result.coupon_discount]] : []),
              ].map(([key, amount]) => (
                <div key={key} className="flex items-center justify-between">
                  <dt className="text-ink-muted">{t(`platform.quote.${key}`)}</dt>
                  <dd>
                    <Money amount={amount} currency={result.currency} />
                  </dd>
                </div>
              ))}
              <div className="flex items-center justify-between border-t border-border pt-2">
                <dt className="text-label text-ink">{t(`platform.quote.total.${result.cycle}`)}</dt>
                <dd>
                  <Money amount={result.total} currency={result.currency} size="lg" />
                </dd>
              </div>
            </dl>
            {result.coupon && !result.coupon.applied ? <Alert tone="warning" title={t(`platform.quote.couponNotApplied.${result.coupon.reason}`)} /> : null}
            {result.suggestion ? (
              <Alert
                tone="info"
                title={t('platform.quote.suggestion', {
                  bundle: label(result.suggestion.bundle),
                  saving: `${result.suggestion.currency} ${formatAmount(result.suggestion.saving, result.suggestion.currency, locale)}`,
                })}
              />
            ) : null}
          </div>
        )}
      </Card>
    </div>
  )
}
