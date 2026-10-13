import { useLocale } from '@/lib/useLocale'

/** A catalogue name in the console's language (platform staff type names in English and French). */
export function useCatalogueName() {
  const locale = useLocale()
  return (name) => name?.[locale] ?? name?.en ?? ''
}
