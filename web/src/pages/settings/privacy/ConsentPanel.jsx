import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Select, StatusBadge, TextField } from '@/components/ds'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { SubjectPicker } from './SubjectPicker'

const CHANNELS = ['email', 'sms', 'whatsapp', 'any']

/**
 * NFR-08: a customer's marketing consent: the current answer per channel,
 * the history (every grant and withdrawal is kept), and recording a new
 * answer with what the customer said.
 */
export function ConsentPanel() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { tenantWide } = usePermissions()
  const canManage = tenantWide('core.consent.manage')
  const queryClient = useQueryClient()
  const [subjectId, setSubjectId] = useState('')
  const [channel, setChannel] = useState('email')
  const [evidence, setEvidence] = useState('')
  const key = ['privacy', 'consents', subjectId]
  const query = useQuery({
    queryKey: key,
    queryFn: () => api.get(`privacy/consents?subject_type=customer&subject_id=${subjectId}`),
    enabled: Boolean(subjectId),
  })
  const record = useMutation({
    mutationFn: (granted) => api.post('privacy/consents', { subject_type: 'customer', subject_id: subjectId, purpose: 'marketing', channel, granted, evidence: evidence.trim() || null }),
    onSuccess: async () => {
      setEvidence('')
      await queryClient.invalidateQueries({ queryKey: key })
    },
  })
  const current = Object.fromEntries((query.data?.data?.current ?? []).map((entry) => [entry.channel, entry]))
  const history = query.data?.data?.history ?? []

  return (
    <Card>
      <div className="flex flex-col gap-5">
        <p className="text-ink-muted">{t('privacy.consent.intro')}</p>
        <SubjectPicker type="customer" value={subjectId} onChange={setSubjectId} />
        {subjectId && query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {subjectId && query.data ? (
          <>
            <section className="flex flex-col gap-2" aria-labelledby="consent-current">
              <h2 id="consent-current" className="text-label text-ink">
                {t('privacy.consent.current')}
              </h2>
              <ul className="flex flex-col gap-1">
                {CHANNELS.map((value) => (
                  <li key={value} className="flex items-center justify-between gap-3">
                    <span className="text-ink">{t(`privacy.channels.${value}`)}</span>
                    <StatusBadge tone={current[value]?.granted ? 'success' : 'neutral'}>
                      {current[value] ? (current[value].granted ? t('privacy.consent.granted') : t('privacy.consent.withdrawn')) : t('privacy.consent.notAsked')}
                    </StatusBadge>
                  </li>
                ))}
              </ul>
            </section>
            {canManage ? (
              <section className="flex flex-col gap-3" aria-labelledby="consent-record">
                <h2 id="consent-record" className="text-label text-ink">
                  {t('privacy.consent.record')}
                </h2>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Select label={t('privacy.consent.channel')} value={channel} onChange={(event) => setChannel(event.target.value)} options={CHANNELS.map((value) => ({ value, label: t(`privacy.channels.${value}`) }))} />
                  <TextField label={t('privacy.consent.evidence')} help={t('privacy.consent.evidenceHelp')} value={evidence} onChange={(event) => setEvidence(event.target.value)} maxLength={500} />
                </div>
                {record.isError ? <Alert tone="danger" title={errorMessage(record.error)} /> : null}
                <div className="flex flex-wrap gap-2">
                  <Button loading={record.isPending && record.variables === false} onClick={() => record.mutate(false)}>
                    {t('privacy.consent.recordWithdrawal')}
                  </Button>
                  <Button variant="primary" loading={record.isPending && record.variables === true} onClick={() => record.mutate(true)}>
                    {t('privacy.consent.recordGrant')}
                  </Button>
                </div>
              </section>
            ) : null}
            <section className="flex flex-col gap-2" aria-labelledby="consent-history">
              <h2 id="consent-history" className="text-label text-ink">
                {t('privacy.consent.history')}
              </h2>
              {history.length === 0 ? <p className="text-caption text-ink-muted">{t('privacy.consent.noHistory')}</p> : null}
              <ul className="flex flex-col divide-y divide-border">
                {history.map((entry) => (
                  <li key={entry.id} className="flex flex-col gap-1 py-2">
                    <span className="text-ink">
                      {t(entry.granted ? 'privacy.consent.historyGranted' : 'privacy.consent.historyWithdrawn', { channel: t(`privacy.channels.${entry.channel}`) })}
                    </span>
                    <span className="text-caption text-ink-muted">
                      {[formatDateTime(entry.recorded_at, locale), entry.recorded_by?.name, t(`privacy.sources.${entry.source}`), entry.evidence].filter(Boolean).join(' · ')}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          </>
        ) : null}
      </div>
    </Card>
  )
}
