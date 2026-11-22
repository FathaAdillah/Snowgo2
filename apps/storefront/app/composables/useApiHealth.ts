import { apiBase, type HealthResponse } from '@snowego/api-types'

/** Liveness of the Laravel API through the same-origin proxy. */
export function useApiHealth() {
  return useFetch<HealthResponse>(`${apiBase}/health`, {
    key: 'api-health',
    server: true
  })
}
