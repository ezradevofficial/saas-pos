// MKT-01, MKT-02: constants shared by the marketplace pages.

/** App categories (marketplace_app_versions.category). */
export const CATEGORIES = ['accounting', 'analytics', 'attendance', 'commerce', 'delivery', 'messaging', 'payments', 'productivity', 'other']

/** Query key of the developer portal's data. */
export const DEVELOPER = ['developer']

/** StatusBadge tone per version status. */
export const VERSION_TONES = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' }

/** Leave for the app's redirect URI (a code, or access_denied). */
export function leaveTo(url) {
  window.location.assign(url)
}
