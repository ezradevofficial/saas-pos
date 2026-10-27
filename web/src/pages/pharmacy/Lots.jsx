import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { lotColumns } from './lotColumns'
import { choiceField, locationFilter, LOT_RECEIVE, LOT_STATES, LOT_TRANSFER, pharmacyKey, usePlaces } from './pharmacyData'

/**
 * PHA-05, PHA-07: stock by lot at the places the user sees, earliest expiry
 * first, with search (item or batch), state and place filters, sort and
 * export. A lot opens its detail, where it is quarantined or released.
 */
export default function Lots() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const places = usePlaces()
  const columns = lotColumns(t)
  const list = useServerList({
    id: 'pharmacy-lots',
    endpoint: 'pharmacy/lots',
    queryKey: [...pharmacyKey, 'lots'],
    filters: { state: '', location: '' },
    defaultSort: 'expires_on',
    columns,
  })

  return (
    <>
      <PageHeader
        title={t('pharmacy.lots.title')}
        description={t('pharmacy.lots.description')}
        actions={
          <span className="flex gap-2">
            {can(LOT_TRANSFER) ? (
              <Button icon="transfers" onClick={() => navigate('/pharmacy/transfers/new')}>
                {t('pharmacy.lots.move')}
              </Button>
            ) : null}
            {can(LOT_RECEIVE) ? (
              <Button variant="primary" icon="plus" onClick={() => navigate('/pharmacy/receive')}>
                {t('pharmacy.lots.receive')}
              </Button>
            ) : null}
          </span>
        }
      />
      <ListView
        list={list}
        title={t('pharmacy.lots.title')}
        searchLabel={t('pharmacy.lots.search')}
        filterFields={[choiceField(t, 'state', LOT_STATES, t('pharmacy.columns.state'), 'pharmacy.states'), ...locationFilter(t, places)]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('pharmacy.lots.emptyFiltered') : t('pharmacy.lots.empty')}
        onRowClick={(row) => navigate(`/pharmacy/lots/${row.lot_id}`)}
      />
    </>
  )
}
