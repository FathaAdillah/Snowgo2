import type { ApiSuccess, Dashboard } from '@snowego/api-types'

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

function seeded(seed: number) {
  const x = Math.sin(seed) * 10000
  return x - Math.floor(x)
}

function sampleTrend(range: DateRange): TrendPoint[] {
  const points: TrendPoint[] = []
  const cursor = new Date(`${range.from}T00:00:00`)
  const end = new Date(`${range.to}T00:00:00`)
  while (cursor <= end) {
    const seed = cursor.getTime() / 86_400_000
    const orders = Math.round(4 + seeded(seed) * 14)
    points.push({ date: isoDate(cursor), orders, amount: orders * Math.round(180_000 + seeded(seed + 1) * 240_000) })
    cursor.setDate(cursor.getDate() + 1)
  }
  return points
}

function sampleDashboard(trend: TrendPoint[]): Dashboard {
  const orders = trend.reduce((sum, point) => sum + point.orders, 0)
  const paid = trend.reduce((sum, point) => sum + point.amount, 0)
  const refund = Math.round(paid * 0.03)
  return {
    orders_count: orders,
    by_method: [
      { method: 'ready_stock', orders_count: Math.round(orders * 0.62), paid_amount: Math.round(paid * 0.55) },
      { method: 'preorder', orders_count: Math.round(orders * 0.28), paid_amount: Math.round(paid * 0.33) },
      { method: 'request_order', orders_count: Math.round(orders * 0.1), paid_amount: Math.round(paid * 0.12) }
    ],
    paid_amount: paid,
    refund_amount: refund,
    net_amount: paid - refund,
    pending_payments: 7,
    shipment_failures: 2,
    open_returns: 3,
    open_refunds: 1,
    low_stock_count: 5
  }
}

/**
 * Dashboard metrics (API-RPT-001). Until the Reporting endpoint ships, the panel shows clearly
 * labelled sample data so the layout can be reviewed.
 */
export function useDashboard() {
  const range = ref<DateRange>(defaultRange())

  const { data, status, refresh } = useAsyncData(
    'admin-dashboard',
    async () => {
      const trend = sampleTrend(range.value)
      try {
        const response = await $fetch<ApiSuccess<Dashboard>>('/api/v1/admin/dashboard', {
          query: range.value,
          credentials: 'include',
          headers: { Accept: 'application/json' }
        })
        return { summary: response.data, trend, sample: false }
      } catch {
        return { summary: sampleDashboard(trend), trend, sample: true }
      }
    },
    { server: false, watch: [range] }
  )

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
