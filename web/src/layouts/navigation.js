/**
 * Navigation (RBAC-09): an item with `permission` shows only when the user
 * has it somewhere; an item with `module` only while that module is active.
 * Sprint 1 has Overview and Settings; later modules add their groups here.
 */
export const NAV_GROUPS = [
  {
    id: 'overview',
    label: (t) => t('nav.groups.overview'),
    items: [{ to: '/', end: true, icon: 'dashboard', label: (t) => t('nav.dashboard') }],
  },
  {
    id: 'settings',
    label: (t) => t('nav.groups.settings'),
    items: [
      { to: '/settings/organisation', icon: 'organisation', label: (t) => t('nav.organisation'), permission: 'core.company.view', module: 'core' },
      { to: '/settings/users', icon: 'users', label: (t) => t('nav.users'), permission: 'core.user.view', module: 'core' },
      { to: '/settings/roles', icon: 'roles', label: (t) => t('nav.roles'), permission: 'core.role.view', module: 'core' },
      { to: '/settings/appearance', icon: 'appearance', label: (t) => t('nav.appearance') },
      { to: '/settings/sessions', icon: 'sessions', label: (t) => t('nav.sessions') },
    ],
  },
]

/** The groups and items the user may see; empty groups are dropped. */
export function visibleGroups(groups, { can, hasModule }) {
  return groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => (!item.permission || can(item.permission)) && (!item.module || hasModule(item.module))),
    }))
    .filter((group) => group.items.length > 0)
}
