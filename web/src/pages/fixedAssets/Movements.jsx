import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { Amount, Back, CalendarDate, Detail, DetailState, DocumentFlow, FaStatus, ReasonDialog } from './FixedAssetsParts'
import { faKey, statusLabel } from './fixedAssetsData'

const statusFilter = (t, statuses) => ({
  name: 'status',
  label: t('fixedAssets.filters.status'),
  options: [{ value: '', label: t('fixedAssets.filters.allStatuses') }, ...statuses.map((value) => ({ value, label: statusLabel(t, value) }))],
})

const AssetCell = ({ asset }) => (
  <span className="flex flex-col">
    <span className="text-ink">{asset?.name}</span>
    <span className="text-caption text-ink-muted tabular-nums">{asset?.number}</span>
  </span>
)

/** FA-14: transfers of the assets the user sees, newest first. */
export function Transfers() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const columns = [
    { key: 'number', label: t('fixedAssets.columns.number'), hideable: false, render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'asset', label: t('fixedAssets.columns.asset'), render: (row) => <AssetCell asset={row.asset} /> },
    { key: 'status', label: t('fixedAssets.columns.status'), render: (row) => <FaStatus status={row.status} /> },
    { key: 'from', label: t('fixedAssets.transfers.from'), render: (row) => row.from_location?.name },
    { key: 'to', label: t('fixedAssets.transfers.to'), render: (row) => row.to_location?.name },
    { key: 'transfer_date', label: t('fixedAssets.transfers.date'), render: (row) => <CalendarDate value={row.transfer_date} /> },
  ]
  const list = useServerList({ id: 'fixed-assets-transfers', endpoint: 'fixed-assets/transfers', queryKey: [...faKey, 'transfers'], filters: { status: '', from: '', to: '' }, columns })
  return (
    <>
      <PageHeader title={t('fixedAssets.transfers.title')} description={t('fixedAssets.transfers.description')} />
      <ListView
        list={list}
        title={t('fixedAssets.transfers.title')}
        searchLabel={t('fixedAssets.transfers.search')}
        filterFields={[statusFilter(t, ['pending', 'applied', 'rejected', 'cancelled']), dateField('from', t('fixedAssets.filters.from')), dateField('to', t('fixedAssets.filters.to'))]}
        emptyText={t('fixedAssets.transfers.empty')}
        onRowClick={(row) => navigate(`/fixed-assets/transfers/${row.id}`)}
      />
    </>
  )
}

/** FA-14: one transfer, its flow and its cancellation. */
export function TransferDetail() {
  const { t } = useTranslation()
  const { transferId } = useParams()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const query = useQuery({ queryKey: [...faKey, 'transfer', transferId], queryFn: () => api.get(`fixed-assets/transfers/${transferId}`) })
  const back = <Back to="/fixed-assets/transfers">{t('fixedAssets.transfers.back')}</Back>
  const transfer = query.data?.data
  if (!transfer) return <DetailState query={query} back={back} notFound={t('fixedAssets.transfers.notFound')} />
  const pair = (from, to) => (from?.id === to?.id ? (from?.name ?? '—') : `${from?.name ?? '—'} → ${to?.name ?? '—'}`)

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('fixedAssets.transfers.eyebrow')}
        title={transfer.number}
        description={<FaStatus status={transfer.status} />}
        actions={transfer.can?.cancel ? <Button variant="ghost" onClick={() => setCancelling(true)}>{t('fixedAssets.transfers.cancel')}</Button> : null}
      />
      <div className="flex flex-col gap-5">
        <Card title={t('fixedAssets.detail.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('fixedAssets.columns.asset')}>
              <Link to={`/fixed-assets/assets/${transfer.asset?.id}`} className="text-primary hover:text-primary-hover">
                {transfer.asset?.number} · {transfer.asset?.name}
              </Link>
            </Detail>
            <Detail label={t('fixedAssets.fields.location')}>{pair(transfer.from_location, transfer.to_location)}</Detail>
            <Detail label={t('fixedAssets.fields.custodian')}>{pair(transfer.from_custodian, transfer.to_custodian)}</Detail>
            <Detail label={t('fixedAssets.fields.costCentre')}>{pair(transfer.from_cost_centre, transfer.to_cost_centre)}</Detail>
            <Detail label={t('fixedAssets.transfers.date')}>
              <CalendarDate value={transfer.transfer_date} />
            </Detail>
            <Detail label={t('fixedAssets.fields.reason')}>{transfer.reason}</Detail>
            <Detail label={t('fixedAssets.transfers.requestedBy')}>{transfer.requested_by?.name}</Detail>
          </dl>
        </Card>
        {transfer.status === 'pending' ? <DocumentFlow type="fixed_assets.transfer" id={transfer.id} /> : null}
      </div>
      <ReasonDialog
        open={cancelling}
        title={t('fixedAssets.transfers.cancelTitle', { number: transfer.number })}
        confirm={t('fixedAssets.transfers.cancel')}
        path={`fixed-assets/transfers/${transfer.id}/cancel`}
        onClose={() => setCancelling(false)}
        onDone={async () => {
          setCancelling(false)
          await queryClient.invalidateQueries({ queryKey: faKey })
        }}
      />
    </>
  )
}

