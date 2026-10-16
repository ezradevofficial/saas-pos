import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { shop } from './shopClient'

/** The store's public face (`GET shop/{store}`), shared by every storefront page. */
export function useShop() {
  const { store } = useParams()
  const query = useQuery({ queryKey: ['shop', store], queryFn: () => shop(store, 'GET', ''), staleTime: 60_000, retry: false })
  return { handle: store, store: query.data?.data ?? null, query }
}
