import type { paths } from '@snowego/api-types'

export type RequestQueue = paths['/admin/request-orders']['get']['responses'][200]['content']['application/json']
export type RequestDetail = paths['/admin/request-orders/{number}']['get']['responses'][200]['content']['application/json']
export type RequestCase = RequestDetail['data']
export type FileDownload = paths['/admin/files/{id}/download']['get']['responses'][200]['content']['application/json']
export const caseApi = '/api/v1/admin/request-orders'
export const caseStatuses: Record<string, string> = {
  SUBMITTED: 'Diajukan', UNDER_REVIEW: 'Dalam review', NEED_INFORMATION: 'Menunggu informasi', QUOTED: 'Ditawarkan',
  REVISION_REQUESTED: 'Diminta revisi', CONVERTED_TO_ORDER: 'Menjadi pesanan', EXPIRED: 'Kedaluwarsa', REJECTED: 'Ditolak', CANCELLED: 'Dibatalkan'
}
export const reviewLabels: Record<string, string> = {
  START: 'Mulai review', NEED_INFORMATION: 'Minta informasi', REJECT: 'Tolak request', REOPEN: 'Buka kembali'
}

export function useRequestOrders() {
  const { can } = useAdminAuth()
  return { may: (action: string) => can('request_orders.request.view') && can('request_orders.request.review') && can(`request_orders.request.${action}`) }
}
