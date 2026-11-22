import { createApiClient } from '@snowego/api-types'

/** Liveness of the Laravel API through the same-origin proxy. */
export function useApiHealth() {
  return useAsyncData('api-health', async () => {
    const { data } = await createApiClient().GET('/health')
    if (!data) throw new Error('API health check failed')
    return data
  }, { server: false })
}
