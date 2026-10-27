import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { SETTINGS_VIEW, SETUP_VIEW } from './fuelData'
import SetupProducts from './SetupProducts'
import SetupPumps from './SetupPumps'
import SetupSettings from './SetupSettings'
import SetupStations from './SetupStations'
import SetupTanks from './SetupTanks'

const PANELS = { stations: SetupStations, products: SetupProducts, tanks: SetupTanks, pumps: SetupPumps, settings: SetupSettings }

/** FUEL-02 to FUEL-07, FUEL-03: the forecourt set-up: stations, fuel products, tanks, pumps and nozzles, and the company's tolerances. */
export default function Setup() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [params, setParams] = useSearchParams()
  const [tab, setTab] = useState(params.get('tab') ?? '')
  const available = [...(can(SETUP_VIEW) ? ['stations', 'products', 'tanks', 'pumps'] : []), ...(can(SETTINGS_VIEW) ? ['settings'] : [])]
  const current = available.includes(tab) ? tab : available[0]
  const Panel = PANELS[current]
  return (
    <>
      <PageHeader title={t('fuel.setup.title')} description={t('fuel.setup.description')} />
      <Tabs
        items={available.map((value) => ({ value, label: t(`fuel.setup.tabs.${value}`) }))}
        value={current}
        onChange={(next) => {
          setTab(next)
          setParams({ tab: next }, { replace: true })
        }}
      />
      {Panel ? <Panel /> : null}
    </>
  )
}
