import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Card, DataTable, formatAmount, Money, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useLocale } from '@/lib/useLocale'
import { usePlatformAuth } from '../usePlatformAuth'
import { useCatalogueName } from '../useCatalogueName'
import { platformApi } from '../platformClient'
import { CouponDialog } from './CouponDialog'
import { PriceDialog } from './PriceDialog'
import { QuotePreview } from './QuotePreview'

const COUNTRIES = ['KE', 'CD']

const STATUS_TONES = { active: 'success', in_effect: 'success', scheduled: 'info', expired: 'neutral', used_up: 'neutral', archived: 'neutral' }

function PriceCell({ prices, country, extra }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const current = prices?.[country]?.current
  const next = prices?.[country]?.next
  const money = (amount) => `${current.currency} ${formatAmount(amount, current.currency, locale)}`
  if (!current && !next) return <span className="text-ink-muted">{t('platform.catalogue.notSold')}</span>
  return (
    <div className="flex flex-col items-end gap-1">
      {current ? <Money amount={current.amount} currency={current.currency} /> : <span className="text-ink-muted">{t('platform.catalogue.notSold')}</span>}
      {current?.minimum_amount ? <span className="text-caption text-ink-muted">{t('platform.catalogue.minimum', { amount: money(current.minimum_amount) })}</span> : null}
      {extra && current?.extra_outlet_amount ? <span className="text-caption text-ink-muted">{t('platform.catalogue.extraOutlet', { amount: money(current.extra_outlet_amount) })}</span> : null}
      {next ? <span className="text-caption text-ink-muted">{t('platform.catalogue.nextPrice', { date: next.effective_from })}</span> : null}
    </div>
  )
}

function ModulesTab({ canManage, onAddPrice }) {
  const { t } = useTranslation()
  const name = useCatalogueName()
  const modules = useQuery({ queryKey: ['platform', 'catalogue', 'modules'], queryFn: () => platformApi.get('platform/catalogue/modules') })
  const annual = useQuery({ queryKey: ['platform', 'catalogue', 'annual'], queryFn: () => platformApi.get('platform/catalogue/annual-discounts') })
  const discount = annual.data?.data?.find((row) => row.status === 'in_effect')

  const columns = [
    {
      key: 'name',
      label: t('platform.catalogue.columns.module'),
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-ink">{name(row.name)}</span>
          <span className="font-mono text-caption text-ink-muted">{row.key}</span>
        </div>
      ),
    },
    { key: 'kind', label: t('platform.catalogue.columns.kind'), render: (row) => t(`platform.kinds.${row.kind}`) },
    {
      key: 'unit',
      label: t('platform.catalogue.columns.unit'),
      render: (row) => (
        <div className="flex flex-col">
          <span>{t(`platform.units.${row.unit}`)}</span>
          {Object.entries(row.includes ?? {}).map(([key, count]) => (
            <span key={key} className="text-caption text-ink-muted">
              {t('platform.catalogue.includes', { count })}
            </span>
          ))}
        </div>
      ),
    },
    ...COUNTRIES.map((country) => ({ key: country, align: 'end', label: t(`auth.countries.${country}`), render: (row) => <PriceCell prices={row.prices} country={country} /> })),
    { key: 'status', label: t('platform.catalogue.columns.status'), render: (row) => <StatusBadge tone={row.archived_at ? 'neutral' : 'success'}>{t(row.archived_at ? 'platform.status.archived' : 'platform.status.active')}</StatusBadge> },
    ...(canManage
      ? [{ key: 'actions', label: '', align: 'end', render: (row) => <Button variant="ghost" onClick={() => onAddPrice({ type: 'module', key: row.key })}>{t('platform.catalogue.newPrice')}</Button> }]
      : []),
  ]

  return (
    <div className="flex flex-col gap-4">
      {discount ? <p className="text-body text-ink-muted">{t('platform.catalogue.annual', { charged: discount.months_charged, months: discount.months_in_cycle })}</p> : null}
      {modules.isError ? <Alert tone="danger" title={modules.error.message} /> : null}
      <Card>
        <DataTable caption={t('platform.catalogue.tabs.modules')} columns={columns} rows={modules.data?.data ?? []} loading={modules.isPending} emptyText={t('platform.catalogue.empty')} />
      </Card>
    </div>
  )
}

