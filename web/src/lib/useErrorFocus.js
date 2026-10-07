import { useEffect } from 'react'

/**
 * After a failed submit (`failure`, the mutation error) focus moves to the
 * first invalid field inside `containerRef`, or to `alertRef` when only the
 * form has an error, so keyboard and screen-reader users land on what needs
 * fixing (same rule as the auth forms).
 */
export function useErrorFocus(containerRef, alertRef, failure) {
  useEffect(() => {
    if (!failure) return
    const invalid = containerRef.current?.querySelector('[aria-invalid="true"]')
    if (invalid) invalid.focus()
    else alertRef.current?.focus()
  }, [failure, containerRef, alertRef])
}
