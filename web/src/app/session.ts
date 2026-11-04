import { useQuery } from '@tanstack/react-query'
import { fetchSession } from '@/lib/api'

/** true = signed in, false = not. Backed by GET /auth/me (also refreshes the CSRF token). */
export function useSession() {
  return useQuery({ queryKey: ['session'], queryFn: fetchSession, staleTime: Infinity, retry: false })
}
