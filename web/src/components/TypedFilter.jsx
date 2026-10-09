import { TextField } from '@/components/ds'
import { useTypedText } from '@/lib/useServerList'

/**
 * A typed filter (text contains, a number bound): the URL follows once
 * typing stops, or at once when the drawer closes, so no keystroke is lost.
 */
export function TypedFilter({ label, value, onChange, inputMode }) {
  const [text, setText] = useTypedText(value, (next) => onChange(next, { replace: true }), 300, { flushOnUnmount: true })
  return <TextField label={label} className="w-full" value={text} inputMode={inputMode} onChange={(event) => setText(event.target.value)} autoComplete="off" />
}
