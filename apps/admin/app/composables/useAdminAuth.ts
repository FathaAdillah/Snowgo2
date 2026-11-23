import type { ApiError, ApiSuccess, Enrollment, MfaChallenge, RecoveryCodes, StaffMe } from '@snowego/api-types'

/** Staff sign-in (API-AUTH-014/015) through the same-origin Sanctum proxy. */
export function useAdminAuth() {
  const user = useState<StaffMe | null>('staff-user', () => null)

  async function csrf() {
    await $fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    const value = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
    return decodeURIComponent(value ?? '')
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

  async function refresh() {
    try {
      const response = await $fetch<ApiSuccess<StaffMe>>('/api/v1/admin/auth/me', { credentials: 'include', cache: 'no-store' })
      user.value = response.data
    } catch (error) {
      user.value = null
      if ((error as { status?: number }).status !== 401) throw error
    }
    return user.value
  }

  async function logout() {
    try {
      await post('/api/v1/admin/auth/logout', {})
    } catch (error) {
      if ((error as { status?: number }).status !== 401) throw error
    }
    user.value = null
    clearNuxtData()
    await navigateTo('/login', { replace: true })
  }

  const can = (permission?: string) => !!permission && user.value?.permissions.includes(permission) === true
  const enroll = (token: string, password: string) => post<Enrollment>('/api/v1/admin/auth/mfa-enrollments', { token, password })
  const confirm = (token: string, code: string) => post<RecoveryCodes>('/api/v1/admin/auth/mfa-enrollment-verifications', { token, code })
  const stepUp = (password: string, code: string) => post('/api/v1/admin/auth/step-up', { password, code })
  return { user, login, verifyMfa, refresh, can, logout, enroll, confirm, csrf, stepUp }
}

export function authErrorMessage(error: unknown): string {
  const status = (error as { statusCode?: number })?.statusCode
  const data = (error as { data?: Partial<ApiError> })?.data
  if (status === 419) return 'Sesi kedaluwarsa. Muat ulang halaman dan coba lagi.'
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
