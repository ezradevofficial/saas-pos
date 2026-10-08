import { useSyncExternalStore } from 'react'

/** Whether a media query matches now, following changes (false where matchMedia is missing). */
export function useMediaQuery(query) {
  return useSyncExternalStore(
    (listener) => {
      if (typeof window === 'undefined' || !window.matchMedia) return () => {}
      const list = window.matchMedia(query)
      list.addEventListener?.('change', listener)
      return () => list.removeEventListener?.('change', listener)
    },
    () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query).matches : false),
    () => false,
  )
}

/** Phones (below the md breakpoint, 768px): the workflow builder is read-only there (spec 6.4). */
export const PHONE_QUERY = '(max-width: 767px)'
