import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Money, MoneyInput, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { fieldErrors, GIFT_CARD_STATUSES, GIFT_CARD_TONES, useLoyaltyCompany } from './loyaltyData'

export function CardStatus({ card }) {
  const { t } = useTranslation()
  if (card.expired) return <StatusBadge tone="neutral">{t('loyalty.giftCards.expired')}</StatusBadge>
  return <StatusBadge tone={GIFT_CARD_TONES[card.status]}>{t(`loyalty.giftCards.statuses.${card.status}`)}</StatusBadge>
}

/** LOY-20..LOY-23: gift cards and vouchers by their last four characters; issue, and check a balance by code. */
export default function GiftCards() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [issuing, setIssuing] = useState(false)
  const [checking, setChecking] = useState(false)

  const columns = [
    { key: 'last4', label: t('loyalty.giftCards.columns.card'), hideable: false, render: (row) => <span className="font-mono">•••• {row.last4}</span> },
    { key: 'kind', label: t('loyalty.giftCards.columns.kind'), render: (row) => t(`loyalty.giftCards.kinds.${row.kind}`) },
    { key: 'status', label: t('loyalty.giftCards.columns.status'), sortKey: 'status', render: (row) => <CardStatus card={row} /> },
    { key: 'initial', label: t('loyalty.giftCards.columns.initial'), render: (row) => <Money amount={row.initial.amount_minor} currency={row.initial.currency} /> },
    { key: 'balance', label: t('loyalty.giftCards.columns.balance'), sortKey: 'balance', render: (row) => <Money amount={row.balance.amount_minor} currency={row.balance.currency} /> },
    { key: 'expires_on', label: t('loyalty.giftCards.columns.expires'), sortKey: 'expires_on', render: (row) => row.expires_on ?? t('loyalty.giftCards.noExpiry') },
  ]
  const list = useServerList({ id: 'loyalty-gift-cards', endpoint: 'loyalty/gift-cards', queryKey: ['loyalty-gift-cards'], filters: { status: '' }, defaultSort: '-issued_at', columns })

  return (
    <>
      <PageHeader
        title={t('loyalty.giftCards.title')}
        description={t('loyalty.giftCards.description')}
        actions={
          <span className="flex gap-2">
            <Button icon="search" onClick={() => setChecking(true)}>{t('loyalty.giftCards.check')}</Button>
            {can('loyalty.gift_card.issue') ? <Button variant="primary" icon="plus" onClick={() => setIssuing(true)}>{t('loyalty.giftCards.issue')}</Button> : null}
          </span>
        }
      />
      <ListView
        list={list}
        title={t('loyalty.giftCards.title')}
        searchLabel={t('loyalty.giftCards.search')}
        filterFields={[{ name: 'status', label: t('loyalty.giftCards.columns.status'), options: [{ value: '', label: t('loyalty.giftCards.allStatuses') }, ...GIFT_CARD_STATUSES.map((status) => ({ value: status, label: t(`loyalty.giftCards.statuses.${status}`) }))] }]}
        emptyText={t('loyalty.giftCards.empty')}
        onRowClick={(row) => navigate(`/loyalty/gift-cards/${row.id}`)}
      />
      {issuing ? <IssueDialog onClose={() => setIssuing(false)} /> : null}
      {checking ? <CheckDialog onClose={() => setChecking(false)} onOpen={(card) => navigate(`/loyalty/gift-cards/${card.id}`)} /> : null}
    </>
  )
}

