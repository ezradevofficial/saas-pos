// MD-01, LOY-27: tabs other modules add to a customer or supplier page,
// shown after the core tabs while the module is active and the user holds
// one of its permissions (the API checks again). A module registers once:
//
//   registerPartyTab({ value: 'loyalty', module: 'loyalty', permissions: [...], roles: ['customer'],
//                      label: (t) => t('loyalty.customer.tab'), Component })
//
// Component receives { party }.
const tabs = []

export function registerPartyTab(tab) {
  if (!tabs.some((entry) => entry.value === tab.value)) tabs.push(tab)
}

/** The registered tabs `role`'s page may show, given `hasModule` and `can` (usePermissions). */
export function partyTabsFor(role, { hasModule, can }) {
  return tabs.filter((tab) => (!tab.roles || tab.roles.includes(role)) && hasModule(tab.module) && tab.permissions.some((name) => can(name)))
}
