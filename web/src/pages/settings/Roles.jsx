import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { actionsColumn } from '@/lib/listColumns'
import { useServerList } from '@/lib/useServerList'
import { CopyRoleDialog } from './roles/CopyRoleDialog'

/**
 * RBAC-02, RBAC-03: the tenant's roles. System roles are marked and copied;
 * custom roles open for editing. Search, sort (system roles first by
 * default), pages, columns and export (EXP-01, LAY-04).
 */
export default function Roles() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { tenantWide } = usePermissions()
  const [copying, setCopying] = useState(null)
  const canCreate = tenantWide('core.role.create')

  const columns = [
    {
      key: 'name',
      label: t('roles.columns.name'),
      sortKey: 'name',
      hideable: false,
      render: (role) => (
        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span className="font-medium text-ink">{role.name}</span>
          {role.is_system ? <StatusBadge tone="neutral">{t('roles.system')}</StatusBadge> : null}
        </span>
      ),
    },
    {
      key: 'type',
      label: t('roles.columns.type'),
      sortKey: 'type',
      defaultHidden: true,
      render: (role) => (role.is_system ? t('roles.types.system') : t('roles.types.custom')),
    },
    { key: 'description', label: t('roles.columns.description'), render: (role) => <span className="text-ink-muted">{role.description ?? ''}</span> },
    {
      key: 'permissions',
      label: t('roles.columns.permissions'),
      align: 'end',
      numeric: true,
      render: (role) => t('roles.permissionCount', { count: role.permissions?.length ?? 0 }),
    },
    {
      key: 'twoFactor',
      label: t('roles.columns.twoFactor'),
      sortKey: 'two_factor',
      exportKey: 'two_factor',
      render: (role) => (role.requires_two_factor ? <StatusBadge tone="info">{t('roles.twoFactorRequired')}</StatusBadge> : null),
    },
    actionsColumn(t('roles.columns.actions'), (role) =>
      role.is_system && canCreate ? (
        <Button
          variant="ghost"
          icon="copy"
          onClick={(event) => {
            event.stopPropagation()
            setCopying(role)
          }}
          aria-label={t('roles.copyRole', { name: role.name })}
        >
          {t('roles.copy')}
        </Button>
      ) : null,
    ),
  ]
  const list = useServerList({ id: 'roles', endpoint: 'roles', queryKey: ['roles'], columns })

  return (
    <>
      <PageHeader
        title={t('settings.roles.title')}
        description={t('settings.roles.description')}
        actions={
          canCreate ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/settings/roles/new')}>
              {t('roles.create')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('settings.roles.title')}
        searchPlaceholder={t('roles.searchPlaceholder')}
        onRowClick={(role) => navigate(`/settings/roles/${role.id}`)}
        emptyText={
          list.term ? (
            t('roles.emptyFiltered')
          ) : canCreate ? (
            <span className="flex flex-col items-center gap-3">
              {t('roles.empty')}
              <Button variant="primary" icon="plus" onClick={() => navigate('/settings/roles/new')}>
                {t('roles.create')}
              </Button>
            </span>
          ) : (
            t('roles.emptyReadOnly')
          )
        }
      />
      {copying ? <CopyRoleDialog role={copying} onClose={() => setCopying(null)} /> : null}
    </>
  )
}
