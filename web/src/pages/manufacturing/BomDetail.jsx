import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, Dialog, Select } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { useUoms } from '@/pages/catalogue/catalogueData'
import { Back, Detail, DetailState, MfgStatus, Qty } from './ManufacturingParts'
import { kindLabel, manufacturingKey } from './manufacturingData'

const EMPTY = 'empty'

/** MFG-03: a new draft version, empty or a copy of a version (409 draft_exists while one is open). */
function NewVersionDialog({ bom, open, onClose, onDone }) {
  const { t } = useTranslation()
  const versions = bom.versions ?? []
  const [from, setFrom] = useState(bom.current_version?.id ?? versions[versions.length - 1]?.id ?? EMPTY)
  const send = useMutation({
    mutationFn: () => api.post(`manufacturing/boms/${bom.id}/versions`, from === EMPTY ? {} : { copy_from_id: from }),
    onSuccess: (response) => onDone(response),
  })
  const close = () => {
    send.reset()
    onClose()
  }
  return (
    <Dialog
      open={open}
      title={t('manufacturing.versions.newTitle')}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={send.isPending} onClick={() => send.mutate()}>
            {t('manufacturing.versions.create')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('manufacturing.versions.newHelp')}</p>
        {send.isError ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        <Select
          label={t('manufacturing.versions.copyFrom')}
          options={[{ value: EMPTY, label: t('manufacturing.versions.startEmpty') }, ...versions.map((version) => ({ value: version.id, label: t('manufacturing.boms.versionNo', { number: version.version_no }) }))]}
          value={from}
          onChange={(event) => setFrom(event.target.value)}
        />
      </div>
    </Dialog>
  )
}

/** TEN-06: archive a BOM (never deleted); its versions stay readable. */
function ArchiveDialog({ bom, open, onClose, onDone }) {
  const { t } = useTranslation()
  const send = useMutation({ mutationFn: () => api.post(`manufacturing/boms/${bom.id}/archive`, {}), onSuccess: (response) => onDone(response) })
  const close = () => {
    send.reset()
    onClose()
  }
  return (
    <Dialog
      open={open}
      title={t('manufacturing.boms.archiveTitle', { item: bom.item?.name ?? '' })}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('manufacturing.actions.keep')}
          </Button>
          <Button variant="danger" loading={send.isPending} onClick={() => send.mutate()}>
            {t('manufacturing.boms.archive')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('manufacturing.boms.archiveHelp')}</p>
        {send.isError ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
      </div>
    </Dialog>
  )
}

/**
 * MFG-02, MFG-03: one BOM or recipe: its output item, kind and company,
 * the version in force, every version with its status and date, and New
 * version and Archive for those who may manage it.
 */
export default function BomDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { bomId } = useParams()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const uoms = useUoms()
  const [dialog, setDialog] = useState(null)
  const key = [...manufacturingKey, 'bom', bomId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`manufacturing/boms/${bomId}`) })
  const bom = query.data?.data
  const back = <Back to="/manufacturing/boms">{t('manufacturing.boms.back')}</Back>

  if (!bom) return <DetailState query={query} back={back} notFound={t('manufacturing.boms.notFound')} />

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(bom.company?.id)) : '')
  const unitCode = (id) => uoms.all.find((uom) => uom.id === id)?.code ?? ''
  const manage = Boolean(bom.can?.manage)

  const columns = [
    {
      key: 'version_no',
      label: t('manufacturing.columns.version'),
      render: (row) => (
        <Link to={`/manufacturing/bom-versions/${row.id}`} className="font-medium text-primary tabular-nums hover:text-primary-hover">
          {t('manufacturing.boms.versionNo', { number: row.version_no })}
        </Link>
      ),
    },
    {
      key: 'status',
      label: t('manufacturing.columns.status'),
      render: (row) => (
        <span className="flex flex-wrap items-center gap-2">
          <MfgStatus status={row.status} />
          {bom.current_version?.id === row.id ? <span className="text-caption text-ink-muted">{t('manufacturing.versions.inForce')}</span> : null}
        </span>
      ),
    },
    { key: 'effective_from', label: t('manufacturing.columns.effectiveFrom'), render: (row) => <span className="tabular-nums">{when(row.effective_from)}</span> },
    { key: 'output', label: t('manufacturing.columns.output'), align: 'end', render: (row) => <Qty value={row.output_qty} unit={unitCode(row.output_uom_id)} /> },
    { key: 'labour', label: t('manufacturing.columns.labourMinutes'), align: 'end', numeric: true, render: (row) => row.labour_minutes ?? '' },
  ]

  const done = async (response, message) => {
    setDialog(null)
    toast.success(message)
    await queryClient.invalidateQueries({ queryKey: manufacturingKey })
    return response
  }

  return (
    <>
      {back}
      <PageHeader
        eyebrow={kindLabel(t, bom.kind)}
        title={bom.item?.name ?? ''}
        description={bom.name ?? undefined}
        actions={
          <div className="flex flex-wrap gap-2">
            {bom.draft_id ? (
              <Button onClick={() => navigate(`/manufacturing/bom-versions/${bom.draft_id}`)}>{t('manufacturing.versions.openDraft')}</Button>
            ) : manage ? (
              <Button variant="primary" icon="plus" onClick={() => setDialog('version')}>
                {t('manufacturing.versions.new')}
              </Button>
            ) : null}
            {manage ? (
              <Button variant="danger" icon="archive" onClick={() => setDialog('archive')}>
                {t('manufacturing.boms.archive')}
              </Button>
            ) : null}
          </div>
        }
      />
      <div className="flex flex-col gap-5">
        {bom.archived_at ? <Alert tone="info" title={t('manufacturing.boms.archivedNote')} /> : null}
        <Card title={t('manufacturing.boms.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('manufacturing.fields.outputItem')}>
              <span className="flex flex-col">
                <span>{bom.item?.name}</span>
                <span className="font-mono text-caption text-ink-muted">{bom.item?.code}</span>
              </span>
            </Detail>
            <Detail label={t('manufacturing.fields.kind')}>{kindLabel(t, bom.kind)}</Detail>
            <Detail label={t('manufacturing.fields.company')}>{bom.company?.name}</Detail>
            <Detail label={t('manufacturing.columns.versionInForce')}>
              {bom.current_version ? (
                <span className="flex flex-col">
                  <span className="tabular-nums">{t('manufacturing.boms.versionNo', { number: bom.current_version.version_no })}</span>
                  <span className="text-caption text-ink-muted tabular-nums">{t('manufacturing.versions.since', { date: when(bom.current_version.effective_from) })}</span>
                </span>
              ) : (
                <span className="text-ink-muted">{t('manufacturing.boms.noneInForce')}</span>
              )}
            </Detail>
          </dl>
        </Card>
        <Card title={t('manufacturing.versions.title')}>
          <DataTable caption={t('manufacturing.versions.title')} columns={columns} rows={bom.versions ?? []} emptyText={t('manufacturing.versions.empty')} onRowClick={(row) => navigate(`/manufacturing/bom-versions/${row.id}`)} />
        </Card>
      </div>
      {dialog === 'version' ? (
        <NewVersionDialog
          bom={bom}
          open
          onClose={() => setDialog(null)}
          onDone={async (response) => {
            await done(response, t('manufacturing.versions.created', { number: response?.data?.version_no ?? '' }))
            navigate(`/manufacturing/bom-versions/${response.data.id}`)
          }}
        />
      ) : null}
      <ArchiveDialog
        bom={bom}
        open={dialog === 'archive'}
        onClose={() => setDialog(null)}
        onDone={async (response) => {
          queryClient.setQueryData(key, response)
          await done(response, t('manufacturing.boms.archivedDone'))
        }}
      />
    </>
  )
}
