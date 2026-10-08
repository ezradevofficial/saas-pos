import { useTranslation } from 'react-i18next'
import { Button, Dialog } from '@/components/ds'
import { HistoryPanel } from './HistoryPanel'

/** MD-07: a record's history in a dialog, for records listed without a page of their own. */
export function HistoryDialog({ record, type, name, timeZone, fields, onClose }) {
  const { t } = useTranslation()
  return (
    <Dialog
      open={Boolean(record)}
      size="lg"
      title={record ? t('history.dialogTitle', { name }) : ''}
      onClose={onClose}
      footer={
        <Button variant="ghost" onClick={onClose}>
          {t('history.close')}
        </Button>
      }
    >
      {record ? (
        <div className="pt-2">
          <HistoryPanel type={type} recordId={record.id} timeZone={timeZone} fields={fields} />
        </div>
      ) : null}
    </Dialog>
  )
}
