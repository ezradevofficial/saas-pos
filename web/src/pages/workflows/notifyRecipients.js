// Who a notify step writes to (NOT-02 through a flow): its config `to` lists
// `role:<role id | template key | template:key>` and `user:<user id>`
// (the engine's NotifyRecipients). Channels come from each recipient's
// notification preferences, never from the step.

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** The `to` entries as a list (the engine also accepts one string). */
export const entriesOf = (to) => (Array.isArray(to) ? to : typeof to === 'string' ? [to] : []).filter((entry) => typeof entry === 'string')

/** Role refs as RolesPicker reads them (a role id, or `template:<key>`). */
export function roleRefsOf(to) {
  return entriesOf(to)
    .filter((entry) => entry.startsWith('role:'))
    .map((entry) => entry.slice(5))
    .map((ref) => (UUID.test(ref) || ref.startsWith('template:') ? ref : `template:${ref}`))
}

/** Named people's user ids. */
export const userIdsOf = (to) =>
  entriesOf(to)
    .filter((entry) => entry.startsWith('user:'))
    .map((entry) => entry.slice(5))

/** The `to` list from picked role refs and user ids. */
export const toFrom = (roleRefs, userIds) => [...roleRefs.map((ref) => `role:${ref}`), ...userIds.map((id) => `user:${id}`)]
