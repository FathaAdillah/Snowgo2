import type { paths } from '@snowego/api-types'
import type { InventoryAction } from './useInventory'

export type BatchList = paths['/admin/procurement-batches']['get']['responses'][200]['content']['application/json']
export type BatchDetail = paths['/admin/procurement-batches/{number}']['get']['responses'][200]['content']['application/json']
export type Batch = BatchDetail['data']
export type EligibleList = paths['/admin/procurement-batches/eligible-items']['get']['responses'][200]['content']['application/json']
export type EligibleItem = EligibleList['data'][number]
export type BatchAction = InventoryAction
export const batchApi = '/api/v1/admin/procurement-batches'
export const batchStatuses: Record<string, string> = { OPEN: 'Terbuka', IN_PROGRESS: 'Dalam proses', RECEIVED: 'Diterima', CANCELLED: 'Dibatalkan', CLOSED: 'Ditutup' }
export const batchStages: Record<string, string> = {
  PENDING_PURCHASE: 'Menunggu pembelian', PURCHASED: 'Sudah dibeli', SELLER_SHIPPED: 'Dikirim pemasok',
  AT_ORIGIN_WAREHOUSE: 'Di gudang asal', INTERNATIONAL_TRANSIT: 'Transit internasional',
  AT_DESTINATION_WAREHOUSE: 'Di gudang tujuan', IMPORT_FEE_ASSESSMENT: 'Penilaian biaya impor',
  RECEIVED: 'Barang diterima', FAILED: 'Pengadaan gagal'
}
export function useProcurement() {
  const { can } = useAdminAuth()
  return { may: (action: 'create' | 'stage' | 'receipt' | 'close') => can('procurement.batch.view') && can('procurement.batch.manage') && can(`procurement.batch.${action}`) }
}
