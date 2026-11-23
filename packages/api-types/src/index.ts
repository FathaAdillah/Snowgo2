import createClient, { type ClientOptions } from 'openapi-fetch'
import type { components, paths } from './openapi'

export type { components, operations, paths } from './openapi'

export const apiBase = '/api/v1'
export function createApiClient(options: ClientOptions = {}) {
  return createClient<paths>({ baseUrl: apiBase, credentials: 'same-origin', ...options })
}

/** Pass as bodySerializer for multipart operations; fetch supplies the boundary. */
export function multipartBody(body: Record<string, string | Blob>): FormData {
  const form = new FormData()
  for (const [name, value] of Object.entries(body)) form.append(name, value)
  return form
}

export type HealthResponse = paths['/health']['get']['responses'][200]['content']['application/json']
export type ApiError = components['schemas']['ApiError']
export type ApiMeta = components['schemas']['ApiMeta']

export interface ApiSuccess<T> {
  success: true
  message: string
  data: T
  meta: ApiMeta
}

export type ApiResponse<T> = ApiSuccess<T> | ApiError

// API-AUTH-014 / API-AUTH-015 (docs/API-DATA-TYPES.md)
export type MfaChallenge = paths['/admin/auth/login']['post']['responses'][200]['content']['application/json']['data']
export type StaffMe = paths['/admin/auth/me']['get']['responses'][200]['content']['application/json']['data']
export type StaffList = paths['/admin/staff']['get']['responses'][200]['content']['application/json']
export type StaffRoleOptions = paths['/admin/staff/role-options']['get']['responses'][200]['content']['application/json']
export type RoleList = paths['/admin/roles']['get']['responses'][200]['content']['application/json']
export type RoleDetail = paths['/admin/roles/{id}']['get']['responses'][200]['content']['application/json']
export type PermissionCatalog = paths['/admin/permissions']['get']['responses'][200]['content']['application/json']
export type Enrollment = paths['/admin/auth/mfa-enrollments']['post']['responses'][200]['content']['application/json']['data']
export type RecoveryCodes = paths['/admin/auth/mfa-enrollment-verifications']['post']['responses'][200]['content']['application/json']['data']

// API-RPT-001 GET /admin/dashboard
export type PurchaseMethod = 'ready_stock' | 'preorder' | 'request_order'

export interface DashboardMethodSummary {
  method: PurchaseMethod
  orders_count: number
  paid_amount: number
}

export interface Dashboard {
  orders_count: number
  by_method: DashboardMethodSummary[]
  paid_amount: number
  refund_amount: number
  net_amount: number
  pending_payments: number
  shipment_failures: number
  open_returns: number
  open_refunds: number
  low_stock_count: number
}
