import type { ApiSuccess, Health } from '@snowego/api-types'

/** Liveness of the Laravel API through the same-origin proxy. */
export function useApiHealth() {
  return useFetch<ApiSuccess<Health>>('/api/v1/health', {
    key: 'api-health',
    server: true
  })
}
