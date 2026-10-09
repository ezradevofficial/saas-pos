import { useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, DataTable, Icon, PosTile, StatusBadge } from '@/components/ds'
import { cn } from '@/lib/utils'
import { loadBrandFonts } from '@/theme/brandFonts'
import { previewVariables } from './theme'

/**
 * BR-02: the draft theme on real components (a sidebar, buttons, a card
 * with a table, a POS tile), in light or dark mode. The draft's values are
 * set as CSS variables on this box only (runtime tenant values, the one
 * place inline style is allowed), so the rest of the screen keeps the
 * published look until the theme is published.
 */
export function BrandPreview({ theme, mode, logo }) {
  const { t } = useTranslation()
  const style = useMemo(() => previewVariables(theme, mode), [theme, mode])
  // A font the draft picks is loaded for the preview too (BR-02).
  useEffect(() => {
    loadBrandFonts({ 'font-sans': style['--font-sans'], 'font-display': style['--font-display'] })
  }, [style])
  const rows = [
    { id: '1', number: 'INV-0042', customer: t('brand.preview.customerA'), status: 'paid' },
    { id: '2', number: 'INV-0043', customer: t('brand.preview.customerB'), status: 'pending' },
  ]
  const nav = [
    { icon: 'dashboard', label: t('brand.preview.navHome'), active: true },
    { icon: 'sales', label: t('brand.preview.navSales') },
    { icon: 'items', label: t('brand.preview.navItems') },
  ]

  return (
    <div
      data-testid="brand-preview"
      data-mode={mode}
      style={style}
      className="flex overflow-hidden rounded-lg border border-border bg-surface-100 font-sans text-body text-ink"
    >
      <div className="flex w-1/3 shrink-0 flex-col gap-3 bg-sidebar px-3 py-4">
        <div className="flex items-center gap-2 border-b border-sidebar-border px-2 pb-3">
          {logo ? (
            <img src={logo} alt="" className="max-h-10 max-w-full object-contain object-left" />
          ) : (
            <>
              <span aria-hidden="true" className="flex size-6 shrink-0 items-center justify-center rounded-sm bg-sidebar-ink-active">
                <span className="size-2 rounded-sm bg-sidebar" />
              </span>
              <span className="truncate font-medium text-sidebar-ink-active">{t('brand.preview.business')}</span>
            </>
          )}
        </div>
        <ul className="flex flex-col gap-px">
          {nav.map((item) => (
            <li
              key={item.label}
              className={cn(
                'flex h-nav items-center gap-2 rounded-md px-2 text-sidebar-ink',
                item.active && 'bg-sidebar-active font-medium text-sidebar-ink-active shadow-sm',
              )}
            >
              <Icon name={item.icon} />
              <span className="truncate">{item.label}</span>
            </li>
          ))}
        </ul>
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-4 p-4">
        <h3 className="font-display text-h2 text-ink">{t('brand.preview.heading')}</h3>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="primary">{t('brand.preview.primary')}</Button>
          <Button>{t('brand.preview.secondary')}</Button>
          <Button variant="pay">{t('brand.preview.decisive')}</Button>
          <span className="font-medium text-primary">{t('brand.preview.link')}</span>
        </div>
        <Card title={t('brand.preview.cardTitle')} subtitle={t('brand.preview.cardSubtitle')}>
          <div className="flex flex-col gap-3">
            <p className="rounded-md bg-primary-tint px-3 py-2 text-ink">{t('brand.preview.selected')}</p>
            <DataTable
              caption={t('brand.preview.cardTitle')}
              columns={[
                { key: 'number', label: t('brand.preview.number') },
                { key: 'customer', label: t('brand.preview.customer') },
                {
                  key: 'status',
                  label: t('brand.preview.status'),
                  render: (row) => <StatusBadge tone={row.status === 'paid' ? 'success' : 'warning'}>{t(`brand.preview.${row.status}`)}</StatusBadge>,
                },
              ]}
              rows={rows}
            />
          </div>
        </Card>
        <div className="grid grid-cols-2 gap-3">
          <PosTile name={t('brand.preview.tileA')} price={25000} currency="KES" stock={12} />
          <PosTile name={t('brand.preview.tileB')} price={8000} currency="KES" stock={40} />
        </div>
      </div>
    </div>
  )
}