function BundlesTab({ canManage, onAddPrice }) {
  const { t } = useTranslation()
  const name = useCatalogueName()
  const bundles = useQuery({ queryKey: ['platform', 'catalogue', 'bundles'], queryFn: () => platformApi.get('platform/catalogue/bundles') })
  const columns = [
    { key: 'name', label: t('platform.catalogue.columns.bundle'), render: (row) => <span className="text-ink">{name(row.name)}</span> },
    { key: 'modules', label: t('platform.catalogue.columns.includes'), render: (row) => <span className="font-mono text-caption whitespace-normal text-ink-muted">{row.modules.join(', ')}</span> },
    { key: 'addons', label: t('platform.catalogue.columns.addons'), render: (row) => <span className="font-mono text-caption text-ink-muted">{row.addons.join(', ') || '—'}</span> },
    ...COUNTRIES.map((country) => ({
      key: country,
      align: 'end',
      label: t(`auth.countries.${country}`),
      render: (row) => (row.quoted ? <span className="text-ink-muted">{t('platform.catalogue.quoted')}</span> : <PriceCell prices={row.prices} country={country} extra />),
    })),
    ...(canManage
      ? [{ key: 'actions', label: '', align: 'end', render: (row) => (row.quoted ? null : <Button variant="ghost" onClick={() => onAddPrice({ type: 'bundle', key: row.key })}>{t('platform.catalogue.newPrice')}</Button>) }]
      : []),
  ]
  return (
    <Card>
      <DataTable caption={t('platform.catalogue.tabs.bundles')} columns={columns} rows={bundles.data?.data ?? []} loading={bundles.isPending} emptyText={t('platform.catalogue.empty')} />
    </Card>
  )
}

function CouponsTab({ canManage }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const coupons = useQuery({ queryKey: ['platform', 'catalogue', 'coupons'], queryFn: () => platformApi.get('platform/catalogue/coupons') })
  const columns = [
    { key: 'code', label: t('platform.coupons.code'), render: (row) => <span className="font-mono text-ink">{row.code}</span> },
    {
      key: 'value',
      label: t('platform.coupons.value'),
      render: (row) => (row.kind === 'percent' ? t('platform.coupons.percentOff', { percent: row.percent }) : <Money amount={row.amount} currency={row.currency} />),
    },
    { key: 'applies', label: t('platform.coupons.appliesTo'), render: (row) => [...row.applies_to_modules, ...row.applies_to_bundles].join(', ') || t('platform.coupons.everything') },
    { key: 'expires', label: t('platform.coupons.expires'), render: (row) => (row.expires_at ? row.expires_at.slice(0, 10) : '—') },
    { key: 'redemptions', label: t('platform.coupons.redemptions'), align: 'end', render: (row) => (row.max_redemptions ? `${row.redemptions} / ${row.max_redemptions}` : row.redemptions) },
    { key: 'status', label: t('platform.catalogue.columns.status'), render: (row) => <StatusBadge tone={STATUS_TONES[row.status]}>{t(`platform.status.${row.status}`)}</StatusBadge> },
  ]
  return (
    <div className="flex flex-col gap-4">
      {canManage ? (
        <div className="flex justify-end">
          <Button variant="primary" icon="plus" onClick={() => setOpen(true)}>
            {t('platform.coupons.new')}
          </Button>
        </div>
      ) : null}
      <Card>
        <DataTable caption={t('platform.catalogue.tabs.coupons')} columns={columns} rows={coupons.data?.data ?? []} loading={coupons.isPending} emptyText={t('platform.coupons.empty')} />
      </Card>
      {open ? <CouponDialog open onClose={() => setOpen(false)} /> : null}
    </div>
  )
}

/** SUB-01: the plan catalogue for platform staff: modules and prices per country, bundles, coupons, and a quote preview. */
export default function CatalogueConsole() {
  const { t } = useTranslation()
  const { can } = usePlatformAuth()
  const [tab, setTab] = useState('modules')
  const [priceFor, setPriceFor] = useState(null)
  const canManage = can('catalogue.manage')

  return (
    <>
      <PageHeader title={t('platform.catalogue.title')} description={t('platform.catalogue.intro')} />
      <Tabs
        value={tab}
        onChange={setTab}
        items={['modules', 'bundles', 'coupons', 'quote'].map((value) => ({ value, label: t(`platform.catalogue.tabs.${value}`) }))}
      />
      {tab === 'modules' ? <ModulesTab canManage={canManage} onAddPrice={setPriceFor} /> : null}
      {tab === 'bundles' ? <BundlesTab canManage={canManage} onAddPrice={setPriceFor} /> : null}
      {tab === 'coupons' ? <CouponsTab canManage={canManage} /> : null}
      {tab === 'quote' ? <QuotePreview /> : null}
      {priceFor ? <PriceDialog key={`${priceFor.type}:${priceFor.key}`} item={priceFor} onClose={() => setPriceFor(null)} /> : null}
    </>
  )
}
