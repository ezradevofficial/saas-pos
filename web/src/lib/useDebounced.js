import { useEffect, useState } from 'react'

/** `value`, once it has stopped changing for `delay` ms (search boxes). */
export function useDebounced(value, delay = 300) {
  const [settled, setSettled] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])
  return settled
}
