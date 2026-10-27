import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { CalendarDate, Qty } from './PharmacyParts'
import { dateField, locationFilter, pharmacyKey, REGISTER_ADJUST, REGISTER_PRINT, usePlaces } from './pharmacyData'
import { registerHtml } from './registerPrint'

/** The print frame's sandbox (TPL-04): never allow-same-origin. */
const PRINT_SANDBOX = 'allow-scripts allow-modals'

/** The document's HTML with its scripts removed and one call to print once it has loaded. */
function printable(html) {
  const clean = String(html ?? '').replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
  const call = '<script>window.addEventListener("load", function () { window.print() })</script>'
  return clean.includes('</body>') ? clean.replace('</body>', `${call}</body>`) : clean + call
}

/** PHA-18: a correction is a new entry with a reason; the register is never edited. */
function CorrectionDialog({ itemId, locationId, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [qty, setQty] = useState('')
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => api.post('pharmacy/register/adjustments', { item_id: itemId, location_id: locationId, qty: qty.trim(), reason: reason.trim() }),
    onSuccess: async () => {
      toast.success(t('pharmacy.register.corrected'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['qty', 'reason'])
  return (
    <Dialog
      open
      title={t('pharmacy.register.correctTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!/^-?\d+(\.\d+)?$/.test(qty.trim()) || !reason.trim()} onClick={() => save.mutate()}>
            {t('pharmacy.register.correct')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-body text-ink-muted">{t('pharmacy.register.correctHelp')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <TextField label={t('pharmacy.register.correctQty')} help={t('pharmacy.register.correctQtyHelp')} inputMode="decimal" value={qty} error={errors.fields.qty} onChange={(event) => setQty(event.target.value)} />
        <TextField label={t('pharmacy.columns.reason')} value={reason} maxLength={500} error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/**
 * PHA-18, PHA-19: the controlled-drug register of one controlled item at one
 * place: every receipt, dispense, return, transfer, write-off and correction
 * with its running balance, the patient, prescriber and pharmacist; printable
 * and exportable. Nothing here edits an entry.
 */
export default function Register() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { can } = usePermissions()
  const places = usePlaces()
  const [correcting, setCorrecting] = useState(false)
  const [printing, setPrinting] = useState(null)
  const [printError, setPrintError] = useState(null)
  const [busy, setBusy] = useState(false)
  const items = useQuery({ queryKey: [...pharmacyKey, 'controlled-items'], queryFn: () => api.get('pharmacy/items?class=controlled&per_page=100'), staleTime: 60_000 })
  const columns = [
    { key: 'entry_no', label: t('pharmacy.columns.entry_no'), sortKey: 'entry_no', numeric: true, hideable: false, render: (row) => row.entry_no },
    { key: 'occurred_at', label: t('pharmacy.columns.occurred_at'), sortKey: 'occurred_at', render: (row) => <span className="tabular-nums">{formatDateTime(row.occurred_at, locale)}</span> },
    { key: 'kind', label: t('pharmacy.columns.kind'), render: (row) => t(`pharmacy.kinds.${row.kind}`) },
    { key: 'item', label: t('pharmacy.columns.item'), wrap: true, render: (row) => row.item?.name },
    { key: 'batch_number', label: t('pharmacy.columns.batch_number'), render: (row) => row.batch_number ?? '' },
    { key: 'expires_on', label: t('pharmacy.columns.expires_on'), defaultHidden: true, render: (row) => <CalendarDate value={row.expires_on} /> },
    { key: 'qty', label: t('pharmacy.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} /> },
    { key: 'balance', label: t('pharmacy.columns.balance'), align: 'end', render: (row) => <Qty value={row.balance} className="font-medium text-ink" /> },
    { key: 'patient_name', label: t('pharmacy.columns.patient_name'), render: (row) => row.patient_name ?? '' },
    { key: 'prescriber', label: t('pharmacy.columns.prescriber'), wrap: true, render: (row) => (row.prescriber_name ? `${row.prescriber_name} ${row.prescriber_licence ?? ''}`.trim() : '') },
    { key: 'pharmacist', label: t('pharmacy.columns.pharmacist'), render: (row) => row.pharmacist?.name ?? row.recorded_by?.name ?? '' },
    { key: 'reason', label: t('pharmacy.columns.reason'), defaultHidden: true, wrap: true, render: (row) => row.reason ?? '' },
  ]
  const list = useServerList({
    id: 'pharmacy-register',
    endpoint: 'pharmacy/register',
    queryKey: [...pharmacyKey, 'register'],
    filters: { item_id: '', location: '', from: '', to: '' },
    defaultSort: 'entry_no',
    columns,
  })
  const itemId = list.filters.item_id
  const locationId = list.filters.location
  const ready = Boolean(itemId && locationId)
  const company = places.companies[0]?.name ?? ''

  const print = async () => {
    setBusy(true)
    setPrintError(null)
    try {
      const query = new URLSearchParams({ item_id: itemId, location_id: locationId, ...(list.filters.from ? { from: list.filters.from } : {}), ...(list.filters.to ? { to: list.filters.to } : {}) })
      const { data } = await api.get(`pharmacy/register/print?${query}`)
      const html = registerHtml({ t, data, locale, pharmacy: company })
      setPrinting((current) => ({ html, n: (current?.n ?? 0) + 1 }))
    } catch (caught) {
      setPrintError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader
        title={t('pharmacy.register.title')}
        description={t('pharmacy.register.description')}
        actions={
          <span className="flex gap-2">
            {can(REGISTER_PRINT) ? (
              <Button icon="print" loading={busy} disabled={!ready} onClick={print}>
                {t('pharmacy.register.print')}
              </Button>
            ) : null}
            {can(REGISTER_ADJUST) ? (
              <Button disabled={!ready} onClick={() => setCorrecting(true)}>
                {t('pharmacy.register.correct')}
              </Button>
            ) : null}
          </span>
        }
      />
      {printError ? <Alert tone="danger" title={printError} /> : null}
      {!ready ? <Alert tone="info" title={t('pharmacy.register.pick')} /> : null}
      <ListView
        list={list}
        title={t('pharmacy.register.title')}
        searchLabel={t('pharmacy.register.search')}
        filterFields={[
          { name: 'item_id', label: t('pharmacy.columns.item'), options: [{ value: '', label: t('pharmacy.filters.anyItem') }, ...(items.data?.data ?? []).map((profile) => ({ value: profile.item.id, label: profile.item.name }))] },
          ...locationFilter(t, places),
          dateField('from', t('pharmacy.filters.from')),
          dateField('to', t('pharmacy.filters.to')),
        ]}
        emptyText={t('pharmacy.register.empty')}
      />
      {correcting ? <CorrectionDialog itemId={itemId} locationId={locationId} onClose={() => setCorrecting(false)} /> : null}
      {printing ? (
        // The page prints itself in a frame with an opaque origin: allow-scripts runs only the print call
        // added here (every value is escaped); allow-modals opens the print dialog.
        <iframe key={printing.n} title={t('pharmacy.print.frame')} sandbox={PRINT_SANDBOX} srcDoc={printable(printing.html)} aria-hidden="true" tabIndex={-1} className="sr-only" />
      ) : null}
    </>
  )
}
