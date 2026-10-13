import { createContext, useContext } from 'react'

export const PlatformAuthContext = createContext(null)

/** The platform staff session (see PlatformAuthProvider). */
export function usePlatformAuth() {
  const value = useContext(PlatformAuthContext)
  if (!value) throw new Error('usePlatformAuth outside PlatformAuthProvider')
  return value
}
