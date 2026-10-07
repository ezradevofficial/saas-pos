import { useTranslation } from 'react-i18next'

/** "Cashier at Front till" lines for a user's roles (AssignmentResource). */
export function RoleList({ assignments }) {
  const { t } = useTranslation()
  if (!assignments?.length) return <span className="text-ink-muted">{t('users.noRoles')}</span>
  return (
    <ul className="flex flex-col">
      {assignments.map((assignment) => (
        <li key={assignment.id ?? `${assignment.role.id}-${assignment.scope.id}`}>
          {t('users.roleAt', { role: assignment.role.name, scope: assignment.scope.name ?? t(`users.scopeTypes.${assignment.scope.type}`) })}
        </li>
      ))}
    </ul>
  )
}
