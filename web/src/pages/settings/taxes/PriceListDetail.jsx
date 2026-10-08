import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { HistoryPanel } from '@/components/HistoryPanel'
import { Alert, Button, Card, Icon, ListView, Money, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { actionsColumn } from '@/lib/listColumns'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { PriceDialog } from '@/pages/catalogue/PriceDialog'
import { useUoms } from '@/pages/catalogue/catalogueData'
import { ConfirmDialog } from '../ConfirmDialog'

const PRICE_VIEW = ['core.price.view', 'core.price.edit']
const STATE_TONES = { current: 'success', scheduled: 'info', replaced: 'neutral' }
const pricesKey = (id) => ['item-prices', id]

/**
 * MD-03 follow-up: one price list's prices (item, unit, price, start date,
 * quantity break, in force or scheduled), with search, sort, the state
 * filter, columns and export (EXP-01, LAY-04); Add price and Edit for
 * users who may change prices (`core.price.edit` at the list's company),
 * Archive; and the list's History (MD-07), price changes included.
 */
export default function PriceListDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { priceListId } = useParams()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const { can } = usePermissions()
  const [editing, setEditing] = useState(null) // { price? }
  const [archiving, setArchiving] = useState(null)
  const query = useQuery({ queryKey: ['price-lists', 'detail', priceListId], queryFn: () => api.get(`price-lists/${priceListId}`) })
  const list = query.data?.data
  const timeZone = useTimeZone(list?.company_id)
  const uoms = useUoms()
  const tab = params.get('tab') === 'history' ? 'history' : 'prices'
  const scope = list ? { type: 'company', id: list.company_id } : undefined
  const canRead = can(PRICE_VIEW, scope)
  const canEdit = Boolean(list) && can('core.price.edit', scope) && !list.archived_at

  const archive = useMutation({
    mutationFn: (price) => api.post(`item-prices/${price.id}/archive`),
    onSuccess: async () => {
      setArchiving(null)
      await queryClient.invalidateQueries({ queryKey: pricesKey(priceListId) })
      queryClient.invalidateQueries({ queryKey: ['history', 'price_list', priceListId] })
    },
  })

  const columns = [
    {
      key: 'item',
      label: t('prices.columns.item'),
      sortKey: 'item_code',
      exportKey: 'item_code',
      hideable: false,
      render: (row) => (
        <span className="flex flex-col">
          <span className="font-mono text-caption text-ink">{row.item_code}</span>
          <span className="whitespace-normal text-ink">{row.item_name}</span>
        </span>
      ),
    },
    { key: 'unit', label: t('prices.columns.unit'), sortKey: 'unit', render: (row) => row.uom_code },
    {
      key: 'amount',
      label: t('prices.columns.amount'),
      sortKey: 'amount',
      align: 'end',
      numeric: true,
      render: (row) => ('amount_minor' in row ? <Money amount={row.amount_minor} currency={row.currency} /> : <span className="text-ink-muted">{t('prices.hidden')}</span>),
    },
    { key: 'effective_from', label: t('prices.columns.from'), sortKey: 'effective_from', render: (row) => formatCalendarDate(row.effective_from, locale) },
    {
      key: 'min_quantity',
      label: t('prices.columns.minQuantity'),
      sortKey: 'min_quantity',
      numeric: true,
      defaultHidden: true,
      render: (row) => formatDecimal(row.min_quantity, locale),
    },
    {
      key: 'state',
      label: t('prices.columns.state'),
      render: (row) => (row.state ? <StatusBadge tone={STATE_TONES[row.state]}>{t(`prices.states.${row.state}`)}</StatusBadge> : null),
    },
    actionsColumn(t('prices.columns.actions'), (row) =>
      canEdit && !row.archived_at ? (
        <div className="flex justify-end gap-1">
          <Button variant="ghost" onClick={() => setEditing({ price: row })} aria-label={t('prices.editFor', { item: row.item_code, unit: row.uom_code })}>
            {t('prices.edit')}
          </Button>
          <Button variant="ghost" icon="archive" onClick={() => setArchiving(row)} aria-label={t('prices.archiveFor', { item: row.item_code, unit: row.uom_code })} />
        </div>
      ) : null,
    ),
  ]
  const prices = useServerList({
    id: 'item-prices',
    endpoint: `price-lists/${priceListId}/prices`,
    queryKey: pricesKey(priceListId),
    filters: { state: '' },
    columns,
  })
  const today = prices.query.data?.meta?.today

  const saved = async () => {
    setEditing(null)
    await queryClient.invalidateQueries({ queryKey: pricesKey(priceListId) })
    queryClient.invalidateQueries({ queryKey: ['history', 'price_list', priceListId] })
  }

  const back = (
    <Link to="/settings/taxes?tab=priceLists" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {t('prices.back')}
    </Link>
  )
  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) {
    return (
      <>
        {back}
        <Alert tone="danger" title={query.error.status === 404 ? t('prices.notFound') : errorMessage(query.error)} />
      </>
    )
  }

  const uomCode = (id) => uoms.all.find((uom) => uom.id === id)?.code ?? t('history.unknown')
  const historyFields = {
    price_list_id: { hidden: true },
    item_id: { format: (value) => prices.rows.find((row) => row.item_id === value)?.item_code ?? t('prices.anItem') },
    uom_id: { format: (value) => (value ? uomCode(value) : t('history.none')) },
  }
  const archived = Boolean(list.archived_at)

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('prices.eyebrow')}
        title={list.name}
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>{list.currency}</span>
            <span>{list.tax_inclusive ? t('taxes.priceLists.includeTax') : t('taxes.priceLists.excludeTax')}</span>
            {list.is_default ? <StatusBadge tone="info">{t('taxes.priceLists.defaultFor', { currency: list.currency })}</StatusBadge> : null}
            {archived ? <StatusBadge tone="neutral">{t('prices.listArchived')}</StatusBadge> : null}
          </span>
        }
        actions={
          canEdit && tab === 'prices' ? (
            <Button icon="plus" onClick={() => setEditing({})}>
              {t('prices.add')}
            </Button>
          ) : null
        }
      />
      <Tabs
        items={[
          { value: 'prices', label: t('prices.tabs.prices') },
          { value: 'history', label: t('prices.tabs.history') },
        ]}
        value={tab}
        onChange={(next) => setParams(next === 'history' ? { tab: 'history' } : {}, { replace: true })}
      />
      {tab === 'prices' ? (
        canRead ? (
          <div className="flex flex-col gap-4">
            <p className="text-ink-muted">{t('prices.intro', { currency: list.currency })}</p>
            <ListView
              list={prices}
              title={t('prices.title', { list: list.name })}
              searchPlaceholder={t('prices.searchPlaceholder')}
              filterFields={[
                {
                  name: 'state',
                  label: t('prices.columns.state'),
                  options: [
                    { value: '', label: t('prices.states.all') },
                    { value: 'current', label: t('prices.states.current') },
                    { value: 'scheduled', label: t('prices.states.scheduled') },
                    { value: 'replaced', label: t('prices.states.replaced') },
                  ],
                },
              ]}
              emptyText={prices.term ? t('prices.emptyFiltered') : canEdit ? t('prices.emptyEdit') : t('prices.empty')}
            />
          </div>
        ) : (
          <Alert tone="info" title={t('prices.noAccess')} />
        )
      ) : (
        <Card>
          <HistoryPanel type="price_list" recordId={list.id} timeZone={timeZone} fields={historyFields} />
        </Card>
      )}
      {editing ? (
        <PriceDialog
          priceList={list}
          item={editing.price ? { id: editing.price.item_id, code: editing.price.item_code, name: editing.price.item_name } : null}
          price={editing.price ?? null}
          today={today}
          onClose={() => setEditing(null)}
          onSaved={saved}
        />
      ) : null}
      <ConfirmDialog
        open={Boolean(archiving)}
        title={archiving ? t('prices.archiveTitle', { item: archiving.item_code, unit: archiving.uom_code }) : ''}
        confirmLabel={t('prices.archiveConfirm')}
        cancelLabel={t('prices.keep')}
        pending={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        failure={archive.error}
        onConfirm={() => archive.mutate(archiving)}
        onClose={() => {
          setArchiving(null)
          archive.reset()
        }}
      >
        {t('prices.archiveText')}
      </ConfirmDialog>
    </>
  )
}
