import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Money, Select, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { ACCOUNT_TONES, fieldErrors, useLoyaltyCompany } from './loyaltyData'

/** A balance against its limit: "KES 3,000.00 of KES 10,000.00". */
export function BalanceOfLimit({ account }) {
  const { t } = useTranslation()
  if (!account.balance) return null
  return (
    <span className="inline-flex flex-wrap items-baseline gap-1">
      <Money amount={account.balance.amount_minor} currency={account.balance.currency} />
      {account.credit_limit ? (
        <span className="text-caption text-ink-muted">
          {t('loyalty.credit.ofLimit')} <Money amount={account.credit_limit.amount_minor} currency={account.credit_limit.currency} />
        </span>
      ) : (
        <span className="text-caption text-ink-muted">{t('loyalty.credit.noLimit')}</span>
      )}
    </span>
  )
}

/** LOY-14: customers' credit accounts, largest balance first, and opening one. */
export default function CreditAccounts() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [opening, setOpening] = useState(false)

  const columns = [
    { key: 'customer', label: t('loyalty.credit.columns.customer'), hideable: false, render: (row) => <span className="font-medium text-ink">{row.party?.name}</span> },
    { key: 'balance', label: t('loyalty.credit.columns.balance'), sortKey: 'balance', render: (row) => <BalanceOfLimit account={row} /> },
    { key: 'available', label: t('loyalty.credit.columns.available'), render: (row) => (row.available ? <Money amount={row.available.amount_minor} currency={row.available.currency} /> : <span className="text-ink-muted">—</span>) },
    { key: 'status', label: t('loyalty.credit.columns.status'), sortKey: 'status', render: (row) => <StatusBadge tone={ACCOUNT_TONES[row.status]}>{t(`loyalty.credit.statuses.${row.status}`)}</StatusBadge> },
    { key: 'company', label: t('loyalty.credit.columns.company'), defaultHidden: true, render: (row) => row.company?.name ?? '' },
  ]
  const list = useServerList({ id: 'loyalty-accounts', endpoint: 'loyalty/accounts', queryKey: ['loyalty-accounts'], filters: { status: '' }, defaultSort: '-balance', columns })

  return (
    <>
      <PageHeader
        title={t('loyalty.credit.title')}
        description={t('loyalty.credit.description')}
        actions={can('loyalty.credit.manage') ? <Button variant="primary" icon="plus" onClick={() => setOpening(true)}>{t('loyalty.credit.open')}</Button> : null}
      />
      <ListView
        list={list}
        title={t('loyalty.credit.title')}
        searchLabel={t('loyalty.credit.search')}
        filterFields={[{ name: 'status', label: t('loyalty.credit.columns.status'), options: [{ value: '', label: t('loyalty.credit.allStatuses') }, { value: 'open', label: t('loyalty.credit.statuses.open') }, { value: 'closed', label: t('loyalty.credit.statuses.closed') }] }]}
        emptyText={t('loyalty.credit.empty')}
        onRowClick={(row) => navigate(`/loyalty/credit/${row.id}`)}
      />
      {opening ? <OpenAccountDialog onClose={() => setOpening(false)} onOpened={(account) => navigate(`/loyalty/credit/${account.id}`)} /> : null}
    </>
  )
}

/** LOY-14: open an account for a customer of the company (the limit stays the core party's). */
export function OpenAccountDialog({ onClose, onOpened, partyId = null }) {
  const { t } = useTranslation()
  const company = useLoyaltyCompany()
  const customers = useQuery({ queryKey: ['loyalty', 'customers'], queryFn: () => api.get('parties?role=customer&per_page=200'), enabled: !partyId })
  const [party, setParty] = useState(partyId ?? '')
  const open = useMutation({ mutationFn: () => api.post('loyalty/accounts', { party_id: party, company_id: company?.id }), onSuccess: (response) => onOpened(response.data) })
  const errors = fieldErrors(open.error)

  return (
    <Dialog
      open
      title={t('loyalty.credit.openTitle', { company: company?.name ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('loyalty.cancel')}</Button>
          <Button variant="primary" loading={open.isPending} disabled={!party} onClick={() => open.mutate()}>{t('loyalty.credit.open')}</Button>
        </>
      }
    >
      <p className="text-body text-ink-muted">{t('loyalty.credit.openHelp')}</p>
      {!partyId ? (
        <Select label={t('loyalty.credit.customer')} placeholder={t('loyalty.credit.chooseCustomer')} value={party} onChange={(event) => setParty(event.target.value)} error={errors.party_id}
          options={(customers.data?.data ?? []).map((row) => ({ value: row.id, label: row.name }))} />
      ) : null}
      {open.isError && !errors.party_id ? <Alert tone="danger" title={errorMessage(open.error)} /> : null}
    </Dialog>
  )
}
