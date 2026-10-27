import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { Back, CalendarDate, Detail, DetailState, ReasonDialog, RecordStatus } from './PharmacyParts'
import { pharmacyKey, PRESCRIPTION_CANCEL, PRESCRIPTION_CREATE, PRESCRIPTION_IMAGE } from './pharmacyData'

const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf'

/**
 * PHA-14, PHA-15, PHA-28: one prescription (personal data): who prescribed,
 * for whom, when; the optional image (added here, opened through a
 * short-lived link that is audited when used); Cancel with a reason.
 */
export default function PrescriptionDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { prescriptionId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const input = useRef(null)
  const [cancelling, setCancelling] = useState(false)
  const key = [...pharmacyKey, 'prescription', prescriptionId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`pharmacy/prescriptions/${prescriptionId}`) })
  const prescription = query.data?.data
  const back = <Back to="/pharmacy/prescriptions">{t('pharmacy.prescriptions.back')}</Back>

  const upload = useMutation({
    mutationFn: (file) => {
      const form = new FormData()
      form.append('file', file)
      return api.upload(`pharmacy/prescriptions/${prescriptionId}/image`, form)
    },
    onSuccess: async (response) => {
      queryClient.setQueryData(key, response)
      toast.success(t('pharmacy.prescription.imageSaved'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
    },
  })
  const open = useMutation({
    mutationFn: () => api.get(`pharmacy/prescriptions/${prescriptionId}/image-link`),
    onSuccess: (response) => window.open(response.data.url, '_blank', 'noopener'),
  })

  if (!prescription) return <DetailState query={query} back={back} notFound={t('pharmacy.prescription.notFound')} />

  const problem = upload.error ?? open.error

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('pharmacy.prescription.eyebrow')}
        title={prescription.patient_name}
        description={<RecordStatus status={prescription.status} />}
        actions={
          prescription.status === 'valid' && can(PRESCRIPTION_CANCEL) ? (
            <Button variant="danger" onClick={() => setCancelling(true)}>
              {t('pharmacy.prescription.cancel')}
            </Button>
          ) : null
        }
      />
      {problem ? <Alert tone="danger" title={errorMessage(problem)} /> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('pharmacy.prescription.prescriber')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('pharmacy.columns.prescriber_name')}>{prescription.prescriber_name}</Detail>
            <Detail label={t('pharmacy.columns.prescriber_licence')}>
              <span className="font-mono">{prescription.prescriber_licence}</span>
            </Detail>
            <Detail label={t('pharmacy.columns.facility')}>{prescription.facility}</Detail>
            <Detail label={t('pharmacy.columns.prescribed_on')}>
              <CalendarDate value={prescription.prescribed_on} />
            </Detail>
          </dl>
        </Card>
        <Card title={t('pharmacy.prescription.patient')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('pharmacy.columns.patient_name')}>{prescription.patient_name}</Detail>
            <Detail label={t('pharmacy.prescriptions.patientPhone')}>{prescription.patient_phone ?? <span className="text-ink-muted">—</span>}</Detail>
            <Detail label={t('pharmacy.prescriptions.patientBirthDate')}>
              <CalendarDate value={prescription.patient_birth_date} />
            </Detail>
            <Detail label={t('pharmacy.prescription.customer')}>{prescription.patient?.name ?? <span className="text-ink-muted">—</span>}</Detail>
            {prescription.notes ? <Detail label={t('pharmacy.fields.note')}>{prescription.notes}</Detail> : null}
            <Detail label={t('pharmacy.fields.location')}>
              <span className="flex flex-col">
                <span>{prescription.location?.name}</span>
                <span className="text-caption text-ink-muted">{t(`pharmacy.sources.${prescription.source}`)} · {formatDate(prescription.created_at, locale)}</span>
              </span>
            </Detail>
            {prescription.cancelled_at ? (
              <Detail label={t('pharmacy.prescription.cancelled')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{formatDate(prescription.cancelled_at, locale)}</span>
                  <span className="text-caption text-ink-muted">{prescription.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card
          title={t('pharmacy.prescription.image')}
          subtitle={t('pharmacy.prescription.imageHelp')}
          actions={
            can(PRESCRIPTION_CREATE) ? (
              <>
                <input ref={input} type="file" accept={ACCEPT} aria-label={t('pharmacy.prescription.chooseImage')} className="sr-only" onChange={(event) => event.target.files?.[0] && upload.mutate(event.target.files[0])} />
                <Button icon="upload" loading={upload.isPending} onClick={() => input.current?.click()}>
                  {prescription.image ? t('pharmacy.prescription.replaceImage') : t('pharmacy.prescription.addImage')}
                </Button>
              </>
            ) : null
          }
        >
          {prescription.image ? (
            <div className="flex flex-wrap items-center gap-3">
              <span className="text-body text-ink">{prescription.image.name}</span>
              {can(PRESCRIPTION_IMAGE) ? (
                <Button variant="ghost" icon="show" loading={open.isPending} onClick={() => open.mutate()}>
                  {t('pharmacy.prescription.openImage')}
                </Button>
              ) : null}
            </div>
          ) : (
            <p className="text-body text-ink-muted">{t('pharmacy.prescription.noImage')}</p>
          )}
        </Card>
      </div>
      <ReasonDialog
        open={cancelling}
        title={t('pharmacy.prescription.cancelTitle')}
        help={t('pharmacy.prescription.cancelHelp')}
        label={t('pharmacy.lot.reasonLabel')}
        action={t('pharmacy.prescription.cancel')}
        path={`pharmacy/prescriptions/${prescriptionId}/cancel`}
        onClose={() => setCancelling(false)}
        onDone={async (response) => {
          setCancelling(false)
          if (response?.data) queryClient.setQueryData(key, response)
          toast.success(t('pharmacy.prescription.cancelledToast'))
          await queryClient.invalidateQueries({ queryKey: pharmacyKey })
        }}
      />
    </>
  )
}
