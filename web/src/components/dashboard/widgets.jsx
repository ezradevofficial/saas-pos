import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Chart } from '@/components/Chart'
import { Icon, StatusBadge } from '@/components/ds'
import { useNavigation } from '@/layouts/useNavigation'
import { useWidgetData, WIDGET_TYPES, widgetTitle } from '@/lib/dashboardData'
import { formatCalendarDate, formatDate } from '@/lib/dates'
import { formatInteger, formatMoney } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'

const money = (minor, currency, locale) => formatMoney(String(minor), currency, locale)

function Kpi({ data, t, locale }) {
  const value = data.kind === 'money' ? (data.value == null || !data.currency ? null : money(data.value, data.currency, locale)) : formatInteger(data.value ?? 0, locale)
  return (
    <div className="flex flex-col gap-1">
      <span className="text-amount-lg tabular-nums text-ink">{value ?? t('layouts.widgets.noFigure')}</span>
      {data.count != null ? <span className="text-caption text-ink-muted">{t('layouts.widgets.sales', { count: data.count, formatted: formatInteger(data.count, locale) })}</span> : null}
      {data.kind === 'money' && data.complete === false && data.value != null ? <span className="text-caption text-ink-muted">{t('layouts.widgets.incomplete')}</span> : null}
    </div>
  )
}

function Count({ data, t, locale }) {
  return (
    <div className="flex items-end justify-between gap-3">
      <span className="text-amount-lg tabular-nums text-ink">{formatInteger(data.value ?? 0, locale)}</span>
      {data.to ? (
        <Link to={data.to} className="text-body text-primary hover:underline">
          {t('layouts.widgets.open')}
        </Link>
      ) : null}
    </div>
  )
}

function Rows({ data, t, locale }) {
  if (!data.rows?.length) return <p className="text-body text-ink-muted">{t('layouts.widgets.listEmpty')}</p>
  return (
    <ul className="flex min-h-0 flex-col divide-y divide-border overflow-y-auto">
      {data.rows.map((row) => (
        <li key={row.id}>
          <Link to={row.to} className="flex items-center justify-between gap-3 py-2 hover:bg-surface-100">
            <span className="flex min-w-0 flex-col">
              <span className="truncate text-body text-ink">{row.title}</span>
              {row.subtitle ? <span className="truncate text-caption text-ink-muted">{row.subtitle}</span> : null}
            </span>
            {row.overdue ? <StatusBadge tone="danger">{t('layouts.widgets.overdue')}</StatusBadge> : row.at ? <span className="shrink-0 text-caption text-ink-muted">{formatDate(row.at, locale)}</span> : null}
          </Link>
        </li>
      ))}
      {data.total > data.rows.length && data.to ? (
        <li className="pt-2">
          <Link to={data.to} className="text-body text-primary hover:underline">
            {t('layouts.widgets.seeAll', { count: data.total, formatted: formatInteger(data.total, locale) })}
          </Link>
        </li>
      ) : null}
    </ul>
  )
}

function Shortcuts({ data, t }) {
  const { groups } = useNavigation()
  const items = new Map(groups.flatMap((group) => group.items.map((item) => [item.to, item])))
  // RBAC-09: only pages of the reader's own menu; a shortcut never grants one.
  const links = (data.links ?? []).map((to) => items.get(to)).filter(Boolean)
  if (!links.length) return <p className="text-body text-ink-muted">{t('layouts.widgets.noShortcuts')}</p>
  return (
    <ul className="flex flex-col gap-2">
      {links.map((item) => (
        <li key={item.to}>
          <Link to={item.to} className="flex items-center gap-3 rounded-md border border-border px-4 py-3 transition-colors hover:border-border-strong hover:bg-surface-100">
            <Icon name={item.icon} size={18} className="text-ink-muted" />
            <span className="font-medium text-primary">{item.label(t)}</span>
          </Link>
        </li>
      ))}
    </ul>
  )
}

function SalesChart({ widget, data, t, locale, title }) {
  const currency = data.currency
  const points = (data.points ?? []).map((point) => ({ label: point.label, value: point.value == null ? null : Number(point.value) }))
  return (
    <Chart
      type={widget.chart === 'line' ? 'line' : 'bar'}
      points={points}
      title={title}
      valueLabel={currency ? t('layouts.chart.amountIn', { currency }) : title}
      formatValue={(value) => (currency ? money(Math.round(value), currency, locale) : formatInteger(Math.round(value), locale))}
      formatLabel={(label) => formatCalendarDate(label, locale)}
    />
  )
}

/**
 * One dashboard widget (LAY-01): its title and its source's data, drawn by
 * type. An unknown type is skipped (LAY-07); a source that fails says so
 * quietly instead of breaking the dashboard.
 */
export function Widget({ widget, framed = true }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const known = WIDGET_TYPES.includes(widget.type)
  const query = useWidgetData(widget, { enabled: known })
  if (!known) return null
  const title = widgetTitle(widget, t)
  const data = query.data

  let body
  if (query.isPending) body = <p className="text-body text-ink-muted">{t('common.loading')}</p>
  else if (query.isError || !data) body = <p className="text-body text-ink-muted">{t('layouts.widgets.unavailable')}</p>
  else if (widget.type === 'kpi') body = <Kpi data={data} t={t} locale={locale} />
  else if (widget.type === 'approval_count') body = <Count data={data} t={t} locale={locale} />
  else if (widget.type === 'list') body = <Rows data={data} t={t} locale={locale} />
  else if (widget.type === 'shortcut') body = <Shortcuts data={data} t={t} />
  else body = <SalesChart widget={widget} data={data} t={t} locale={locale} title={title} />

  return (
    <section
      aria-label={title}
      data-widget-type={widget.type}
      className={cn('flex h-full min-h-0 flex-col gap-3', framed && 'rounded-lg border border-border bg-surface-200 px-5 py-4')}
    >
      <h2 className="text-body text-ink-muted">{title}</h2>
      <div className="min-h-0 flex-1">{body}</div>
    </section>
  )
}
