import { useTranslation } from 'react-i18next'
import { Checkbox } from '@/components/ds'
import { entriesOf, roleRefsOf, userIdsOf } from '@/pages/workflows/notifyRecipients'
import { PeoplePicker, RolesPicker } from '@/pages/workflows/RolesPicker'

/** `field:<name>` entries: the person a user field of the document holds. */
const fieldNamesOf = (to) =>
  entriesOf(to)
    .filter((entry) => entry.startsWith('field:'))
    .map((entry) => entry.slice(6))

/** The `to` list from roles, people and user fields (the engine's `role:`, `user:` and `field:` prefixes). */
const recipientsFrom = (roleRefs, userIds, fieldNames) => [
  ...roleRefs.map((ref) => `role:${ref}`),
  ...userIds.map((id) => `user:${id}`),
  ...fieldNames.map((name) => `field:${name}`),
]

/**
 * Who a notification goes to (AUTO-03, NOT-02): roles and named people
 * through the workflow builder's pickers, plus the person a user field of
 * the document holds ("the requisition's owner") when the trigger has a
 * document. Channels come from each person's preferences.
 */
export function RecipientsPicker({ value, onChange, roles, users, userFields = [], fields = [], withDocument = true, error }) {
  const { t } = useTranslation()
  const roleRefs = roleRefsOf(value)
  const userIds = userIdsOf(value)
  const fieldNames = fieldNamesOf(value)
  const offered = withDocument ? userFields : []
  // A field no longer offered (a schedule has no document) still shows so it can be removed.
  const shownFields = [...offered, ...fieldNames.filter((name) => !offered.includes(name))]

  return (
    <fieldset className="flex min-w-0 flex-col gap-3">
      <legend className="pb-1 text-label text-ink">{t('automation.notify.to')}</legend>
      <span className="text-caption text-ink-muted">{t('automation.notify.toHelp')}</span>
      {error ? <span className="text-caption text-danger">{error}</span> : null}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        <RolesPicker label={t('automation.notify.roles')} roles={roles} value={roleRefs} onChange={(next) => onChange(recipientsFrom(next, userIds, fieldNames))} />
        <PeoplePicker label={t('automation.notify.people')} users={users} value={userIds} onChange={(next) => onChange(recipientsFrom(roleRefs, next, fieldNames))} />
        <fieldset className="flex min-w-0 flex-col gap-2">
          <legend className="pb-1 text-label text-ink">{t('automation.notify.fromFields')}</legend>
          {shownFields.length === 0 ? (
            <span className="text-caption text-ink-muted">{withDocument ? t('automation.notify.noUserFields') : t('automation.notify.noDocument')}</span>
          ) : (
            shownFields.map((name) => (
              <Checkbox
                key={name}
                label={fields.find((field) => field.name === name)?.label ?? name}
                checked={fieldNames.includes(name)}
                onChange={(event) => onChange(recipientsFrom(roleRefs, userIds, event.target.checked ? [...fieldNames, name] : fieldNames.filter((one) => one !== name)))}
              />
            ))
          )}
        </fieldset>
      </div>
    </fieldset>
  )
}
