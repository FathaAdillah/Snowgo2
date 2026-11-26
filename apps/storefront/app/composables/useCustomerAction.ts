import type { ApiError } from '@snowego/api-types'

export async function customerWrite<T = unknown>(path: string, method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', body?: Record<string, unknown> | FormData, key = crypto.randomUUID()): Promise<T> {
  await $fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', retry: 0 })
  const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
  return await $fetch<T>('/api/v1' + path, { method, body, credentials: 'same-origin', retry: 0, headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': token, 'Idempotency-Key': key } }) as T
}
export function useCustomerAction() {
  const busy = ref(false)
  const notice = ref('')
  const problem = ref('')
  async function run(action: () => Promise<unknown>, message = '') {
    if (busy.value) return
    busy.value = true
    problem.value = ''
    notice.value = ''
    try {
      await action()
      notice.value = message
    } catch (err) {
      const data = (err as { data?: Partial<ApiError> }).data
      problem.value = Object.values(data?.errors ?? {}).flat().join(' ') || data?.message || 'Permintaan gagal. Periksa koneksi dan coba lagi.'
    } finally {
      busy.value = false
    }
  }
  return { busy, notice, problem, run }
}
