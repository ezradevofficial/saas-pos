import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

/**
 * AUTH-01..AUTH-05 (email-only mode): whether the platform can send text
 * messages, from `meta.sms_available` on GET public/branding. Phone sign-up,
 * phone codes and phone invitations are hidden only when the API says no;
 * while it is unknown the forms stay as they are, and the API refuses a
 * phone with `sms_unavailable` anyway. The platform console passes its own
 * client so no tenant token is sent (ADR 013).
 */
export function useSmsAvailable(client = api) {
  const meta = useQuery({
    queryKey: ['public', 'meta'],
    queryFn: async () => (await client.get('public/branding')) ?? null,
    staleTime: 300_000,
    retry: false,
  })
  return meta.data?.meta?.sms_available !== false
}
