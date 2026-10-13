import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Icon, Select, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, CalendarDate, DetailState, ItemPicker, LowBadge, Qty } from './InventoryParts'
import { ITEM_CONFIGURE, inventoryKey, TRACKING } from './inventoryData'
import { MovementList } from './Movements'

const TABS = ['locations', 'batches', 'serials', 'movements', 'settings']
const MAX_ATTRIBUTES = 5

/** INV-01: tracking (none, batch, serial) and expiry; tracking is fixed once the item has movements. */
function TrackingCard({ detail, itemId, canConfigure }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [tracking, setTracking] = useState(detail.settings.tracking)
  const [trackExpiry, setTrackExpiry] = useState(detail.settings.track_expiry)
  const locked = detail.has_movements
  const save = useMutation({
    mutationFn: () => api.put(`inventory/items/${itemId}/settings`, { tracking, track_expiry: tracking === 'batch' ? trackExpiry : false }),
    onSuccess: async () => {
      toast.success(t('inventory.item.settings.saved'))
      await queryClient.invalidateQueries({ queryKey: inventoryKey })
    },
  })
  const changed = tracking !== detail.settings.tracking || (tracking === 'batch' && trackExpiry !== detail.settings.track_expiry)

  return (
    <Card title={t('inventory.item.settings.title')} subtitle={t('inventory.item.settings.help')}>
      <div className="flex flex-col gap-4">
        {locked ? <Alert tone="info" title={t('inventory.item.settings.locked')} /> : null}
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <Select
          label={t('inventory.item.settings.tracking')}
          options={TRACKING.map((value) => ({ value, label: t(`inventory.tracking.${value}`) }))}
          value={tracking}
          disabled={!canConfigure || locked}
          help={locked ? t('inventory.item.settings.lockedHelp') : t(`inventory.tracking.help.${tracking}`)}
          onChange={(event) => setTracking(event.target.value)}
          className="max-w-field"
        />
        {tracking === 'batch' ? (
          <Switch label={t('inventory.item.settings.trackExpiry')} checked={trackExpiry} disabled={!canConfigure} onChange={setTrackExpiry} />
        ) : null}
        {canConfigure ? (
          <div>
            <Button variant="primary" loading={save.isPending} disabled={!changed} onClick={() => save.mutate()}>
              {t('inventory.item.settings.save')}
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  )
}

/** INV-02: link this item as a variant of a parent, with up to five attributes, or unlink it. */
function VariantCard({ detail, itemId, canConfigure }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const link = detail.variant
  const [parent, setParent] = useState(link?.parent ?? null)
  const [rows, setRows] = useState(() => {
    const entries = Object.entries(link?.attributes ?? {}).map(([name, value]) => ({ name, value }))
    return entries.length ? entries : [{ name: '', value: '' }]
  })
  const save = useMutation({
    mutationFn: (body) => api.put(`inventory/items/${itemId}/variant`, body),
    onSuccess: async (_, body) => {
      toast.success(body.parent_item_id ? t('inventory.item.variant.saved') : t('inventory.item.variant.unlinked'))
      if (!body.parent_item_id) {
        setParent(null)
        setRows([{ name: '', value: '' }])
      }
      await queryClient.invalidateQueries({ queryKey: inventoryKey })
    },
  })
  const filled = rows.filter((row) => row.name.trim() && row.value.trim())
  const setRow = (index, change) => setRows((current) => current.map((row, at) => (at === index ? { ...row, ...change } : row)))
  const hasVariants = detail.variants.length > 0

  return (
    <Card title={t('inventory.item.variant.title')} subtitle={t('inventory.item.variant.help')}>
      <div className="flex flex-col gap-4">
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        {hasVariants ? <p className="text-body text-ink-muted">{t('inventory.item.variant.isParent')}</p> : null}
        {link && !canConfigure ? (
          <p className="text-body text-ink">
            {t('inventory.item.variant.of', { name: link.parent?.name ?? '' })}
            <span className="text-ink-muted">{` · ${Object.entries(link.attributes ?? {}).map(([name, value]) => `${name}: ${value}`).join(', ')}`}</span>
          </p>
        ) : null}
        {canConfigure && !hasVariants ? (
          <>
            <ItemPicker label={t('inventory.item.variant.parent')} value={parent} type="" exclude={[itemId]} onChange={setParent} className="max-w-field" />
            <fieldset className="flex flex-col gap-3">
              <legend className="mb-2 text-label text-ink">{t('inventory.item.variant.attributes')}</legend>
              {rows.map((row, index) => (
                <div key={index} className="flex flex-wrap items-end gap-3">
                  <TextField
                    label={t('inventory.item.variant.attributeName')}
                    value={row.name}
                    maxLength={60}
                    onChange={(event) => setRow(index, { name: event.target.value })}
                  />
                  <TextField
                    label={t('inventory.item.variant.attributeValue')}
                    value={row.value}
                    maxLength={60}
                    onChange={(event) => setRow(index, { value: event.target.value })}
                  />
                  {rows.length > 1 ? (
                    <Button
                      variant="ghost"
                      icon="remove"
                      aria-label={t('inventory.item.variant.removeAttribute', { number: index + 1 })}
                      onClick={() => setRows((current) => current.filter((_, at) => at !== index))}
                    />
                  ) : null}
                </div>
              ))}
              {rows.length < MAX_ATTRIBUTES ? (
                <div>
                  <Button variant="ghost" icon="plus" onClick={() => setRows((current) => [...current, { name: '', value: '' }])}>
                    {t('inventory.item.variant.addAttribute')}
                  </Button>
                </div>
              ) : null}
            </fieldset>
            <div className="flex flex-wrap gap-2">
              <Button
                variant="primary"
                loading={save.isPending && Boolean(save.variables?.parent_item_id)}
                disabled={!parent || filled.length === 0}
                onClick={() => save.mutate({ parent_item_id: parent.id, attributes: Object.fromEntries(filled.map((row) => [row.name.trim(), row.value.trim()])) })}
              >
                {t('inventory.item.variant.save')}
              </Button>
              {link ? (
                <Button variant="danger" loading={save.isPending && !save.variables?.parent_item_id} onClick={() => save.mutate({ parent_item_id: null, attributes: {} })}>
                  {t('inventory.item.variant.unlink')}
                </Button>
              ) : null}
            </div>
          </>
        ) : null}
        {!link && !hasVariants && !canConfigure ? <p className="text-body text-ink-muted">{t('inventory.item.variant.none')}</p> : null}
      </div>
    </Card>
  )
}

/**
 * INV-01, INV-02, INV-05 to INV-07, INV-11, INV-12: one item's stock where
 * the user sees it: by location (reorder level, low), batches with expiry
 * (expired marked), serials in stock, its ledger, and its tracking and
 * variant link (inventory.item.configure). Costs per company show only to
 * valuation holders (the API leaves them out otherwise, RBAC-05).
 */
export default function ItemStock() {
  const { t } = useTranslation()
  const { itemId } = useParams()
  const { can } = usePermissions()
  const [params, setParams] = useSearchParams()
  const tab = TABS.includes(params.get('tab')) ? params.get('tab') : 'locations'
  const setTab = (next) => {
    const copy = new URLSearchParams()
    if (next !== 'locations') copy.set('tab', next)
    setParams(copy)
  }
  const query = useQuery({ queryKey: [...inventoryKey, 'item', itemId], queryFn: () => api.get(`inventory/items/${itemId}`) })
  const detail = query.data?.data
  const back = <Back to="/inventory/stock">{t('inventory.stock.back')}</Back>

  if (!detail) return <DetailState query={query} back={back} notFound={t('inventory.item.notFound')} />

  const unit = detail.item.base_uom?.code
  const canConfigure = can(ITEM_CONFIGURE)

  const balanceColumns = [
    { key: 'location', label: t('inventory.columns.location'), render: (row) => row.location?.name },
    { key: 'qty', label: t('inventory.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} unit={unit} /> },
    { key: 'reorder_level', label: t('inventory.columns.reorderLevel'), align: 'end', render: (row) => <Qty value={row.reorder_level} /> },
    { key: 'low', label: t('inventory.columns.low'), render: (row) => <LowBadge low={row.low} /> },
  ]
  const batchColumns = [
    { key: 'batch_number', label: t('inventory.columns.batch'), render: (row) => <span className="font-medium text-ink">{row.batch_number}</span> },
    {
      key: 'expires_on',
      label: t('inventory.columns.expiresOn'),
      render: (row) => (
        <span className="flex flex-wrap items-center gap-2">
          <CalendarDate value={row.expires_on} />
          {row.expired ? <StatusBadge tone="danger">{t('inventory.item.expired')}</StatusBadge> : null}
        </span>
      ),
    },
    {
      key: 'balances',
      label: t('inventory.columns.qtyByLocation'),
      wrap: true,
      render: (row) => (
        <ul className="flex flex-col">
          {row.balances.map((balance) => (
            <li key={balance.location?.id}>
              {balance.location?.name} <Qty value={balance.qty} unit={unit} />
            </li>
          ))}
        </ul>
      ),
    },
  ]
  const serialColumns = [
    { key: 'serial_number', label: t('inventory.columns.serial'), render: (row) => <span className="font-mono text-caption">{row.serial_number}</span> },
    { key: 'location', label: t('inventory.columns.location'), render: (row) => row.location?.name ?? '' },
  ]
  const variantColumns = [
    {
      key: 'item',
      label: t('inventory.columns.item'),
      render: (row) => (
        <Link to={`/inventory/items/${row.item?.id}`} className="text-primary hover:text-primary-hover">
          {row.item?.name}
        </Link>
      ),
    },
    { key: 'attributes', label: t('inventory.columns.attributes'), wrap: true, render: (row) => Object.entries(row.attributes ?? {}).map(([name, value]) => `${name}: ${value}`).join(', ') },
    { key: 'qty', label: t('inventory.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} unit={unit} /> },
  ]
  const costColumns = [
    { key: 'company', label: t('inventory.columns.company'), render: (row) => row.company?.name },
    { key: 'qty', label: t('inventory.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} unit={unit} /> },
    { key: 'value', label: t('inventory.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('inventory.item.eyebrow')}
        title={detail.item.name}
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="font-mono text-caption">{detail.item.code}</span>
            <span>{t('inventory.item.onHand')}</span>
            <Qty value={detail.on_hand} unit={unit} className="text-ink" />
            <span>{t(`inventory.tracking.${detail.settings.tracking}`)}</span>
          </span>
        }
        actions={
          can('core.item.view') ? (
            <Link to={`/catalogue/items/${detail.item.id}`} className="flex items-center gap-2 text-label text-primary hover:text-primary-hover">
              <Icon name="items" />
              {t('inventory.item.openItem')}
            </Link>
          ) : null
        }
      />
      {detail.variant ? (
        <p className="text-body text-ink-muted">
          {`${t('inventory.item.variantOf')} `}
          <Link to={`/inventory/items/${detail.variant.parent?.id}`} className="text-primary hover:text-primary-hover">
            {detail.variant.parent?.name}
          </Link>
        </p>
      ) : null}
      <Tabs
        value={tab}
        onChange={setTab}
        items={TABS.map((value) => ({
          value,
          label: t(`inventory.item.tabs.${value}`),
          count: value === 'batches' ? detail.batches.length : value === 'serials' ? detail.serials.length : undefined,
        }))}
      />
      <div className="flex flex-col gap-5">
        {tab === 'locations' ? (
          <>
            <DataTable caption={t('inventory.item.tabs.locations')} columns={balanceColumns} rows={detail.balances.map((row) => ({ ...row, id: row.location?.id }))} emptyText={t('inventory.item.noStock')} />
            {detail.costs?.length ? (
              <Card title={t('inventory.item.costs')} subtitle={t('inventory.item.costsHelp')}>
                <DataTable caption={t('inventory.item.costs')} columns={costColumns} rows={detail.costs.map((row) => ({ ...row, id: row.company?.id }))} />
              </Card>
            ) : null}
            {detail.variants.length ? (
              <Card title={t('inventory.item.variants')}>
                <DataTable caption={t('inventory.item.variants')} columns={variantColumns} rows={detail.variants.map((row) => ({ ...row, id: row.item?.id }))} />
              </Card>
            ) : null}
          </>
        ) : null}
        {tab === 'batches' ? (
          <DataTable caption={t('inventory.item.tabs.batches')} columns={batchColumns} rows={detail.batches} emptyText={t('inventory.item.noBatches')} />
        ) : null}
        {tab === 'serials' ? (
          <>
            <DataTable caption={t('inventory.item.tabs.serials')} columns={serialColumns} rows={detail.serials} emptyText={t('inventory.item.noSerials')} />
            {detail.serials.length >= 500 ? <p className="text-caption text-ink-muted">{t('inventory.item.serialsCapped')}</p> : null}
          </>
        ) : null}
        {tab === 'movements' ? <MovementList endpoint={`inventory/items/${itemId}/movements`} withItem={false} /> : null}
        {tab === 'settings' ? (
          <>
            <TrackingCard key={`${detail.settings.tracking}-${detail.settings.track_expiry}`} detail={detail} itemId={itemId} canConfigure={canConfigure} />
            <VariantCard key={detail.variant?.parent?.id ?? 'none'} detail={detail} itemId={itemId} canConfigure={canConfigure} />
          </>
        ) : null}
      </div>
    </>
  )
}
