import type { paths } from '@snowego/api-types'

export interface DateRange {
  from: string
  to: string
}

/** API-RPT-001 accepts at most 92 days per request. */
export const MAX_RANGE_DAYS = 92

export interface TrendPoint {
  date: string
  amount: number
  orders: number
}

function isoDate(date: Date) {
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function defaultRange(days = 14): DateRange {
  const to = new Date()
  const from = new Date(to)
  from.setDate(to.getDate() - (days - 1))
  return { from: isoDate(from), to: isoDate(to) }
}

type DashboardResponse = paths['/admin/dashboard']['get']['responses'][200]['content']['application/json']

export function useDashboard() {
  const range = ref<DateRange>(defaultRange())
  const { data, status, refresh } = useFetch<DashboardResponse>('/api/v1/admin/dashboard', {
    ...inventoryFetch,
    query: range
  })
  return { range, data, status, refresh }
}

export function formatRupiah(value: number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value)
}

export function formatCompactRupiah(value: number) {
  if (value >= 1_000_000_000) {
    return `Rp ${(value / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} M`
  }
  if (value >= 1_000_000) {
    return `Rp ${(value / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`
  }
  return formatRupiah(value)
}
