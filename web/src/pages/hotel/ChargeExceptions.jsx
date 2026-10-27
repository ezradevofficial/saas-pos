import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { Amount, BranchSwitch, NoProperty, Stamp, StateBadge } from './HotelParts'
import { CHARGE_RESOLVE, hotelKey, RESOLUTIONS, useBranchChoice } from './hotelData'

/** HOT-22: put a room charge that could not be posted on a folio, or close it with a note. */
function ResolveDialog({ exception, branchId, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [resolution, setResolution] = useState('moved')
  const [folioId, setFolioId] = useState('')
  const [note, setNote] = useState('')
  const moved = resolution === 'moved'
  const folios = useQuery({ queryKey: [...hotelKey, 'folio-targets', branchId], queryFn: () => api.get(`hotel/folios?branch=${branchId}&status=open&per_page=100`), enabled: moved })
  const targets = (folios.data?.data ?? []).filter((folio) => folio.currency === exception.currency)
  const save = useMutation({
    mutationFn: () => api.post(`hotel/charge-exceptions/${exception.id}/resolve`, moved ? { resolution, folio_id: folioId, ...(note.trim() ? { note: note.trim() } : {}) } : { resolution, note: note.trim() }),
    onSuccess: async () => {
      toast.success(t('hotel.exceptions.resolved'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['resolution', 'folio_id', 'note'])
  const label = (folio) => [folio.number, folio.room_number ? t('hotel.reservation.roomNumber', { number: folio.room_number }) : null, folio.guest?.name].filter(Boolean).join(' · ')
  return (
    <Dialog open title={t('hotel.exceptions.resolveTitle', { reference: exception.reference ?? exception.room_number ?? '' })} onClose={onClose} footer={<><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button variant="primary" loading={save.isPending} disabled={moved ? !folioId : !note.trim()} onClick={() => save.mutate()}>{t('hotel.exceptions.resolve')}</Button></>}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <p className="text-body text-ink">
          <Amount minor={exception.amount_minor} currency={exception.currency} />
        </p>
        <Select label={t('hotel.exceptions.how')} options={RESOLUTIONS.map((value) => ({ value, label: t(`hotel.exceptions.resolutions.${value}`) }))} value={resolution} error={errors.fields.resolution} onChange={(event) => setResolution(event.target.value)} />
        {moved ? <Select label={t('hotel.exceptions.toFolio')} placeholder={t('hotel.pickers.folio')} options={targets.map((folio) => ({ value: folio.id, label: label(folio) }))} value={folioId} required error={errors.fields.folio_id} onChange={(event) => setFolioId(event.target.value)} /> : null}
        <TextField label={moved ? t('hotel.exceptions.noteOptional') : t('hotel.exceptions.note')} value={note} maxLength={500} required={!moved} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** HOT-22: room charges from tills that could not be posted (the guest left, the room was unknown), for the front desk to resolve. */
export default function ChargeExceptions() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const choice = useBranchChoice()
  const [resolving, setResolving] = useState(null)
  const branchId = choice.branch?.id
  const columns = [
    { key: 'created_at', label: t('hotel.columns.created_at'), sortKey: 'created_at', render: (row) => <Stamp value={row.created_at} companyId={choice.companyId} /> },
    { key: 'reference', label: t('hotel.columns.reference'), render: (row) => <span className="font-mono">{row.reference ?? ''}</span> },
    { key: 'room', label: t('hotel.columns.room'), render: (row) => row.room_number ?? '' },
    { key: 'amount_minor', label: t('hotel.columns.amount_minor'), align: 'end', sortKey: 'amount', render: (row) => <Amount minor={row.amount_minor} currency={row.currency} /> },
    { key: 'reason', label: t('hotel.columns.reason'), render: (row) => t(`hotel.exceptions.reasons.${row.reason}`, { defaultValue: row.reason }) },
    { key: 'status', label: t('hotel.columns.status'), sortKey: 'status', render: (row) => <StateBadge status={row.status} /> },
    {
      key: 'actions',
      label: <span className="sr-only">{t('hotel.columns.actions')}</span>,
      inMenu: false,
      render: (row) =>
        row.status === 'open' && can(CHARGE_RESOLVE) ? (
          <Button variant="secondary" aria-label={t('hotel.exceptions.resolveTitle', { reference: row.reference ?? row.room_number ?? '' })} onClick={() => setResolving(row)}>
            {t('hotel.exceptions.resolve')}
          </Button>
        ) : null,
    },
  ]
  const list = useServerList({
    id: 'hotel-charge-exceptions',
    endpoint: 'hotel/charge-exceptions',
    params: { branch: branchId },
    queryKey: [...hotelKey, 'charge-exceptions', branchId],
    filters: { status: '' },
    defaultSort: '-created_at',
    columns,
  })
  return (
    <>
      <PageHeader title={t('hotel.exceptions.title')} description={t('hotel.exceptions.description')} />
      <BranchSwitch choice={choice} />
      {choice.branch ? (
        <ListView
          list={list}
          title={t('hotel.exceptions.title')}
          searchable={false}
          filterFields={[{ name: 'status', label: t('hotel.columns.status'), options: [{ value: '', label: t('hotel.states.open') }, { value: 'resolved', label: t('hotel.states.resolved') }, { value: 'all', label: t('hotel.filters.all') }] }]}
          emptyText={t('hotel.exceptions.empty')}
        />
      ) : (
        <NoProperty choice={choice} />
      )}
      {resolving ? <ResolveDialog exception={resolving} branchId={branchId} onClose={() => setResolving(null)} /> : null}
    </>
  )
}
