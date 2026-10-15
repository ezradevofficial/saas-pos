import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, TextField } from '@/components/ds'
import { useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, CalendarDate, Detail, DetailState, FaStatus, ReasonDialog } from './FixedAssetsParts'
import { DEPRECIATION_POST, faKey } from './fixedAssetsData'

/** The charge lines of a preview or a run. */
function LineTable({ lines, caption }) {
  const { t } = useTranslation()
  const columns = [
    { key: 'asset', label: t('fixedAssets.columns.number'), render: (row) => <span className="tabular-nums">{row.asset?.number}</span> },
    { key: 'name', label: t('fixedAssets.columns.name'), render: (row) => row.asset?.name },
    { key: 'from', label: t('fixedAssets.schedule.from'), render: (row) => <CalendarDate value={row.from} /> },
    { key: 'to', label: t('fixedAssets.schedule.to'), render: (row) => <CalendarDate value={row.to} /> },
    { key: 'units', label: t('fixedAssets.runs.units'), align: 'end', render: (row) => <span className="tabular-nums">{row.units ?? '—'}</span> },
    { key: 'amount', label: t('fixedAssets.schedule.charge'), align: 'end', render: (row) => <Amount value={row.amount} /> },
    { key: 'book_value', label: t('fixedAssets.columns.bookValue'), align: 'end', render: (row) => <Amount value={row.book_value} /> },
  ]
  return <DataTable caption={caption} columns={columns} rows={lines.map((line, index) => ({ ...line, id: line.id ?? `${line.asset?.id}-${index}` }))} emptyText={t('fixedAssets.runs.noLines')} />
}

/**
 * FA-12: depreciation runs of the company in use: preview a month (nothing
 * saved), post it (one per month, in order, never in a closed period),
 * and the runs posted.
 */
export default function Runs() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { company } = useCompanySelection()
  const [period, setPeriod] = useState('')
  const runs = useQuery({ queryKey: [...faKey, 'runs', company?.id], queryFn: () => api.get(`fixed-assets/runs?per_page=50${company ? `&company=${company.id}` : ''}`) })
  const preview = useMutation({ mutationFn: () => api.get(`fixed-assets/runs/preview?company=${company.id}&period=${period}`) })
  const post = useMutation({
    mutationFn: () => api.post('fixed-assets/runs', { company_id: company.id, period }),
    onSuccess: async (response) => {
      toast.success(t('fixedAssets.runs.posted', { month: response.data.period }))
      await queryClient.invalidateQueries({ queryKey: faKey })
      navigate(`/fixed-assets/runs/${response.data.id}`)
    },
  })
  const data = preview.data?.data
  const mayPost = can(DEPRECIATION_POST) && company
  const columns = [
    { key: 'period', label: t('fixedAssets.runs.period'), render: (row) => <span className="font-medium tabular-nums">{row.period}</span> },
    { key: 'status', label: t('fixedAssets.columns.status'), render: (row) => <FaStatus status={row.status} /> },
    { key: 'assets_count', label: t('fixedAssets.runs.assets'), align: 'end', render: (row) => <span className="tabular-nums">{row.assets_count}</span> },
    { key: 'total', label: t('fixedAssets.runs.total'), align: 'end', render: (row) => <Amount value={row.total} /> },
    { key: 'posted_by', label: t('fixedAssets.runs.postedBy'), render: (row) => row.posted_by?.name ?? '' },
  ]

  return (
    <>
      <PageHeader title={t('fixedAssets.runs.title')} description={t('fixedAssets.runs.description')} />
      <div className="flex flex-col gap-5">
        {!company ? <Alert tone="info" title={t('fixedAssets.chooseCompany')} /> : null}
        {mayPost ? (
          <Card title={t('fixedAssets.runs.newTitle')} subtitle={t('fixedAssets.runs.newHelp')}>
            <div className="flex flex-col gap-4">
              <div className="flex flex-wrap items-end gap-3">
                <TextField type="month" label={t('fixedAssets.runs.period')} value={period} onChange={(event) => setPeriod(event.target.value)} />
                <Button disabled={!period} loading={preview.isPending} onClick={() => preview.mutate()}>
                  {t('fixedAssets.runs.preview')}
                </Button>
              </div>
              {preview.isError ? <Alert tone="danger" title={errorMessage(preview.error)} /> : null}
              {post.isError ? <Alert tone="danger" title={errorMessage(post.error)} /> : null}
              {data ? (
                <>
                  {data.next_period && data.next_period !== data.period ? <Alert tone="warning" title={t('fixedAssets.runs.outOfOrder', { month: data.next_period })} /> : null}
                  {data.closed_through && data.closed_through >= data.period_end ? <Alert tone="warning" title={t('fixedAssets.runs.closed', { date: data.closed_through })} /> : null}
                  <dl className="grid gap-4 sm:grid-cols-3">
                    <Detail label={t('fixedAssets.runs.assets')}>{data.assets_count}</Detail>
                    <Detail label={t('fixedAssets.runs.total')}>
                      <Amount value={data.total} />
                    </Detail>
                    <Detail label={t('fixedAssets.runs.convention')}>{t(`fixedAssets.conventions.${data.convention}`)}</Detail>
                  </dl>
                  <LineTable lines={data.lines} caption={t('fixedAssets.runs.preview')} />
                  <div>
                    <Button variant="pay" loading={post.isPending} onClick={() => post.mutate()}>
                      {t('fixedAssets.runs.post', { month: data.period })}
                    </Button>
                  </div>
                </>
              ) : null}
            </div>
          </Card>
        ) : null}
        <Card title={t('fixedAssets.runs.postedTitle')}>
          <DataTable caption={t('fixedAssets.runs.postedTitle')} columns={columns} rows={runs.data?.data ?? []} loading={runs.isPending} emptyText={t('fixedAssets.runs.empty')} onRowClick={(row) => navigate(`/fixed-assets/runs/${row.id}`)} />
        </Card>
      </div>
    </>
  )
}

