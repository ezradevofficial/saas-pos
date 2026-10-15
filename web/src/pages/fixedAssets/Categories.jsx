import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Select } from '@/components/ds'
import { useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { ItemPicker } from '@/pages/inventory/InventoryParts'
import { CATEGORY_MANAGE, faKey, METHODS, useSettings } from './fixedAssetsData'
import { FormDialog } from './FormDialog'

/**
 * FA-02, FA-03, FA-21: a company's asset categories with their default
 * depreciation terms (accounts come from Accounting's posting rules, keyed
 * by category), and the items flagged as assets with their category.
 */
export default function Categories() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { company } = useCompanySelection()
  const settings = useSettings(company?.id)
  const [editing, setEditing] = useState(null)
  const [item, setItem] = useState(null)
  const [itemCategory, setItemCategory] = useState('')
  const categories = useQuery({ queryKey: [...faKey, 'categories', 'all', company?.id], queryFn: () => api.get(`fixed-assets/categories?status=all&per_page=200${company ? `&company=${company.id}` : ''}`) })
  const flagged = useQuery({ queryKey: [...faKey, 'item-categories', company?.id], queryFn: () => api.get(`fixed-assets/item-categories?per_page=200${company ? `&company=${company.id}` : ''}`) })
  const manage = can(CATEGORY_MANAGE)
  const refresh = () => queryClient.invalidateQueries({ queryKey: faKey })
  const archive = useMutation({ mutationFn: (row) => api.post(`fixed-assets/categories/${row.id}/${row.archived_at ? 'restore' : 'archive'}`), onSuccess: refresh })
  const flag = useMutation({
    mutationFn: () => api.post('fixed-assets/item-categories', { company_id: company.id, item_id: item.id, category_id: itemCategory }),
    onSuccess: () => {
      setItem(null)
      toast.success(t('fixedAssets.categories.flagged'))
      refresh()
    },
  })
  const unflag = useMutation({ mutationFn: (id) => api.post(`fixed-assets/item-categories/${id}/archive`), onSuccess: refresh })
  const taxClasses = [...new Set((settings?.tax_classes ?? []).map((row) => row.key))].map((key) => ({ value: key, label: t(`fixedAssets.taxClasses.${key}`, { defaultValue: key }) }))
  const rows = categories.data?.data ?? []

  const columns = [
    { key: 'code', label: t('fixedAssets.categories.code'), render: (row) => <span className="font-medium tabular-nums">{row.code}</span> },
    { key: 'name', label: t('fixedAssets.categories.name'), render: (row) => row.name },
    { key: 'method', label: t('fixedAssets.fields.method'), render: (row) => t(`fixedAssets.methods.${row.method}`) },
    { key: 'life', label: t('fixedAssets.fields.usefulLife'), align: 'end', render: (row) => (row.useful_life_months ? <span className="tabular-nums">{row.useful_life_months}</span> : '—') },
    { key: 'residual', label: t('fixedAssets.categories.residualPercent'), align: 'end', render: (row) => <span className="tabular-nums">{Number(row.residual_percent)}%</span> },
    { key: 'tax', label: t('fixedAssets.categories.taxClass'), render: (row) => (row.tax_class ? t(`fixedAssets.taxClasses.${row.tax_class}`, { defaultValue: row.tax_class }) : '—') },
    {
      key: 'actions',
      label: t('fixedAssets.categories.actions'),
      align: 'end',
      render: (row) =>
        manage ? (
          <span className="flex justify-end gap-2">
            <Button variant="ghost" icon="edit" onClick={() => setEditing(row)}>
              {t('fixedAssets.categories.edit')}
            </Button>
            <Button variant="ghost" icon={row.archived_at ? 'restore' : 'archive'} onClick={() => archive.mutate(row)}>
              {row.archived_at ? t('fixedAssets.categories.restore') : t('fixedAssets.categories.archive')}
            </Button>
          </span>
        ) : null,
    },
  ]

  const fields = [
    { name: 'code', label: t('fixedAssets.categories.code'), required: true },
    { name: 'name', label: t('fixedAssets.categories.name'), required: true },
    { name: 'method', label: t('fixedAssets.fields.method'), type: 'select', required: true, options: METHODS.map((value) => ({ value, label: t(`fixedAssets.methods.${value}`) })) },
    { name: 'useful_life_months', label: t('fixedAssets.fields.usefulLife'), type: 'number', hidden: (values) => ['none', 'units_of_production'].includes(values.method) },
    { name: 'residual_percent', label: t('fixedAssets.categories.residualPercent'), type: 'decimal', maxDecimals: 4 },
    { name: 'declining_rate', label: t('fixedAssets.fields.decliningRate'), type: 'decimal', maxDecimals: 4, hidden: (values) => values.method !== 'declining_balance' },
    { name: 'tax_class', label: t('fixedAssets.categories.taxClass'), type: 'select', options: [{ value: '', label: t('fixedAssets.categories.noTaxClass') }, ...taxClasses] },
  ]
  const toBody = (values) => ({
    ...(editing?.id ? {} : { company_id: company?.id }),
    code: values.code,
    name: values.name,
    method: values.method,
    useful_life_months: values.useful_life_months ? Number(values.useful_life_months) : null,
    residual_percent: values.residual_percent || '0',
    declining_rate: values.method === 'declining_balance' ? values.declining_rate || null : null,
    tax_class: values.tax_class || null,
  })

  return (
    <>
      <PageHeader
        title={t('fixedAssets.categories.title')}
        description={t('fixedAssets.categories.description')}
        actions={manage && company ? <Button variant="primary" icon="plus" onClick={() => setEditing({})}>{t('fixedAssets.categories.new')}</Button> : null}
      />
      <div className="flex flex-col gap-5">
        {!company ? <Alert tone="info" title={t('fixedAssets.chooseCompany')} /> : null}
        {archive.isError ? <Alert tone="danger" title={errorMessage(archive.error)} /> : null}
        <Card title={t('fixedAssets.categories.title')} subtitle={t('fixedAssets.categories.accountsHelp')}>
          <DataTable caption={t('fixedAssets.categories.title')} columns={columns} rows={rows} loading={categories.isPending} emptyText={t('fixedAssets.categories.empty')} />
        </Card>
        <Card title={t('fixedAssets.categories.itemsTitle')} subtitle={t('fixedAssets.categories.itemsHelp')}>
          <div className="flex flex-col gap-4">
            {flag.isError ? <Alert tone="danger" title={errorMessage(flag.error)} /> : null}
            {can('fixed_assets.asset.manage') && company ? (
              <div className="grid gap-3 sm:grid-cols-3 sm:items-end">
                <ItemPicker label={t('fixedAssets.categories.item')} value={item} type="" onChange={setItem} />
                <Select label={t('fixedAssets.fields.category')} placeholder={t('fixedAssets.pickers.category')} value={itemCategory} onChange={(event) => setItemCategory(event.target.value)} options={rows.filter((row) => !row.archived_at).map((row) => ({ value: row.id, label: row.name }))} />
                <div>
                  <Button loading={flag.isPending} disabled={!item || !itemCategory} onClick={() => flag.mutate()}>
                    {t('fixedAssets.categories.flag')}
                  </Button>
                </div>
              </div>
            ) : null}
            <DataTable
              caption={t('fixedAssets.categories.itemsTitle')}
              columns={[
                { key: 'item', label: t('fixedAssets.categories.item'), render: (row) => `${row.item?.code ?? ''} · ${row.item?.name ?? ''}` },
                { key: 'category', label: t('fixedAssets.fields.category'), render: (row) => row.category?.name ?? '' },
                {
                  key: 'actions',
                  label: t('fixedAssets.categories.actions'),
                  align: 'end',
                  render: (row) => (
                    <Button variant="ghost" icon="remove" onClick={() => unflag.mutate(row.id)}>
                      {t('fixedAssets.categories.unflag')}
                    </Button>
                  ),
                },
              ]}
              rows={flagged.data?.data ?? []}
              emptyText={t('fixedAssets.categories.noItems')}
            />
          </div>
        </Card>
      </div>
      {editing ? (
        <FormDialog
          open
          title={editing.id ? t('fixedAssets.categories.editTitle', { code: editing.code }) : t('fixedAssets.categories.new')}
          confirm={t('fixedAssets.categories.save')}
          method={editing.id ? 'patch' : 'post'}
          path={editing.id ? `fixed-assets/categories/${editing.id}` : 'fixed-assets/categories'}
          initial={{
            code: editing.code ?? '',
            name: editing.name ?? '',
            method: editing.method ?? 'straight_line',
            useful_life_months: editing.useful_life_months ? String(editing.useful_life_months) : '',
            residual_percent: editing.residual_percent ? String(Number(editing.residual_percent)) : '',
            declining_rate: editing.declining_rate ? String(Number(editing.declining_rate)) : '',
            tax_class: editing.tax_class ?? '',
          }}
          fields={fields}
          toBody={toBody}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null)
            toast.success(t('fixedAssets.categories.saved'))
            refresh()
          }}
        />
      ) : null}
    </>
  )
}
