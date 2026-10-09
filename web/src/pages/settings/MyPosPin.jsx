import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { PinDialog, PinStatus, RemovePinDialog } from './pin/PinParts'

/**
 * AUTH-06: one's own POS PIN, for signing in at the tills. Setting or
 * removing it takes the account password; people who approve voids,
 * refunds and price changes need 6 digits (AUTH-08). Never shows the PIN.
 */
export default function MyPosPin() {
  const { t } = useTranslation()
  const [dialog, setDialog] = useState(null) // 'set' | 'remove'
  const [notice, setNotice] = useState(null)
  const query = useQuery({ queryKey: ['pos-pin', 'me'], queryFn: () => api.get('me/pos-pin') })
  const status = query.data?.data

  return (
    <>
      <PageHeader title={t('posPin.title')} description={t('posPin.description')} />
      {notice ? <Alert tone="success" title={notice} /> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {status ? (
        <Card
          title={t('posPin.cardTitle')}
          actions={
            <>
              {status.pin_set ? <Button onClick={() => setDialog('remove')}>{t('posPin.remove')}</Button> : null}
              <Button variant="primary" icon="key" onClick={() => setDialog('set')}>
                {status.pin_set ? t('posPin.change') : t('posPin.set')}
              </Button>
            </>
          }
        >
          <div className="flex flex-col gap-3">
            <PinStatus status={status} />
            <p className="text-ink-muted">{status.six_digits ? t('posPin.sixDigitsNote') : t('posPin.lengthNote')}</p>
            {status.must_change ? <p className="text-ink-muted">{t('posPin.mustChangeNote')}</p> : null}
          </div>
        </Card>
      ) : null}
      {dialog === 'set' ? (
        <PinDialog
          title={status?.pin_set ? t('posPin.changeTitle') : t('posPin.setTitle')}
          endpoint="me/pos-pin"
          withPassword
          sixDigits={Boolean(status?.six_digits)}
          confirmLabel={t('posPin.save')}
          onClose={() => setDialog(null)}
          onSaved={(answer) => setNotice(answer?.message ?? t('posPin.saved'))}
        />
      ) : null}
      {dialog === 'remove' ? (
        <RemovePinDialog title={t('posPin.removeTitle')} text={t('posPin.removeSelfText')} endpoint="me/pos-pin" withPassword onClose={() => setDialog(null)} />
      ) : null}
    </>
  )
}
