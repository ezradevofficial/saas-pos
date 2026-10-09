import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card } from '@/components/ds'
import { PinDialog, PinStatus, RemovePinDialog } from './PinParts'

/**
 * AUTH-06: a user's POS PIN as an administrator sees it: set or not, must
 * be changed at the till, since when (never the PIN). With
 * `core.user.edit` over the user, set a new one (the person changes it at
 * the till first) or remove it; acting on oneself takes the password.
 */
export function UserPinCard({ user, self, canEdit }) {
  const { t } = useTranslation()
  const [dialog, setDialog] = useState(null) // 'set' | 'remove'
  const [notice, setNotice] = useState(null)
  const query = useQuery({ queryKey: ['pos-pin', 'user', user.id], queryFn: () => api.get(`users/${user.id}/pos-pin`) })
  const status = query.data?.data
  const endpoint = `users/${user.id}/pos-pin`

  return (
    <Card
      title={t('posPin.userCardTitle')}
      actions={
        canEdit && status ? (
          <>
            {status.pin_set ? <Button onClick={() => setDialog('remove')}>{t('posPin.remove')}</Button> : null}
            <Button icon="key" onClick={() => setDialog('set')}>
              {status.pin_set ? t('posPin.reset') : t('posPin.set')}
            </Button>
          </>
        ) : null
      }
    >
      <div className="flex flex-col gap-3">
        {notice ? <Alert tone="success" title={notice} /> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : <PinStatus status={status} />}
        {status?.six_digits ? <p className="text-caption text-ink-muted">{t('posPin.userSixDigits')}</p> : null}
      </div>
      {dialog === 'set' ? (
        <PinDialog
          title={t('posPin.resetTitle', { name: user.name })}
          intro={self ? null : t('posPin.resetIntro')}
          endpoint={endpoint}
          withPassword={self}
          sixDigits={Boolean(status?.six_digits)}
          confirmLabel={status?.pin_set ? t('posPin.reset') : t('posPin.set')}
          onClose={() => setDialog(null)}
          onSaved={(answer) => setNotice(answer?.message ?? t('posPin.saved'))}
        />
      ) : null}
      {dialog === 'remove' ? (
        <RemovePinDialog
          title={t('posPin.removeUserTitle', { name: user.name })}
          text={t('posPin.removeUserText')}
          endpoint={endpoint}
          withPassword={self}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </Card>
  )
}
