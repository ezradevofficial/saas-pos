import { createContext } from 'react'

// The context lives in a module of its own with no components, so React Fast
// Refresh never re-evaluates it while editing AuthProvider or its consumers:
// the context identity stays stable and useAuth keeps finding its provider.
export const AuthContext = createContext(null)
