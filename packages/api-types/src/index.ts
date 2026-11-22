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
