import { QueryClient } from '@tanstack/react-query'

// Client errors (4xx) are answers, not glitches: only network failures and
// server errors are retried, once.
export function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        retry: (count, error) => count < 1 && (error?.status === 0 || error?.status >= 500),
      },
      mutations: { retry: false },
    },
  })
}
