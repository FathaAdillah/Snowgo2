export default defineNuxtRouteMiddleware(async (to) => {
  if (import.meta.server) return
  try {
    const me = await $fetch<StoreRead<'/auth/me'>>('/api/v1/auth/me', { retry: 0 })
    if (!me.data.email_verified) return navigateTo('/verify-email')
  } catch (err) {
    if ((err as { status?: number }).status === 401) return navigateTo({ path: '/masuk', query: { next: to.fullPath } })
    throw err
  }
})
