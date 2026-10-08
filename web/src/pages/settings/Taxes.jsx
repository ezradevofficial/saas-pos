import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useSettingsCompany } from './finance/useSettingsCompany'
import { PriceLists } from './taxes/PriceLists'
import { TaxCategories } from './taxes/TaxCategories'
import { TaxCodes } from './taxes/TaxCodes'

/**
 * MD-03, CP-01, CP-02: a company's tax codes and their effective-dated
 * rates ("Rate needed" until a figure is confirmed; never invented), tax
 * categories and price lists.
 */
export default function Taxes() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const { company, picker, ready } = useSettingsCompany()
  const tabs = [
    { value: 'codes', label: t('taxes.tabs.codes') },
    { value: 'categories', label: t('taxes.tabs.categories') },
    ...(can(['core.price_list.view', 'core.price_list.edit']) ? [{ value: 'priceLists', label: t('taxes.tabs.priceLists') }] : []),
  ]
  const [tab, setTab] = useState('codes')

  return (
    <>
      <PageHeader title={t('settings.taxes.title')} description={t('settings.taxes.description')} />
      {picker}
      {!ready ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {ready && !company ? <Alert tone="info" title={t('finance.company.none')} /> : null}
      {company ? (
        <div className="flex flex-col gap-5">
          <Tabs items={tabs} value={tab} onChange={setTab} />
          {tab === 'codes' ? <TaxCodes key={company.id} company={company} /> : null}
          {tab === 'categories' ? <TaxCategories key={company.id} company={company} /> : null}
          {tab === 'priceLists' ? <PriceLists key={company.id} company={company} /> : null}
        </div>
      ) : null}
    </>
  )
}