/** FA-17: disposals of the assets the user sees, newest first. */
export function Disposals() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const columns = [
    { key: 'number', label: t('fixedAssets.columns.number'), hideable: false, render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'asset', label: t('fixedAssets.columns.asset'), render: (row) => <AssetCell asset={row.asset} /> },
    { key: 'status', label: t('fixedAssets.columns.status'), render: (row) => <FaStatus status={row.status} /> },
    { key: 'kind', label: t('fixedAssets.disposals.kind'), render: (row) => t(`fixedAssets.disposals.kinds.${row.kind}`) },
    { key: 'disposal_date', label: t('fixedAssets.disposals.date'), render: (row) => <CalendarDate value={row.disposal_date} /> },
    { key: 'gain', label: t('fixedAssets.disposals.gain'), align: 'end', render: (row) => <Amount value={row.gain} /> },
  ]
  const list = useServerList({ id: 'fixed-assets-disposals', endpoint: 'fixed-assets/disposals', queryKey: [...faKey, 'disposals'], filters: { status: '', from: '', to: '' }, columns })
  return (
    <>
      <PageHeader title={t('fixedAssets.disposals.title')} description={t('fixedAssets.disposals.description')} />
      <ListView
        list={list}
        title={t('fixedAssets.disposals.title')}
        searchLabel={t('fixedAssets.disposals.search')}
        filterFields={[statusFilter(t, ['pending', 'approved', 'posted', 'rejected', 'cancelled']), dateField('from', t('fixedAssets.filters.from')), dateField('to', t('fixedAssets.filters.to'))]}
        emptyText={t('fixedAssets.disposals.empty')}
        onRowClick={(row) => navigate(`/fixed-assets/disposals/${row.id}`)}
      />
    </>
  )
}

