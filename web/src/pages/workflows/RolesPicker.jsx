import { useTranslation } from 'react-i18next'
import { Checkbox } from '@/components/ds'

const TEMPLATE = 'template:'

/** Whether a stage's role ref names this role: its id, or `template:<key>` for a system role (WF-08). */
const names = (ref, role) => ref === role.id || (role.is_system && role.template_key && ref === `${TEMPLATE}${role.template_key}`)

/**
 * Roles allowed to move documents into or out of a stage (WF-08). Refs are
 * role ids, or `template:<key>` for the tenant's system role from a role
 * template, as default flows name them. None ticked: the document type's
 * own permission decides.
 */
export function RolesPicker({ label, help, value = [], onChange, roles, disabled }) {
  const { t } = useTranslation()
  const refs = Array.isArray(value) ? value : []
  const unknown = refs.filter((ref) => !roles.some((role) => names(ref, role)))

  return (
    <fieldset className="flex min-w-0 flex-col gap-2" disabled={disabled}>
      <legend className="pb-1 text-label text-ink">{label}</legend>
      {help ? <span className="text-caption text-ink-muted">{help}</span> : null}
      <div className="flex max-h-picker flex-col gap-2 overflow-auto">
        {roles.map((role) => {
          const checked = refs.some((ref) => names(ref, role))
          return (
            <Checkbox
              key={role.id}
              label={role.name}
              checked={checked}
              onChange={(event) =>
                onChange(event.target.checked ? [...refs, role.id] : refs.filter((ref) => !names(ref, role)))
              }
            />
          )
        })}
        {unknown.map((ref) => (
          <Checkbox
            key={ref}
            label={t('workflows.roles.unknown', { ref })}
            checked
            onChange={() => onChange(refs.filter((one) => one !== ref))}
          />
        ))}
        {roles.length === 0 && unknown.length === 0 ? <span className="text-caption text-ink-muted">{t('workflows.roles.none')}</span> : null}
      </div>
    </fieldset>
  )
}
