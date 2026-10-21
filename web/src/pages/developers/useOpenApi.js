import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

export const OPENAPI_KEY = ['developers', 'openapi']

/** API-04: the public OpenAPI document (no credential), cached for the visit. */
export function useOpenApi() {
  return useQuery({ queryKey: OPENAPI_KEY, queryFn: () => api.get('developers/openapi.json'), staleTime: 5 * 60_000 })
}
