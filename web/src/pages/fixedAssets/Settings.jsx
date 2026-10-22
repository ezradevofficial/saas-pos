import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Card, DataTable, Select, Switch } from '@/components/ds'
import { ResearchStatus, ResearchedNotice } from '@/components/ResearchedNotice'
import { useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { faKey, SETTINGS_EDIT, useSettings } from './fixedAssetsData'

/**
 * FA-01, FA-20: a company's depreciation convention (locked once a run is
 * posted) and the tax book, with the country pack's classes and what the
 * owner must confirm before the tax book can be used.
 */
export default function Settings() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { company } = useCompanySelection()
  const settings = useSettings(company?.id)
  const save = useMutation({
    mutationFn: (body) => api.put(`fixed-assets/companies/${company.id}/settings`, body),
    onSuccess: async () => {
      toast.success(t('fixedAssets.settings.saved'))
      await queryClient.invalidateQueries({ queryKey: faKey })
    },
  })
  const edit = can(SETTINGS_EDIT)

  return (
    <>
      <PageHeader title={t('fixedAssets.settings.title')} description={t('fixedAssets.settings.description')} />
      <div className="flex flex-col gap-5">
        {!company ? <Alert tone="info" title={t('fixedAssets.chooseCompany')} /> : null}
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        {settings ? (
          <>
            <Card title={t('fixedAssets.settings.depreciation')}>
              <div className="flex flex-col gap-4 sm:max-w-md">
                <Select
                  label={t('fixedAssets.runs.convention')}
                  help={settings.convention_locked ? t('fixedAssets.settings.conventionLocked') : t('fixedAssets.settings.conventionHelp')}
                  options={['days', 'mid_month'].map((value) => ({ value, label: t(`fixedAssets.conventions.${value}`) }))}
                  value={settings.convention}
                  disabled={!edit || settings.convention_locked}
                  onChange={(event) => save.mutate({ convention: event.target.value })}
                />
              </div>
            </Card>
            <Card title={t('fixedAssets.settings.taxBook')} subtitle={t('fixedAssets.settings.taxBookHelp')}>
              <div className="flex flex-col gap-4">
                <Switch label={t('fixedAssets.settings.taxBookOn')} checked={settings.tax_book} disabled={!edit} onChange={(on) => save.mutate({ tax_book: Boolean(on) })} />
                {settings.tax_unconfirmed?.length ? <Alert tone="warning" title={t('fixedAssets.settings.unconfirmed')} /> : null}
                {/* CP-01: classes from public research; low confidence shows the stronger notice. */}
                {(settings.tax_classes ?? []).some((row) => row.status === 'researched') ? (
                  <ResearchedNotice low={(settings.tax_classes ?? []).some((row) => row.status === 'researched' && row.confidence === 'low')} />
                ) : null}
                <DataTable
                  caption={t('fixedAssets.settings.classes')}
                  columns={[
                    { key: 'key', label: t('fixedAssets.categories.taxClass'), render: (row) => t(`fixedAssets.taxClasses.${row.key}`, { defaultValue: row.key }) },
                    { key: 'method', label: t('fixedAssets.fields.method'), render: (row) => (row.method ? t(`fixedAssets.taxMethods.${row.method}`) : t('fixedAssets.settings.needsConfirmation')) },
                    {
                      key: 'rate',
                      label: t('fixedAssets.settings.rate'),
                      align: 'end',
                      render: (row) => (row.rate ? (row.first_year_rate ? t('fixedAssets.settings.firstYearRate', { first: row.first_year_rate, rate: row.rate }) : `${row.rate}%`) : t('fixedAssets.settings.needsConfirmation')),
                    },
                    {
                      key: 'status',
                      label: t('fixedAssets.settings.status'),
                      render: (row) => <ResearchStatus status={row.status ?? (row.needs_confirmation ? 'needs_confirmation' : 'confirmed')} confidence={row.confidence} researchedOn={row.researched_on} source={row.source ?? []} />,
                    },
                  ]}
                  rows={(settings.tax_classes ?? []).map((row) => ({ ...row, id: `${row.key}:${row.effective_from ?? ''}` }))}
                  emptyText={t('fixedAssets.settings.noClasses')}
                />
                {settings.tax_todo?.length ? (
                  <div className="flex flex-col gap-2">
                    <p className="text-label text-ink">{t('fixedAssets.settings.toConfirm')}</p>
                    <ul className="flex list-disc flex-col gap-1 pl-5 text-body text-ink-muted">
                      {settings.tax_todo.map((line) => (
                        <li key={line}>{line}</li>
                      ))}
                    </ul>
                  </div>
                ) : null}
              </div>
            </Card>
          </>
        ) : null}
      </div>
    </>
  )
}
