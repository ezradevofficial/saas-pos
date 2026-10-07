import {
  ArrowDown,
  ArrowUp,
  Check,
  ChevronDown,
  Clock,
  Cloud,
  Info,
  RefreshCw,
  TriangleAlert,
  WifiOff,
  X,
} from 'lucide-react'
import { cn } from '@/lib/utils'

const ICONS = {
  check: Check,
  x: X,
  alert: TriangleAlert,
  info: Info,
  cloud: Cloud,
  sync: RefreshCw,
  offline: WifiOff,
  chevron: ChevronDown,
  clock: Clock,
  up: ArrowUp,
  down: ArrowDown,
}

/** Lucide line icon: 1.5 stroke, 16px in navigation, 18px in tools. Decorative only. */
export function Icon({ name, size = 16, className, ...rest }) {
  const Glyph = ICONS[name]
  if (!Glyph) return null
  return <Glyph size={size} strokeWidth={1.5} aria-hidden="true" focusable="false" className={cn('shrink-0', className)} {...rest} />
}
