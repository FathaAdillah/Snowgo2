import type { paths } from '@snowego/api-types'

type ListPath = '/admin/warehouses' | '/admin/stock-levels' | '/admin/stock-movements' | '/admin/stock-adjustments'
export type InventoryList<P extends ListPath> = paths[P]['get']['responses'][200]['content']['application/json']
export type Warehouse = InventoryList<'/admin/warehouses'>['data'][number]
export type WarehouseChoice = { value: Warehouse['id'], label: string }
export type InventoryAction = { url: string, method: 'PATCH' | 'POST', body: Record<string, unknown>, label: string }

export const inventoryApi = '/api/v1/admin'
export const inventoryFetch = { cache: 'no-store' as const, lazy: true, deep: false, headers: { Accept: 'application/json' } }
export const movementLabels: Record<string, string> = {
  INITIAL: 'Saldo pembuka', PURCHASE: 'Penerimaan', ADJUSTMENT_IN: 'Koreksi masuk', ADJUSTMENT_OUT: 'Koreksi keluar'
}
const dateFormatter = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' })
export function inventoryTime(value: string) {
  const iso = value.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00').replace(/(\.\d{3})\d+/, '$1')
  return `${dateFormatter.format(new Date(iso))} WIB`
}

export function useInventoryPaging<T extends Record<string, string | undefined>>(filters: () => T) {
  const params = ref({ ...filters(), page: 1 })
  watch(filters, (next, _, cleanup) => {
    const timer = setTimeout(() => {
      params.value = { ...next, page: 1 }
    }, 300)
    cleanup(() => clearTimeout(timer))
  })
  const changePage = (page: number) => {
    params.value = { ...params.value, page }
  }
  return { params, changePage }
}
