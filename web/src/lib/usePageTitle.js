import { useEffect } from 'react'
import { appName } from '@/config'

/** Sets the browser tab title to "Page · App name". */
export function usePageTitle(title) {
  useEffect(() => {
    document.title = title && appName ? `${title} · ${appName}` : title || appName
  }, [title])
}
