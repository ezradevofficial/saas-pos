import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Button, ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies } from '@/layouts/companySelection'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { STATUS_TONES, useAutomationCatalogue, useAutomationRights } from './automationData'
import { TemplatesDialog } from './TemplatesDialog'

const STATUSES = ['active', 'enabled', 'disabled', 'archived', 'all']

/** A rule's status as a dot and a word. */
export function RuleStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{t(`automation.statuses.${status}`, { defaultValue: status })}</StatusBadge>
}

/**
 * Automation rules (AUTO-01..AUTO-07): every rule the user may see, with
 * its document type, trigger in words, company and status. Create one
 * from scratch or from a template; a row opens the editor.
 */
export default function AutomationRules() {
  const { t } = useTranslation()
  const locale = useLocale()
  const timeZone = useTimeZone()
  const navigate = useNavigate()
  const { types } = useAutomationCatalogue()
  const { companies } = useCompanies()
  const { canEditSomewhere, canEditAll } = useAutomationRights(null)
  const [templates, setTemplates] = useState(false)

  const columns = [
    { key: 'name', label: t('automation.columns.name'), sortKey: 'name', hideable: false, render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'document_type', label: t('automation.columns.documentType'), sortKey: 'document_type', render: (row) => row.document_type_label },
    { key: 'trigger', label: t('automation.columns.trigger'), sortKey: 'trigger_type', wrap: true, render: (row) => row.trigger_description ?? t(`automation.triggers.${row.trigger?.type}`, { defaultValue: '' }) },
    { key: 'company', label: t('automation.columns.company'), render: (row) => row.company_name ?? t('automation.allCompanies') },
    { key: 'status', label: t('automation.columns.status'), sortKey: 'enabled', render: (row) => <RuleStatus status={row.status} /> },
    { key: 'updated_at', label: t('automation.columns.updated'), sortKey: 'updated_at', render: (row) => (row.updated_at ? formatWhen(row.updated_at, locale, timeZone) : '') },
  ]
  const list = useServerList({ id: 'automation-rules', endpoint: 'automation-rules', queryKey: ['automation-rules'], filters: { status: 'active', type: '', company: '' }, columns })

  return (
    <>
      <PageHeader
        title={t('automation.title')}
        description={t('automation.description')}
        actions={
          <>
            <Button icon="history" onClick={() => navigate('/settings/automation-runs')}>
              {t('automation.runs.open')}
            </Button>
            {canEditSomewhere ? (
              <>
                <Button icon="templates" disabled={types.length === 0} onClick={() => setTemplates(true)}>
                  {t('automation.templates.open')}
                </Button>
                <Button variant="primary" icon="plus" disabled={types.length === 0} onClick={() => navigate('/settings/automation-rules/new')}>
                  {t('automation.create')}
                </Button>
              </>
            ) : null}
          </>
        }
      />
      <ListView
        list={list}
        title={t('automation.title')}
        searchPlaceholder={t('automation.searchPlaceholder')}
        filterFields={[
          {
            name: 'status',
            label: t('automation.filters.status'),
            options: STATUSES.map((status) => ({ value: status, label: t(`automation.statusFilter.${status}`) })),
          },
          {
            name: 'type',
            label: t('automation.filters.type'),
            options: [{ value: '', label: t('automation.filters.allTypes') }, ...types.map((one) => ({ value: one.key, label: one.label }))],
          },
          ...(companies.length > 1
            ? [
                {
                  name: 'company',
                  label: t('automation.filters.company'),
                  options: [{ value: '', label: t('automation.allCompanies') }, ...companies.map((company) => ({ value: company.id, label: company.name }))],
                },
              ]
            : []),
        ]}
        emptyText={t('automation.empty')}
        onRowClick={(row) => navigate(`/settings/automation-rules/${row.id}`)}
      />
      {templates ? <TemplatesDialog types={types} companies={companies} canAll={canEditAll} onClose={() => setTemplates(false)} /> : null}
    </>
  )
}
