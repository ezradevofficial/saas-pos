import { useEffect } from 'react'
import { appName } from '@/config'

/** Sets the browser tab title to "Page · App name". Off (`enabled` false) for a page embedded in another, which keeps its own title. */
export function usePageTitle(title, enabled = true) {
  useEffect(() => {
    if (!enabled) return
    document.title = title && appName ? `${title} · ${appName}` : title || appName
  }, [title, enabled])
}
