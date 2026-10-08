import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Checkbox, Dialog, ListView, Select, StatusBadge, TextField } from '@/components/ds'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useServerList } from '@/lib/useServerList'
import { companyScope, useTenantCurrencies } from '../finance/useSettingsCompany'

function AddPriceListDialog({ company, currencies, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [values, setValues] = useState({ name: '', currency: company.base_currency, tax_inclusive: true, is_default: false })

  const mutation = useMutation({
    mutationFn: () => api.post(`companies/${company.id}/price-lists`, { ...values, name: values.name.trim() }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['price-lists', company.id] })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['name', 'currency'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('taxes.priceLists.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('taxes.priceLists.add')}
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
        <TextField label={t('taxes.priceLists.name')} value={values.name} onChange={(event) => setValues((v) => ({ ...v, name: event.target.value }))} error={errors.fields.name} required />
        <Select
          label={t('taxes.priceLists.currency')}
          options={currencies.map((currency) => ({ value: currency.code, label: currency.code }))}
          value={values.currency}
          onChange={(event) => setValues((v) => ({ ...v, currency: event.target.value }))}
          error={errors.fields.currency}
          required
        />
        <Checkbox
          label={t('taxes.priceLists.inclusive')}
          help={t('taxes.priceLists.inclusiveHelp')}
          checked={values.tax_inclusive}
          onChange={(event) => setValues((v) => ({ ...v, tax_inclusive: event.target.checked }))}
        />
        <Checkbox
          label={t('taxes.priceLists.makeDefault')}
          help={t('taxes.priceLists.makeDefaultHelp')}
          checked={values.is_default}
          onChange={(event) => setValues((v) => ({ ...v, is_default: event.target.checked }))}
        />
      </form>
    </Dialog>
  )
}

/**
 * MD-03: the company's price lists, tax-inclusive or exclusive, one
 * default per currency; search, sort, pages, columns and export (EXP-01,
 * LAY-04). A row opens the list's prices (PriceListDetail).
 */
export function PriceLists({ company }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const canEdit = can('core.price_list.edit', companyScope(company))
  const currencies = useTenantCurrencies()
  const [adding, setAdding] = useState(false)
  const navigate = useNavigate()

  const columns = [
    { key: 'name', label: t('taxes.priceLists.name'), sortKey: 'name', hideable: false, render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'currency', label: t('taxes.priceLists.currency'), sortKey: 'currency' },
    {
      key: 'tax',
      label: t('taxes.priceLists.prices'),
      sortKey: 'prices',
      exportKey: 'prices',
      render: (row) => (row.tax_inclusive ? t('taxes.priceLists.includeTax') : t('taxes.priceLists.excludeTax')),
    },
    {
      key: 'default',
      label: t('taxes.priceLists.default'),
      sortKey: 'default',
      render: (row) => (row.is_default ? <StatusBadge tone="info">{t('taxes.priceLists.defaultFor', { currency: row.currency })}</StatusBadge> : null),
    },
  ]
  const list = useServerList({ id: 'price-lists', endpoint: `companies/${company.id}/price-lists`, queryKey: ['price-lists', company.id], columns })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-ink-muted">{t('taxes.priceLists.intro')}</p>
        {canEdit ? (
          <Button icon="plus" onClick={() => setAdding(true)}>
            {t('taxes.priceLists.add')}
          </Button>
        ) : null}
      </div>
      <ListView
        list={list}
        title={t('taxes.tabs.priceLists')}
        onRowClick={(row) => navigate(`/settings/taxes/price-lists/${row.id}`)}
        searchPlaceholder={t('taxes.priceLists.searchPlaceholder')}
        emptyText={list.term ? t('taxes.priceLists.emptyFiltered') : t('taxes.priceLists.empty')}
      />
      {adding ? <AddPriceListDialog company={company} currencies={currencies.active} onClose={() => setAdding(false)} /> : null}
    </div>
  )
}
