import { Navigate } from 'react-router'
import { useSupportSession } from './useSupportSession'

/** RBAC-13: a page that only writes (a personal designer) is closed to a read-only support session. */
export function NotInReadOnlySession({ children }) {
  const { readOnly } = useSupportSession()
  return readOnly ? <Navigate to="/" replace /> : children
}
