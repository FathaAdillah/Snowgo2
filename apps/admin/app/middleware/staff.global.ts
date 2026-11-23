import { hasPageAccess } from '~/composables/useAdminNavigation'

export default defineNuxtRouteMiddleware(async (to) => {
  if (to.path === '/activate' || to.path === '/login') return
  const { refresh, can } = useAdminAuth()
  if (!await refresh()) return navigateTo('/login', { replace: true })
  if (!hasPageAccess(to.meta.permission, can)) {
    throw createError({ statusCode: 403, statusMessage: 'Tidak memiliki izin untuk halaman ini' })
  }
})