/** FA-12, FA-13: one run: its lines and, for the latest, its reversal. */
export function RunDetail() {
  const { t } = useTranslation()
  const { runId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [reversing, setReversing] = useState(false)
  const query = useQuery({ queryKey: [...faKey, 'run', runId], queryFn: () => api.get(`fixed-assets/runs/${runId}`) })
  const back = <Back to="/fixed-assets/runs">{t('fixedAssets.runs.back')}</Back>
  const run = query.data?.data
  if (!run) return <DetailState query={query} back={back} notFound={t('fixedAssets.runs.notFound')} />

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('fixedAssets.runs.eyebrow')}
        title={run.period}
        description={<FaStatus status={run.status} />}
        actions={run.status === 'posted' && can(DEPRECIATION_POST) ? <Button variant="danger" onClick={() => setReversing(true)}>{t('fixedAssets.runs.reverse')}</Button> : null}
      />
      <div className="flex flex-col gap-5">
        <Card title={t('fixedAssets.detail.summary')}>
          <dl className="grid gap-4 sm:grid-cols-4">
            <Detail label={t('fixedAssets.runs.assets')}>{run.assets_count}</Detail>
            <Detail label={t('fixedAssets.runs.total')}>
              <Amount value={run.total} />
            </Detail>
            <Detail label={t('fixedAssets.runs.postedBy')}>{run.posted_by?.name}</Detail>
            {run.reverse_reason ? <Detail label={t('fixedAssets.runs.reversedBecause')}>{run.reverse_reason}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('fixedAssets.runs.lines')}>
          <LineTable lines={run.lines ?? []} caption={t('fixedAssets.runs.lines')} />
        </Card>
      </div>
      <ReasonDialog
        open={reversing}
        title={t('fixedAssets.runs.reverseTitle', { month: run.period })}
        help={t('fixedAssets.runs.reverseHelp')}
        confirm={t('fixedAssets.runs.reverse')}
        path={`fixed-assets/runs/${run.id}/reverse`}
        onClose={() => setReversing(false)}
        onDone={async () => {
          setReversing(false)
          toast.success(t('fixedAssets.runs.reversed'))
          await queryClient.invalidateQueries({ queryKey: faKey })
        }}
      />
    </>
  )
}
