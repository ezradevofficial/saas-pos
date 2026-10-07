import { useTranslation } from 'react-i18next'
import { Button, Select } from '@/components/ds'
import { scopeLabel } from './assignments'

/**
 * One role assignment: the role, the kind of place (whole organisation,
 * company, branch, location) and the place itself (RBAC-04).
 */
export function AssignmentFields({ index, value, onChange, onRemove, roles, scopes, scopeTypes, errors = {} }) {
  const { t } = useTranslation()
  const set = (patch) => onChange({ ...value, ...patch })
  const number = index + 1
  const places = value.scope_type && value.scope_type !== 'tenant' ? scopes[value.scope_type] : []

  return (
    <fieldset className="grid gap-3 rounded-md border border-border p-4 sm:grid-cols-3">
      <legend className="px-1 text-label text-ink">{t('users.assignment.legend', { number })}</legend>
      <Select
        label={t('users.assignment.role')}
        placeholder={t('users.assignment.rolePlaceholder')}
        options={roles.map((role) => ({ value: role.id, label: role.name }))}
        value={value.role_id}
        onChange={(event) => set({ role_id: event.target.value })}
        error={errors.role_id}
        required
      />
      <Select
        label={t('users.assignment.scopeType')}
        options={scopeTypes.map((type) => ({ value: type, label: t(`users.scopeTypes.${type}`) }))}
        value={value.scope_type}
        onChange={(event) => set({ scope_type: event.target.value, scope_id: '' })}
        error={errors.scope_type}
        required
      />
      {value.scope_type === 'tenant' ? (
        <p className="self-end pb-2 text-caption text-ink-muted">{t('users.assignment.tenantHelp')}</p>
      ) : (
        <Select
          label={t(`users.assignment.scope.${value.scope_type || 'location'}`)}
          placeholder={t(`users.assignment.scopePlaceholder.${value.scope_type || 'location'}`)}
          options={places.map((record) => ({ value: record.id, label: scopeLabel(value.scope_type, record) }))}
          value={value.scope_id}
          onChange={(event) => set({ scope_id: event.target.value })}
          error={errors.scope_id}
          required
        />
      )}
      {onRemove ? (
        <div className="sm:col-span-3">
          <Button variant="ghost" icon="remove" onClick={onRemove} aria-label={t('users.assignment.removeRow', { number })}>
            {t('users.assignment.remove')}
          </Button>
        </div>
      ) : null}
    </fieldset>
  )
}
