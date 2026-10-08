import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Pager } from '@/components/Pager'
import { Alert, Button, DataTable, Icon, Money, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useDebounced } from '@/lib/useDebounced'
import { ROLE_PATHS } from './partyData'

const PER_PAGE = 25
const STATUSES = ['active', 'archived']

/**
 * MD-01: customers or suppliers (`role`). Search (debounced) reads the
 * name, legal name, tax ID or phone digits; a tag filter; active or
 * archived; server pages. Columns show only what the API returns (RBAC-05).
 */
export default function Parties({ role }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const path = ROLE_PATHS[role]
  const [search, setSearch] = useState('')
  const [tag, setTag] = useState('')
  const [status, setStatus] = useState('active')
  const [page, setPage] = useState(1)
  const term = useDebounced(search.trim(), 300)
  const tagTerm = useDebounced(tag.trim(), 300)

  const params = new URLSearchParams({ role, status, per_page: String(PER_PAGE), page: String(page) })
  if (term) params.set('search', term)
  if (tagTerm) params.set('tag', tagTerm)
  const parties = useQuery({
    queryKey: ['parties', 'list', role, status, term, tagTerm, page],
    queryFn: () => api.get(`parties?${params}`),
    placeholderData: (previous) => previous,
  })
  const rows = parties.data?.data ?? []
  const filtered = Boolean(term || tagTerm)

  const columns = [
    {
      key: 'name',
      label: t('parties.columns.name'),
      render: (party) => (
        <span className="flex flex-col">
          <span className="font-medium text-ink">{party.name}</span>
          {party.legal_name && party.legal_name !== party.name ? <span className="text-caption text-ink-muted">{party.legal_name}</span> : null}
        </span>
      ),
    },
    { key: 'phone', label: t('parties.columns.phone'), render: (party) => <span className="tabular-nums">{party.phones?.[0]?.number ?? ''}</span> },
    { key: 'email', label: t('parties.columns.email'), render: (party) => party.emails?.[0]?.address ?? '' },
    { key: 'tags', label: t('parties.columns.tags'), render: (party) => (party.tags ?? []).join(', ') },
    {
      key: 'credit',
      label: t('parties.columns.creditLimit'),
      align: 'end',
      numeric: true,
      render: (party) => (party.credit_limit ? <Money amount={party.credit_limit.amount_minor} currency={party.credit_limit.currency} /> : ''),
    },
  ]

  return (
    <>
      <PageHeader
        title={t(`contacts.${path}.title`)}
        description={t(`contacts.${path}.description`)}
        actions={
          can('core.party.create') ? (
            <Button variant="primary" icon="plus" onClick={() => navigate(`/contacts/${path}/new`)}>
              {t(`parties.add.${role}`)}
            </Button>
          ) : null
        }
      />
      <div className="grid gap-3 sm:grid-cols-3">
        <TextField
          type="search"
          label={t('parties.search')}
          placeholder={t('parties.searchPlaceholder')}
          prefix={<Icon name="search" className="text-ink-muted" />}
          className="sm:col-span-2"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            setPage(1)
          }}
          autoComplete="off"
        />
        <TextField
          label={t('parties.filters.tag')}
          value={tag}
          onChange={(event) => {
            setTag(event.target.value)
            setPage(1)
          }}
          autoComplete="off"
        />
      </div>
      <Tabs
        items={STATUSES.map((value) => ({ value, label: t(`parties.status.${value}`) }))}
        value={status}
        onChange={(next) => {
          setStatus(next)
          setPage(1)
        }}
      />
      {parties.isError ? <Alert tone="danger" title={errorMessage(parties.error)} action={<Button onClick={() => parties.refetch()}>{t('common.retry')}</Button>} /> : null}
      <DataTable
        caption={t(`contacts.${path}.title`)}
        columns={columns}
        rows={rows}
        onRowClick={(party) => navigate(`/contacts/${path}/${party.id}`)}
        emptyText={parties.isPending ? t('common.loading') : filtered ? t('parties.emptyFiltered') : t(`parties.empty.${role}.${status}`)}
      />
      <Pager page={page} lastPage={parties.data?.meta?.last_page ?? 1} onPage={setPage} label={t('parties.pages')} />
    </>
  )
}