/** LOY-20: issue cards; their codes are shown once, here, to print or hand over. */
function IssueDialog({ onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const company = useLoyaltyCompany()
  const currency = company?.base_currency ?? 'KES'
  const [form, setForm] = useState({ kind: 'gift_card', amount_minor: '', count: '1', expires_on: '', active: false })
  const issue = useMutation({
    mutationFn: () => api.post('loyalty/gift-cards', { company_id: company?.id, kind: form.kind, currency, amount_minor: form.amount_minor, count: Number(form.count) || 1, expires_on: form.expires_on || null, active: form.active }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['loyalty-gift-cards'] }),
  })
  const errors = fieldErrors(issue.error)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const issued = issue.data?.data

  return (
    <Dialog
      open
      size="lg"
      title={t('loyalty.giftCards.issueTitle')}
      onClose={onClose}
      footer={issued ? <Button variant="primary" onClick={onClose}>{t('loyalty.giftCards.done')}</Button> : (
        <>
          <Button onClick={onClose}>{t('loyalty.cancel')}</Button>
          <Button variant="primary" loading={issue.isPending} disabled={!form.amount_minor} onClick={() => issue.mutate()}>{t('loyalty.giftCards.issueConfirm', { count: Number(form.count) || 1 })}</Button>
        </>
      )}
    >
      {issued ? (
        <div className="flex flex-col gap-3">
          <Alert tone="warning" title={t('loyalty.giftCards.codesOnce')} />
          <ul className="flex flex-col gap-1" aria-label={t('loyalty.giftCards.codes')}>
            {issued.map((card) => (
              <li key={card.id} className="flex justify-between gap-3 border-b border-border py-1">
                <span className="font-mono text-body text-ink">{card.code}</span>
                <Money amount={card.initial.amount_minor} currency={card.initial.currency} />
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          <Select label={t('loyalty.giftCards.columns.kind')} value={form.kind} onChange={(event) => set('kind')(event.target.value)} options={['gift_card', 'voucher'].map((kind) => ({ value: kind, label: t(`loyalty.giftCards.kinds.${kind}`) }))} />
          <MoneyInput label={t('loyalty.giftCards.value')} currency={currency} value={form.amount_minor} onChange={(value) => set('amount_minor')(value ?? '')} error={errors.amount_minor} />
          <TextField label={t('loyalty.giftCards.count')} inputMode="numeric" value={form.count} onChange={(event) => set('count')(event.target.value)} error={errors.count} />
          <TextField type="date" label={t('loyalty.giftCards.expiresOn')} value={form.expires_on} onChange={(event) => set('expires_on')(event.target.value)} error={errors.expires_on} />
          <Switch label={t('loyalty.giftCards.activeNow')} checked={form.active} onChange={set('active')} />
          {issue.isError && !Object.keys(errors).length ? <Alert tone="danger" title={errorMessage(issue.error)} /> : null}
        </div>
      )}
    </Dialog>
  )
}

/** LOY-23: a card's balance by its code. */
function CheckDialog({ onClose, onOpen }) {
  const { t } = useTranslation()
  const [code, setCode] = useState('')
  const lookup = useMutation({ mutationFn: () => api.post('loyalty/gift-cards/lookup', { code }) })
  const card = lookup.data?.data
  return (
    <Dialog
      open
      title={t('loyalty.giftCards.check')}
      onClose={onClose}
      footer={
        <>
          {card ? <Button onClick={() => onOpen(card)}>{t('loyalty.giftCards.openCard')}</Button> : null}
          <Button variant="primary" loading={lookup.isPending} disabled={code.trim().length < 4} onClick={() => lookup.mutate()}>{t('loyalty.giftCards.checkConfirm')}</Button>
        </>
      }
    >
      <TextField label={t('loyalty.giftCards.code')} autoComplete="off" value={code} onChange={(event) => setCode(event.target.value)} />
      {lookup.isError ? <Alert tone="danger" title={lookup.error.status === 404 ? t('loyalty.giftCards.notFoundCode') : errorMessage(lookup.error)} /> : null}
      {card ? (
        <div className="flex items-center justify-between gap-3" aria-live="polite">
          <span className="font-mono">•••• {card.last4}</span>
          <CardStatus card={card} />
          <Money amount={card.balance.amount_minor} currency={card.balance.currency} size="lg" />
        </div>
      ) : null}
    </Dialog>
  )
}
