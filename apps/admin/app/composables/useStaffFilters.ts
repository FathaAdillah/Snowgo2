import type { paths } from '@snowego/api-types'

type StaffQuery = NonNullable<paths['/admin/staff']['get']['parameters']['query']>
export type StaffStatusFilter = NonNullable<StaffQuery['status']> | 'all'

export function useStaffFilters() {
  const query = ref('')
  const statusFilter = ref<StaffStatusFilter>('all')
  const params = ref<StaffQuery>({ page: 1 })

  function changePage(page: number) {
    if (page !== params.value.page) params.value = { ...params.value, page }
  }

  function applyFilters() {
    const search = query.value.trim() || undefined
    const status = statusFilter.value === 'all' ? undefined : statusFilter.value
    if (search !== params.value.search || status !== params.value.status) params.value = { page: 1, search, status }
  }

  watch(query, (_, __, onCleanup) => {
    const timer = setTimeout(applyFilters, 300)
    onCleanup(() => clearTimeout(timer))
  })
  watch(statusFilter, applyFilters)
  return { query, statusFilter, params, changePage, applyFilters }
}
