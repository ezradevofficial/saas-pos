import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Icon, Money, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { useAmountText } from '@/pages/pos/useAmountText'
import { CardStatus } from './GiftCards'

/** LOY-21..LOY-23: one card: balance, ledger, selling an unsold card, blocking. */
export default function GiftCardDetail() {
  const { cardId } = useParams()
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const amount = useAmountText()
  const key = ['loyalty', 'gift-card', cardId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`loyalty/gift-cards/${cardId}`) })
  const [reference, setReference] = useState('')
  const act = useMutation({
    mutationFn: (action) => api.post(`loyalty/gift-cards/${cardId}/${action}`, action === 'sell' ? { reference: reference || null } : {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: key })
      queryClient.invalidateQueries({ queryKey: ['loyalty-gift-cards'] })
    },
  })
  const card = query.data?.data

  if (query.isError) return <Alert tone="danger" title={query.error.status === 404 ? t('loyalty.giftCards.notFound') : errorMessage(query.error)} />
  if (!card) return <p className="text-ink-muted">{t('common.loading')}</p>

  return (
    <>
      <Link to="/loyalty/gift-cards" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t('loyalty.giftCards.back')}
      </Link>
      <PageHeader
        title={`•••• ${card.last4}`}
        description={<span className="flex flex-wrap items-center gap-3"><CardStatus card={card} /><span>{t(`loyalty.giftCards.kinds.${card.kind}`)}</span></span>}
        actions={can('loyalty.gift_card.block') ? (
          card.status === 'blocked'
            ? <Button loading={act.isPending} onClick={() => act.mutate('unblock')}>{t('loyalty.giftCards.unblock')}</Button>
            : <Button variant="danger" loading={act.isPending} onClick={() => act.mutate('block')}>{t('loyalty.giftCards.block')}</Button>
        ) : null}
      />
      {act.isError ? <Alert tone="danger" title={errorMessage(act.error)} /> : null}
      <div className="grid gap-4 md:grid-cols-3">
        <Card title={t('loyalty.giftCards.columns.balance')}><Money amount={card.balance.amount_minor} currency={card.currency} size="lg" /></Card>
        <Card title={t('loyalty.giftCards.columns.initial')}><Money amount={card.initial.amount_minor} currency={card.currency} /></Card>
        <Card title={t('loyalty.giftCards.columns.expires')}><span className="text-body text-ink">{card.expires_on ?? t('loyalty.giftCards.noExpiry')}</span></Card>
      </div>
      {card.status === 'inactive' && can('loyalty.gift_card.sell') ? (
        <Card title={t('loyalty.giftCards.sellTitle')} subtitle={t('loyalty.giftCards.sellHelp')}>
          <div className="flex flex-wrap items-end gap-3">
            <TextField className="min-w-60" label={t('loyalty.giftCards.paymentReference')} value={reference} onChange={(event) => setReference(event.target.value)} />
            <Button variant="pay" loading={act.isPending} onClick={() => act.mutate('sell')}>{t('loyalty.giftCards.sell', { amount: amount(card.initial) })}</Button>
          </div>
        </Card>
      ) : null}
      <Card title={t('loyalty.giftCards.ledger')}>
        <DataTable
          caption={t('loyalty.giftCards.ledger')}
          rows={card.entries ?? []}
          emptyText={t('loyalty.giftCards.noEntries')}
          columns={[
            { key: 'date', label: t('loyalty.credit.entry.date'), render: (row) => <span className="tabular-nums">{formatCompanyTime(row.occurred_at, locale, companyOf(card.company_id))}</span> },
            { key: 'kind', label: t('loyalty.credit.entry.kind'), render: (row) => t(`loyalty.giftCards.entries.${row.kind}`) },
            { key: 'reference', label: t('loyalty.credit.entry.reference'), render: (row) => row.reference ?? '' },
            { key: 'amount', label: t('loyalty.credit.entry.amount'), render: (row) => <Money amount={row.amount.amount_minor} currency={row.amount.currency} /> },
            { key: 'flags', label: t('loyalty.credit.entry.flags'), render: (row) => (row.flags ?? []).map((flag) => <StatusBadge key={flag.code} tone="warning">{t(`loyalty.flags.${flag.code}`, { defaultValue: flag.code })}</StatusBadge>) },
          ]}
        />
      </Card>
    </>
  )
}
