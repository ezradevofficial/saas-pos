import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView, Money, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList, useTypedText } from '@/lib/useServerList'
import { ROLE_PATHS } from './partyData'

const STATUSES = ['active', 'archived']

/** The tag filter: typed, so the URL (and the API) follow once typing stops. */
function TagField({ label, value, onChange }) {
  const [tag, setTag] = useTypedText(value, (next) => onChange(next, { replace: true }))
  return <TextField label={label} className="w-full" value={tag} onChange={(event) => setTag(event.target.value)} autoComplete="off" />
}

/**
 * MD-01: customers or suppliers (`role`). Search (debounced) reads the
 * name, legal name, tax ID or phone digits; a tag filter; active or
 * archived; sort, server pages, columns and export (EXP-01, LAY-04).
 * Columns show only what the API returns (RBAC-05).
 */
export default function Parties({ role }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const path = ROLE_PATHS[role]

  const columns = [
    {
      key: 'name',
      label: t('parties.columns.name'),
      sortKey: 'name',
      hideable: false,
      render: (party) => (
        <span className="flex flex-col">
          <span className="font-medium text-ink">{party.name}</span>
          {party.legal_name && party.legal_name !== party.name ? <span className="text-caption text-ink-muted">{party.legal_name}</span> : null}
        </span>
      ),
    },
    { key: 'phone', label: t('parties.columns.phone'), exportKey: 'phones', render: (party) => <span className="tabular-nums">{party.phones?.[0]?.number ?? ''}</span> },
    { key: 'email', label: t('parties.columns.email'), exportKey: 'emails', render: (party) => party.emails?.[0]?.address ?? '' },
    { key: 'tax_id', label: t('parties.columns.taxId'), sortKey: 'tax_id', defaultHidden: true, render: (party) => party.tax_id ?? '' },
    { key: 'tags', label: t('parties.columns.tags'), render: (party) => (party.tags ?? []).join(', ') },
    {
      key: 'credit',
      label: t('parties.columns.creditLimit'),
      exportKey: 'credit_limit',
      align: 'end',
      numeric: true,
      render: (party) => (party.credit_limit ? <Money amount={party.credit_limit.amount_minor} currency={party.credit_limit.currency} /> : ''),
    },
  ]

  const list = useServerList({
    id: path,
    endpoint: 'parties',
    queryKey: ['parties'],
    params: { role },
    filters: { status: 'active', tag: '' },
    columns,
  })
  const { status } = list.filters
  const filtered = Boolean(list.term || list.filters.tag)

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
      <Tabs
        items={STATUSES.map((value) => ({ value, label: t(`parties.status.${value}`) }))}
        value={status}
        onChange={(next) => list.setFilter('status', next)}
      />
      <ListView
        list={list}
        title={t(`contacts.${path}.title`)}
        searchLabel={t('parties.search')}
        searchPlaceholder={t('parties.searchPlaceholder')}
        filterFields={[
          {
            name: 'tag',
            label: t('parties.filters.tag'),
            render: ({ label, value, onChange }) => <TagField label={label} value={value} onChange={onChange} />,
          },
        ]}
        onRowClick={(party) => navigate(`/contacts/${path}/${party.id}`)}
        emptyText={filtered ? t('parties.emptyFiltered') : t(`parties.empty.${role}.${status}`)}
      />
    </>
  )
}
