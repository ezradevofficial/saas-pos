import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Select, TextField } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useServerList } from '@/lib/useServerList'
import { companyScope } from '../finance/useSettingsCompany'

/** Add a tax category: shared or this company's, following the items sharing mode (TEN-08), with this company's default code. */
function AddCategoryDialog({ company, shared, codes, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [name, setName] = useState('')
  const [code, setCode] = useState('')

  const mutation = useMutation({
    mutationFn: () =>
      api.post('tax-categories', {
        name: name.trim(),
        ...(shared ? {} : { company_id: company.id }),
        ...(code ? { codes: [{ company_id: company.id, tax_code_id: code }] } : {}),
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['tax-categories'] })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['name'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('taxes.categories.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('taxes.categories.add')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
      >
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(mutation.error)} />
          </div>
        ) : null}
        <p>{shared ? t('taxes.categories.sharedNote') : t('taxes.categories.companyNote', { name: company.name })}</p>
        <TextField label={t('taxes.categories.name')} value={name} onChange={(event) => setName(event.target.value)} error={errors.fields.name} required />
        <Select
          label={t('taxes.categories.defaultCode', { name: company.name })}
          options={[{ value: '', label: t('taxes.categories.noCode') }, ...codes.map((entry) => ({ value: entry.id, label: `${entry.code} · ${entry.name}` }))]}
          value={code}
          onChange={(event) => setCode(event.target.value)}
        />
      </form>
    </Dialog>
  )
}

/**
 * MD-03: tax categories (shared, or a company's) the user reaches, and the
 * default tax code each has in this company; search, sort, pages, columns
 * and export (EXP-01, LAY-04). The API lists every reached company's
 * categories (it has no company filter), so "Used by" names the company.
 */
export function TaxCategories({ company }) {
  const { t } = useTranslation()
  const { can, tenantWide } = usePermissions()
  const [adding, setAdding] = useState(false)
  const { companies } = useCompanies()
  const sharing = useQuery({ queryKey: ['master-data-settings'], queryFn: () => api.get('master-data/settings') })
  const codes = useQuery({ queryKey: ['tax-codes', company.id], queryFn: () => api.get(`companies/${company.id}/tax-codes?per_page=200`) })
  const shared = (sharing.data?.data ?? []).find((entry) => entry.data_type === 'items')?.mode !== 'per_company'
  const canAdd = sharing.isSuccess && (shared ? tenantWide('core.tax.edit') : can('core.tax.edit', companyScope(company)))

  const companyName = (id) => (id === company.id ? company.name : (companies.find((entry) => entry.id === id)?.name ?? t('taxes.categories.oneCompany')))
  const columns = [
    { key: 'name', label: t('taxes.categories.name'), sortKey: 'name', hideable: false, render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'scope', label: t('taxes.categories.scope'), sortKey: 'scope', render: (row) => (row.shared ? t('taxes.categories.shared') : companyName(row.company_id)) },
    {
      key: 'code',
      label: t('taxes.categories.codeHere', { name: company.name }),
      exportKey: 'codes',
      render: (row) => {
        const entry = row.codes.find((item) => item.company_id === company.id)
        return entry?.code ? <span className="font-mono text-caption text-ink">{entry.code}</span> : <span className="text-ink-muted">{t('taxes.categories.noCode')}</span>
      },
    },
  ]
  const list = useServerList({ id: 'tax-categories', endpoint: 'tax-categories', queryKey: ['tax-categories'], columns })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-ink-muted">{t('taxes.categories.intro')}</p>
        {canAdd ? (
          <Button icon="plus" onClick={() => setAdding(true)}>
            {t('taxes.categories.add')}
          </Button>
        ) : null}
      </div>
      <ListView
        list={list}
        title={t('taxes.tabs.categories')}
        searchPlaceholder={t('taxes.categories.searchPlaceholder')}
        emptyText={list.term ? t('taxes.categories.emptyFiltered') : t('taxes.categories.empty')}
      />
      {adding ? (
        <AddCategoryDialog
          company={company}
          shared={shared}
          codes={(codes.data?.data ?? []).filter((code) => !code.archived_at)}
          onClose={() => setAdding(false)}
        />
      ) : null}
    </div>
  )
}