/** FA-17: one disposal: what it posted (or why it could not), its flow, cancellation and posting again. */
export function DisposalDetail() {
  const { t } = useTranslation()
  const { disposalId } = useParams()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const key = [...faKey, 'disposal', disposalId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`fixed-assets/disposals/${disposalId}`) })
  const post = useMutation({
    mutationFn: () => api.post(`fixed-assets/disposals/${disposalId}/post`),
    onSuccess: async () => {
      toast.success(t('fixedAssets.disposals.postedToast'))
      await queryClient.invalidateQueries({ queryKey: faKey })
    },
  })
  const back = <Back to="/fixed-assets/disposals">{t('fixedAssets.disposals.back')}</Back>
  const disposal = query.data?.data
  if (!disposal) return <DetailState query={query} back={back} notFound={t('fixedAssets.disposals.notFound')} />

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('fixedAssets.disposals.eyebrow')}
        title={disposal.number}
        description={<FaStatus status={disposal.status} />}
        actions={
          <div className="flex flex-wrap gap-2">
            {disposal.can?.cancel ? <Button variant="ghost" onClick={() => setCancelling(true)}>{t('fixedAssets.disposals.cancel')}</Button> : null}
            {disposal.can?.post ? (
              <Button variant="pay" loading={post.isPending} onClick={() => post.mutate()}>
                {t('fixedAssets.disposals.post')}
              </Button>
            ) : null}
          </div>
        }
      />
      <div className="flex flex-col gap-5">
        {disposal.post_error_message ? <Alert tone="warning" title={disposal.post_error_message} /> : null}
        {post.isError ? <Alert tone="danger" title={errorMessage(post.error)} /> : null}
        <Card title={t('fixedAssets.detail.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('fixedAssets.columns.asset')}>
              <Link to={`/fixed-assets/assets/${disposal.asset?.id}`} className="text-primary hover:text-primary-hover">
                {disposal.asset?.number} · {disposal.asset?.name}
              </Link>
            </Detail>
            <Detail label={t('fixedAssets.disposals.kind')}>{t(`fixedAssets.disposals.kinds.${disposal.kind}`)}</Detail>
            <Detail label={t('fixedAssets.disposals.date')}>
              <CalendarDate value={disposal.disposal_date} />
            </Detail>
            {disposal.kind === 'sale' ? (
              <Detail label={t('fixedAssets.disposals.proceeds')}>
                <Amount value={disposal.proceeds} />
              </Detail>
            ) : null}
            {disposal.party ? <Detail label={t('fixedAssets.disposals.buyer')}>{disposal.party.name}</Detail> : null}
            <Detail label={t('fixedAssets.fields.reason')}>{disposal.reason}</Detail>
          </dl>
        </Card>
        {disposal.status === 'posted' ? (
          <Card title={t('fixedAssets.disposals.result')}>
            <dl className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
              <Detail label={t('fixedAssets.disposals.lastCharge')}>
                <Amount value={disposal.depreciation} />
              </Detail>
              <Detail label={t('fixedAssets.fields.cost')}>
                <Amount value={disposal.cost} />
              </Detail>
              <Detail label={t('fixedAssets.columns.accumulated')}>
                <Amount value={disposal.accumulated} />
              </Detail>
              <Detail label={t('fixedAssets.columns.bookValue')}>
                <Amount value={disposal.book_value} />
              </Detail>
              <Detail label={t('fixedAssets.disposals.baseProceeds')}>
                <Amount value={disposal.base_proceeds} />
              </Detail>
              <Detail label={t('fixedAssets.disposals.gain')}>
                <Amount value={disposal.gain} className="font-medium" />
              </Detail>
            </dl>
          </Card>
        ) : null}
        {disposal.status === 'pending' ? <DocumentFlow type="fixed_assets.disposal" id={disposal.id} /> : null}
      </div>
      <ReasonDialog
        open={cancelling}
        title={t('fixedAssets.disposals.cancelTitle', { number: disposal.number })}
        confirm={t('fixedAssets.disposals.cancel')}
        path={`fixed-assets/disposals/${disposal.id}/cancel`}
        onClose={() => setCancelling(false)}
        onDone={async () => {
          setCancelling(false)
          await queryClient.invalidateQueries({ queryKey: faKey })
        }}
      />
    </>
  )
}

/** FA-15, FA-16: revaluations and impairments, newest first. */
export function Revaluations() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const columns = [
    { key: 'asset', label: t('fixedAssets.columns.asset'), hideable: false, render: (row) => <AssetCell asset={row.asset} /> },
    { key: 'kind', label: t('fixedAssets.revaluations.kind'), render: (row) => t(`fixedAssets.revaluations.kinds.${row.kind}`) },
    { key: 'revalued_on', label: t('fixedAssets.revaluations.date'), render: (row) => <CalendarDate value={row.revalued_on} /> },
    { key: 'value', label: t('fixedAssets.revaluations.value'), align: 'end', render: (row) => <Amount value={row.value} /> },
    { key: 'surplus_change', label: t('fixedAssets.revaluations.surplusChange'), align: 'end', render: (row) => <Amount value={row.surplus_change} /> },
    { key: 'pnl_change', label: t('fixedAssets.revaluations.pnlChange'), align: 'end', render: (row) => <Amount value={row.pnl_change} /> },
  ]
  const list = useServerList({ id: 'fixed-assets-revaluations', endpoint: 'fixed-assets/revaluations', queryKey: [...faKey, 'revaluations'], filters: { from: '', to: '' }, columns })
  return (
    <>
      <PageHeader title={t('fixedAssets.revaluations.title')} description={t('fixedAssets.revaluations.description')} />
      <ListView
        list={list}
        title={t('fixedAssets.revaluations.title')}
        searchable={false}
        filterFields={[dateField('from', t('fixedAssets.filters.from')), dateField('to', t('fixedAssets.filters.to'))]}
        emptyText={t('fixedAssets.revaluations.empty')}
        onRowClick={(row) => navigate(`/fixed-assets/assets/${row.asset?.id}`)}
      />
    </>
  )
}
