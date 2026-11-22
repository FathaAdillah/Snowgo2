// Shared envelope (docs/05-API-CONTRACT.md). Resource types are generated into
// ./openapi.d.ts from the Scramble export once the contract pipeline (TASK-004) lands.

export interface ApiMeta {
  request_id: string
  [key: string]: unknown
}

export interface ApiSuccess<T> {
  success: true
  message: string
  data: T
  meta: ApiMeta
}

export interface ApiError {
  success: false
  message: string
  code: string
  errors?: Record<string, string[]>
  meta: ApiMeta
}

export type ApiResponse<T> = ApiSuccess<T> | ApiError

export interface HealthCheck {
  component: string
  status: 'ok' | 'fail'
}

export interface Health {
  status: 'ok' | 'degraded'
  checks?: HealthCheck[]
}

// API-AUTH-014 / API-AUTH-015 (docs/API-DATA-TYPES.md)
export interface MfaChallenge {
  challenge_required: true
  expires_at: string
}

export interface StaffMe {
  id: number
  name: string
  email: string
  roles?: string[]
  permissions?: string[]
}

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
