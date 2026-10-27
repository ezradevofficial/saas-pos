import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { BranchSwitch, NoProperty } from './HotelParts'
import { dateField, hotelKey, RESERVATION_CREATE, STAY_STATUSES, useBranchChoice } from './hotelData'
import { stayColumns } from './stayColumns'

/** HOT-12, HOT-31: the reservations of a property, one row per stay, with filters, sort and export. */
function ReservationList({ branchId }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const list = useServerList({
    id: 'hotel-reservations',
    endpoint: 'hotel/reservations',
    params: { branch: branchId },
    queryKey: [...hotelKey, 'reservations', branchId],
    filters: { status: '', kind: '', arrival_from: '', arrival_to: '' },
    defaultSort: 'arrival_on',
    columns: stayColumns(t),
  })
  const status = {
    name: 'status',
    label: t('hotel.columns.status'),
    options: [{ value: '', label: t('hotel.filters.openStays') }, { value: 'all', label: t('hotel.filters.all') }, ...STAY_STATUSES.map((value) => ({ value, label: t(`hotel.states.${value}`) }))],
  }
  const kind = { name: 'kind', label: t('hotel.reservations.kind'), options: [{ value: '', label: t('hotel.filters.any') }, ...['direct', 'walk_in', 'group'].map((value) => ({ value, label: t(`hotel.kinds.${value}`) }))] }
  return (
    <ListView
      list={list}
      title={t('hotel.reservations.title')}
      searchLabel={t('hotel.reservations.search')}
      filterFields={[status, kind, dateField('arrival_from', t('hotel.filters.arrivalFrom')), dateField('arrival_to', t('hotel.filters.arrivalTo'))]}
      emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('hotel.reservations.emptyFiltered') : t('hotel.reservations.empty')}
      onRowClick={(row) => navigate(`/hotel/reservations/${row.reservation_id}`)}
    />
  )
}

export default function Reservations() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const choice = useBranchChoice()
  return (
    <>
      <PageHeader
        title={t('hotel.reservations.title')}
        description={t('hotel.reservations.description')}
        actions={
          can(RESERVATION_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/hotel/reservations/new')}>
              {t('hotel.reservations.new')}
            </Button>
          ) : null
        }
      />
      <BranchSwitch choice={choice} />
      {choice.branch ? <ReservationList key={choice.branch.id} branchId={choice.branch.id} /> : <NoProperty choice={choice} />}
    </>
  )
}
