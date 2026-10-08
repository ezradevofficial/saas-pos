import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { DuplicatesAlert } from '@/components/DuplicatesAlert'
import { HistoryPanel } from '@/components/HistoryPanel'
import { Alert, Button, Card, Icon, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConfirmDialog } from '@/pages/settings/ConfirmDialog'
import { PartyForm } from './PartyForm'
import { ROLE_PATHS } from './partyData'

const detailKey = (id) => ['parties', 'detail', id]

/** History labels for a party's own fields: kind and roles in words. */
function usePartyHistoryFields() {
  const { t } = useTranslation()
  const none = t('history.none')
  return {
    kind: { format: (value) => (value ? t(`parties.kinds.${value}`, { defaultValue: value }) : none) },
    roles: { format: (list) => (Array.isArray(list) && list.length ? list.map((role) => t(`parties.roles.${role}`, { defaultValue: role })).join(', ') : none) },
  }
}

/** MD-01, MD-06, MD-07: one customer or supplier: Details (with possible duplicates) and History. */
export default function PartyDetail({ role }) {
  const { t } = useTranslation()
  const { partyId } = useParams()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const { can, canWithin } = usePermissions()
  const path = ROLE_PATHS[role]
  const [duplicates, setDuplicates] = useState(() => location.state?.duplicates ?? [])
  const [saved, setSaved] = useState(Boolean(location.state?.created))
  const [archiving, setArchiving] = useState(false)
  const tab = params.get('tab') === 'history' ? 'history' : 'details'
  const query = useQuery({ queryKey: detailKey(partyId), queryFn: () => api.get(`parties/${partyId}`) })
  const party = query.data?.data
  const timeZone = useTimeZone(party?.company_id)
  const historyFields = usePartyHistoryFields()

  const action = useMutation({
    mutationFn: (kind) => api.post(`parties/${partyId}/${kind}`),
    onSuccess: async (response) => {
      setArchiving(false)
      queryClient.setQueryData(detailKey(partyId), { data: response.data })
      await queryClient.invalidateQueries({ queryKey: ['parties', 'list'] })
      queryClient.invalidateQueries({ queryKey: ['history', 'party', partyId] })
    },
  })

  const back = (
    <Link to={`/contacts/${path}`} className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {t(`parties.back.${role}`)}
    </Link>
  )
  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) {
    return (
      <>
        {back}
        <Alert tone="danger" title={query.error.status === 404 ? t('parties.notFound') : errorMessage(query.error)} />
      </>
    )
  }

  const allowed = (name) => (party.company_id ? canWithin(name, [{ type: 'company', id: party.company_id }]) : can(name))
  const archived = Boolean(party.archived_at)
  const canEdit = allowed('core.party.edit') && !archived

  return (
    <>
      {back}
      <PageHeader
        title={party.name ?? t('parties.unnamed')}
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            {(party.roles ?? []).map((name) => (
              <span key={name}>{t(`parties.roles.${name}`, { defaultValue: name })}</span>
            ))}
            <StatusBadge tone={archived ? 'neutral' : 'success'}>{archived ? t('parties.status.archivedOne') : t('parties.status.activeOne')}</StatusBadge>
          </span>
        }
        actions={
          allowed('core.party.archive') ? (
            archived ? (
              <Button icon="restore" loading={action.isPending} onClick={() => action.mutate('restore')}>
                {t('parties.restore')}
              </Button>
            ) : (
              <Button variant="danger" icon="archive" onClick={() => setArchiving(true)}>
                {t('parties.archive')}
              </Button>
            )
          ) : null
        }
      />
      {saved ? <Alert tone="success" title={t('parties.saved', { name: party.name ?? '' })} /> : null}
      {action.isError && !archiving ? <Alert tone="danger" title={errorMessage(action.error)} /> : null}
      <DuplicatesAlert kind="party" matches={duplicates} linkTo={(match) => `/contacts/${path}/${match.id}`} onDismiss={() => setDuplicates([])} />
      <Tabs
        items={[
          { value: 'details', label: t('items.tabs.details') },
          { value: 'history', label: t('items.tabs.history') },
        ]}
        value={tab}
        onChange={(next) => setParams(next === 'history' ? { tab: 'history' } : {}, { replace: true })}
      />
      {tab === 'details' ? (
        <PartyForm
          key={party.id}
          party={party}
          role={role}
          readOnly={!canEdit}
          onSaved={(response) => {
            setSaved(true)
            setDuplicates(response.meta?.possible_duplicates ?? [])
          }}
        />
      ) : (
        <Card>
          <HistoryPanel type="party" recordId={party.id} timeZone={timeZone} fields={historyFields} />
        </Card>
      )}
      <ConfirmDialog
        open={archiving}
        title={t('parties.archiveTitle', { name: party.name ?? '' })}
        confirmLabel={t('parties.archiveConfirm')}
        cancelLabel={t('parties.keep')}
        pending={action.isPending}
        error={action.error ? errorMessage(action.error) : null}
        failure={action.error}
        onConfirm={() => action.mutate('archive')}
        onClose={() => {
          setArchiving(false)
          action.reset()
        }}
      >
        {t('parties.archiveText')}
      </ConfirmDialog>
    </>
  )
}
