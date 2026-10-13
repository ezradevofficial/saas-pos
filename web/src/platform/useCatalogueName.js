import { useQuery } from '@tanstack/react-query'
import { useLocale } from '@/lib/useLocale'
import { platformApi } from './platformClient'

/** A catalogue name in the console's language (platform staff type names in English and French). */
export function useCatalogueName() {
  const locale = useLocale()
  return (name) => name?.[locale] ?? name?.en ?? ''
}

/**
 * Catalogue keys (modules, bundles, as in a plan "starter + crm") shown by
 * their names in the console's language. Null until the catalogue loads,
 * so screens show a placeholder rather than raw keys.
 */
export function useCatalogueKeyNames() {
  const name = useCatalogueName()
  const modules = useQuery({ queryKey: ['platform', 'catalogue', 'modules'], queryFn: () => platformApi.get('platform/catalogue/modules') })
  const bundles = useQuery({ queryKey: ['platform', 'catalogue', 'bundles'], queryFn: () => platformApi.get('platform/catalogue/bundles') })
  if (!modules.data || !bundles.data) return null
  const names = new Map([...(modules.data.data ?? []), ...(bundles.data.data ?? [])].map((entry) => [entry.key, name(entry.name)]))
  const one = (key) => names.get(key) || key
  return {
    one,
    plan: (plan) => (plan ? String(plan).split(' + ').map(one).join(' + ') : ''),
    list: (keys) => keys.map(one).join(', '),
  }
}
