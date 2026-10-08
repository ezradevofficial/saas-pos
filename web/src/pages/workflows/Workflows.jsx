import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Select, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies } from '@/layouts/companySelection'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { useDocumentTypes } from './workflowData'

const ALL = 'all'

/** Create a flow for a document type in a company, or for every company (WF-02). It starts from the type's default. */
function CreateDialog({ types, companies, canAll, onClose }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const companyOptions = [...(canAll ? [{ value: ALL, label: t('workflows.allCompanies') }] : []), ...companies.map((company) => ({ value: company.id, label: company.name }))]
  const [type, setType] = useState(types[0]?.key ?? '')
  const [company, setCompany] = useState(companyOptions[0]?.value ?? '')
  const create = useMutation({
    mutationFn: () => api.post('workflows', { document_type: type, company_id: company === ALL ? null : company }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['workflows'] })
      navigate(`/settings/workflows/${response.data.id}`)
    },
  })

  return (
    <Dialog
      open
      title={t('workflows.create.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" disabled={!type || !company} loading={create.isPending} onClick={() => create.mutate()}>
            {t('workflows.create.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {create.isError ? <Alert tone="danger" title={errorMessage(create.error)} /> : null}
        <p>{t('workflows.create.body')}</p>
        <Select label={t('workflows.create.type')} options={types.map((one) => ({ value: one.key, label: one.label }))} value={type} onChange={(event) => setType(event.target.value)} required />
        <Select label={t('workflows.create.company')} options={companyOptions} value={company} onChange={(event) => setCompany(event.target.value)} required />
      </div>
    </Dialog>
  )
}

/**
 * Workflows (WF-02, spec 6.4): one flow per document type and company,
 * or one for every company without its own. Shows the live version and
 * any draft; a row opens the builder.
 */
export default function Workflows() {
  const { t } = useTranslation()
  const locale = useLocale()
  const timeZone = useTimeZone()
  const navigate = useNavigate()
  const { can, tenantWide } = usePermissions()
  const { types } = useDocumentTypes()
  const { companies } = useCompanies()
  const [creating, setCreating] = useState(false)
  const canCreate = can('core.workflow.edit')

  const columns = [
    { key: 'document_type', label: t('workflows.columns.documentType'), sortKey: 'document_type', hideable: false, render: (row) => <span className="font-medium text-ink">{row.document_type_label}</span> },
    { key: 'company', label: t('workflows.columns.company'), sortKey: 'company', render: (row) => row.company_name ?? t('workflows.allCompanies') },
    {
      key: 'published',
      label: t('workflows.columns.live'),
      render: (row) =>
        row.published ? <StatusBadge tone="success">{t('workflows.versionShort', { version: row.published.version })}</StatusBadge> : <span className="text-ink-muted">{t('workflows.notPublished')}</span>,
    },
    {
      key: 'draft',
      label: t('workflows.columns.draft'),
      render: (row) =>
        row.draft ? <StatusBadge tone="info">{t('workflows.header.draft', { version: row.draft.version })}</StatusBadge> : <span className="text-ink-muted">{t('workflows.noDraft')}</span>,
    },
    { key: 'updated_at', label: t('workflows.columns.updated'), sortKey: 'updated_at', render: (row) => (row.updated_at ? formatWhen(row.updated_at, locale, timeZone) : '') },
  ]
  const list = useServerList({ id: 'workflows', endpoint: 'workflows', queryKey: ['workflows'], filters: { type: '', company: '' }, columns })

  return (
    <>
      <PageHeader
        title={t('workflows.title')}
        description={t('workflows.description')}
        actions={
          canCreate ? (
            <Button variant="primary" icon="plus" disabled={types.length === 0} onClick={() => setCreating(true)}>
              {t('workflows.create.open')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('workflows.title')}
        searchPlaceholder={t('workflows.searchPlaceholder')}
        filters={
          <>
            <Select
              label={t('workflows.filters.type')}
              options={[{ value: '', label: t('workflows.filters.allTypes') }, ...types.map((one) => ({ value: one.key, label: one.label }))]}
              value={list.filters.type}
              onChange={(event) => list.setFilter('type', event.target.value)}
              className="w-full sm:w-56"
            />
            {companies.length > 1 ? (
              <Select
                label={t('workflows.filters.company')}
                options={[{ value: '', label: t('workflows.allCompanies') }, ...companies.map((company) => ({ value: company.id, label: company.name }))]}
                value={list.filters.company}
                onChange={(event) => list.setFilter('company', event.target.value)}
                className="w-full sm:w-56"
              />
            ) : null}
          </>
        }
        emptyText={types.length === 0 ? t('workflows.emptyNoTypes') : t('workflows.empty')}
        onRowClick={(row) => navigate(`/settings/workflows/${row.id}`)}
      />
      {creating ? <CreateDialog types={types} companies={companies} canAll={tenantWide('core.workflow.edit')} onClose={() => setCreating(false)} /> : null}
    </>
  )
}
