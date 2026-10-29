import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, KpiTile, Money } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { ClientDialog } from './Clients'
import { QueryNotes, Status, When } from './SalonParts'
import { APPOINTMENT_CREATE, CLIENT_MANAGE, CLIENT_NOTES, queryString, salonError, salonKey, useSalonPlace, useStaff } from './salonData'

/** A list of per-currency amounts, one per line, or a dash. */
function Amounts({ rows, field = 'amount_minor' }) {
  if (!rows?.length) return <span className="text-ink-muted">—</span>
  return (
    <span className="flex flex-col">
      {rows.map((row) => (
        <Money key={row.currency} amount={row[field]} currency={row.currency} />
      ))}
    </span>
  )
}

/**
 * SAL-25: one client: the profile, what they spent, deposits and prepaid value, the visit history and "Book again".
 * Only what the API sends is shown: allergies and formulas arrive for `salon.client.notes` and are dropped by field
 * rules (RBAC-05); the prepaid value for `salon.package.view`.
 */
export default function ClientDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { clientId } = useParams()
  const { can } = usePermissions()
  const place = useSalonPlace()
  const company = place.company
  const [editing, setEditing] = useState(false)
  const enabled = Boolean(company)
  const params = queryString({ company_id: company?.id })
  const profile = useQuery({ queryKey: [...salonKey, 'client', clientId, company?.id], queryFn: () => api.get(`salon/clients/${clientId}${params}`), enabled })
  const summary = useQuery({ queryKey: [...salonKey, 'client-summary', clientId, company?.id], queryFn: () => api.get(`salon/clients/${clientId}/summary${params}`), enabled })
  const visits = useQuery({ queryKey: [...salonKey, 'visits', clientId, company?.id], queryFn: () => api.get(`salon/clients/${clientId}/visits${params}`), enabled })
  const staff = useStaff(company?.id).rows
  const client = profile.data?.data
  const stats = summary.data?.data
  const rows = visits.data?.data ?? []
  const shows = (field) => client && client[field] && (!['allergies', 'formulas'].includes(field) || can(CLIENT_NOTES))
  const preferred = staff.find((member) => member.id === client?.preferred_staff_id)
  // "Book again": the services and stylist of the latest visit that was not cancelled.
  const last = rows.find((row) => !['cancelled', 'no_show'].includes(row.status))
  const again = queryString({
    client: clientId,
    services: last ? [...new Set(last.lines.filter((line) => line.kind === 'service').map((line) => line.service_id))].join(',') : '',
    staff: client?.preferred_staff_id ?? last?.lines[0]?.staff_id ?? '',
  })
  const columns = [
    { key: 'when', label: t('salon.clients.visit'), render: (row) => <When value={row.starts_at} /> },
    { key: 'services', label: t('salon.book.services'), render: (row) => row.lines.map((line) => line.service_name).join(', ') },
    { key: 'staff', label: t('salon.book.staff'), render: (row) => [...new Set(row.lines.map((line) => line.staff_name))].join(', ') },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.status} /> },
    { key: 'total', label: t('salon.clientPage.total'), align: 'end', render: (row) => <Money amount={row.total_minor} currency={row.currency} /> },
    { key: 'formula', label: t('salon.clients.formulaUsed'), render: (row) => row.service_notes ?? '' },
  ].filter((column) => column.key !== 'formula' || rows.some((row) => row.service_notes))

  return (
    <>
      <PageHeader
        eyebrow={<Link to="/salon/clients" className="text-primary hover:text-primary-hover">{t('salon.clientPage.back')}</Link>}
        title={client?.name ?? t('salon.clients.loading')}
        description={client ? [client.phone, client.email].filter(Boolean).join(' · ') : undefined}
        actions={
          <div className="flex gap-2">
            {can(CLIENT_MANAGE) && client ? (
              <Button variant="secondary" onClick={() => setEditing(true)}>
                {t('salon.clientPage.edit')}
              </Button>
            ) : null}
            {can(APPOINTMENT_CREATE) && client ? (
              <Button variant="primary" icon="plus" onClick={() => navigate(`/salon/appointments/new${again}`)}>
                {t('salon.clientPage.bookAgain')}
              </Button>
            ) : null}
          </div>
        }
      />
      <div className="flex flex-col gap-5">
        <QueryNotes query={profile} />
        {summary.isError ? <Alert tone="danger" title={salonError(summary.error)} /> : null}
        {stats ? (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiTile label={t('salon.clientPage.visits')} value={String(stats.visits)} />
            <KpiTile label={t('salon.clientPage.spend')} value={<Amounts rows={stats.spend} />} />
            <KpiTile label={t('salon.clientPage.deposits')} value={<Amounts rows={stats.deposits_held} />} />
            {stats.credit ? <KpiTile label={t('salon.clientPage.credit')} value={<Amounts rows={stats.credit} field="remaining_minor" />} /> : null}
          </div>
        ) : null}
        {stats ? (
          <p className="text-caption text-ink-muted">
            {[
              stats.last_visit_at ? t('salon.clientPage.lastVisit', { date: formatDateTime(stats.last_visit_at, locale, company?.timezone) }) : t('salon.clients.noVisits'),
              stats.next_appointment ? t('salon.clientPage.next', { date: formatDateTime(stats.next_appointment.starts_at, locale, company?.timezone) }) : null,
              stats.no_shows ? t('salon.clientPage.noShows', { count: stats.no_shows }) : null,
              stats.cancellations ? t('salon.clientPage.cancellations', { count: stats.cancellations }) : null,
            ].filter(Boolean).join(' · ')}
          </p>
        ) : null}
        {client ? (
          <Card title={t('salon.clientPage.profile')}>
            <dl className="grid gap-4 sm:grid-cols-2">
              <div>
                <dt className="text-caption text-ink-muted">{t('salon.clients.preferredStaff')}</dt>
                <dd>{preferred?.name ?? t('salon.book.anyStaff')}</dd>
              </div>
              {shows('preferences') ? (
                <div>
                  <dt className="text-caption text-ink-muted">{t('salon.clients.preferences')}</dt>
                  <dd className="whitespace-pre-line">{client.preferences}</dd>
                </div>
              ) : null}
              {shows('allergies') ? (
                <div>
                  <dt className="text-caption text-ink-muted">{t('salon.clients.allergies')}</dt>
                  <dd className="whitespace-pre-line">{client.allergies}</dd>
                </div>
              ) : null}
              {shows('formulas') ? (
                <div>
                  <dt className="text-caption text-ink-muted">{t('salon.clients.formulas')}</dt>
                  <dd className="whitespace-pre-line">{client.formulas}</dd>
                </div>
              ) : null}
              {shows('notes') ? (
                <div>
                  <dt className="text-caption text-ink-muted">{t('salon.clients.notes')}</dt>
                  <dd className="whitespace-pre-line">{client.notes}</dd>
                </div>
              ) : null}
            </dl>
            {client.has_alerts && !shows('allergies') && !shows('formulas') ? <p className="text-caption text-ink-muted">{t('salon.clients.hasAlerts')}</p> : null}
          </Card>
        ) : null}
        <Card title={t('salon.clients.visits')}>
          <QueryNotes query={visits} />
          {!visits.isError ? <DataTable caption={t('salon.clients.visits')} columns={columns} rows={rows} loading={visits.isPending && enabled} emptyText={t('salon.clients.noVisits')} /> : null}
        </Card>
      </div>
      {editing ? <ClientDialog partyId={clientId} company={company} onClose={() => setEditing(false)} /> : null}
    </>
  )
}
