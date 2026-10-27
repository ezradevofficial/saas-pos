import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { BranchSwitch, NoProperty } from './HotelParts'
import { hotelKey, useBranchChoice } from './hotelData'
import { folioColumns } from './stayColumns'

/** A list of folios of one property: all of them, or (the report) those with something owed or held. */
export function FolioList({ branchId, report = false }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const list = useServerList({
    id: report ? 'hotel-folio-balances' : 'hotel-folios',
    endpoint: report ? 'hotel/reports/folio-balances' : 'hotel/folios',
    params: { branch: branchId },
    queryKey: [...hotelKey, report ? 'folio-balances' : 'folios', branchId],
    filters: report ? { status: '' } : { status: '', kind: '', with_balance: '' },
    defaultSort: '-created_at',
    columns: folioColumns(t),
  })
  const status = { name: 'status', label: t('hotel.columns.status'), options: [{ value: '', label: report ? t('hotel.states.open') : t('hotel.filters.all') }, ...(report ? [{ value: 'settled', label: t('hotel.states.settled') }] : [{ value: 'open', label: t('hotel.states.open') }, { value: 'settled', label: t('hotel.states.settled') }])] }
  const fields = report
    ? [status]
    : [
        status,
        { name: 'kind', label: t('hotel.columns.kind'), options: [{ value: '', label: t('hotel.filters.any') }, { value: 'guest', label: t('hotel.kinds.guest') }, { value: 'master', label: t('hotel.kinds.master') }] },
        { name: 'with_balance', label: t('hotel.filters.balance'), options: [{ value: '', label: t('hotel.filters.any') }, { value: '1', label: t('hotel.filters.withBalance') }] },
      ]
  return (
    <ListView
      list={list}
      title={report ? t('hotel.reports.folio-balances.title') : t('hotel.folios.title')}
      searchLabel={t('hotel.folios.search')}
      filterFields={fields}
      emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('hotel.folios.emptyFiltered') : t('hotel.folios.empty')}
      onRowClick={(row) => navigate(`/hotel/folios/${row.id}`)}
    />
  )
}

/** HOT-20: the folios of a property. */
export default function Folios() {
  const { t } = useTranslation()
  const choice = useBranchChoice()
  return (
    <>
      <PageHeader title={t('hotel.folios.title')} description={t('hotel.folios.description')} />
      <BranchSwitch choice={choice} />
      {choice.branch ? <FolioList key={choice.branch.id} branchId={choice.branch.id} /> : <NoProperty choice={choice} />}
    </>
  )
}
