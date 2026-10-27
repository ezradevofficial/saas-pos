import { useTranslation } from 'react-i18next'
import { Alert, Button, Dialog, Select } from '@/components/ds'

/** A picker for the location in use; nothing when there is only one. */
export function LocationPicker({ choice }) {
  const { t } = useTranslation()
  if (choice.locations.length < 2) return null
  return (
    <Select
      label={t('restaurant.fields.location')}
      options={choice.locations.map((entry) => ({ value: entry.id, label: entry.name }))}
      value={choice.location?.id ?? ''}
      onChange={(event) => choice.choose(event.target.value)}
      className="max-w-field"
    />
  )
}

/** A picker for the company in use; nothing when there is only one. */
export function CompanyPicker({ choice }) {
  const { t } = useTranslation()
  if (choice.companies.length < 2) return null
  return (
    <Select
      label={t('restaurant.fields.company')}
      options={choice.companies.map((entry) => ({ value: entry.id, label: entry.name }))}
      value={choice.company?.id ?? ''}
      onChange={(event) => choice.choose(event.target.value)}
      className="max-w-field"
    />
  )
}

/** A question before archiving or cancelling. */
export function ConfirmDialog({ open, title, message, confirmLabel, onConfirm, onClose, loading, error }) {
  const { t } = useTranslation()
  return (
    <Dialog
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="danger" loading={loading} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {error ? <Alert tone="danger" title={error} /> : null}
        <p>{message}</p>
      </div>
    </Dialog>
  )
}
