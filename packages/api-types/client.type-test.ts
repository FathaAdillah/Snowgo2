import { createApiClient, multipartBody, type paths } from './src/index'

type EmailChange = '/admin/auth/profile/email' | '/admin/auth/profile/email/confirm'
declare const conflict: paths[EmailChange]['post']['responses'][409]['content']['application/json']
conflict.code satisfies 'IDENTITY_CONFLICT'

declare const cartConflict: paths['/cart/items']['post']['responses'][409]['content']['application/json']
cartConflict.code satisfies 'VERSION_CONFLICT' | 'OFFER_UNAVAILABLE'

const api = createApiClient()
const params = { header: { 'Idempotency-Key': 'upload', 'X-XSRF-TOKEN': 'csrf' } }
const body = { owner_type: 'return', owner_ref: 'R-1', collection: 'return_evidence', file: new File([], 'evidence.mp4') }

api.POST('/files', { params, body, bodySerializer: multipartBody })
// @ts-expect-error A multipart upload requires idempotency and CSRF headers.
api.POST('/files', { body })
// @ts-expect-error Binary uploads take a File, not a filename.
api.POST('/files', { params, body: { ...body, file: 'evidence.mp4' } })
// @ts-expect-error Readiness is not a public API operation.
api.GET('/internal/readiness')
