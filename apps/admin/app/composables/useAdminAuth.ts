import type { ApiError, ApiSuccess, MfaChallenge, StaffMe } from '@snowego/api-types'

/** Staff sign-in (API-AUTH-014/015) through the same-origin Sanctum proxy. */
export function useAdminAuth() {
  const user = useState<StaffMe | null>('staff-user', () => null)

  async function csrf() {
    await $fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    return useCookie('XSRF-TOKEN').value ?? ''
  }

  async function post<T>(path: string, body: Record<string, string>) {
    const token = await csrf()
    return $fetch<ApiSuccess<T>>(path, {
      method: 'POST',
      body,
      credentials: 'include',
      headers: { 'X-XSRF-TOKEN': token, 'Accept': 'application/json' }
    })
  }

  const login = (email: string, password: string) =>
    post<MfaChallenge>('/api/v1/admin/auth/login', { email, password })

  async function verifyMfa(code: string) {
    const response = await post<StaffMe>('/api/v1/admin/auth/mfa-challenges', { code })
    user.value = response.data
    return response.data
  }

  return { user, login, verifyMfa }
}

export function authErrorMessage(error: unknown): string {
  const status = (error as { statusCode?: number })?.statusCode
  const data = (error as { data?: Partial<ApiError> })?.data
  if (status === 404) {
    return 'Layanan login staff belum tersedia di API.'
  }
  if (data?.code === 'THROTTLED') {
    return 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.'
  }
  if (data?.code === 'BAD_CREDENTIALS') {
    return 'Email atau password salah.'
  }
  if (data?.code === 'INVALID_CHALLENGE') {
    return 'Kode verifikasi tidak valid atau sudah kedaluwarsa.'
  }
  return data?.message ?? 'Tidak dapat terhubung ke server. Coba lagi.'
}
