export default defineNuxtRouteMiddleware(async (to) => {
  if (to.path === '/activate' || to.path === '/login') return
  const { refresh, can } = useAdminAuth()
  if (!await refresh()) return navigateTo('/login', { replace: true })
  if (typeof to.meta.permission !== 'string' || !can(to.meta.permission)) {
    throw createError({ statusCode: 403, statusMessage: 'Tidak memiliki izin untuk halaman ini' })
  }
})
