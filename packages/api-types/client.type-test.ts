import { createApiClient, multipartBody, type paths } from './src/index'

type EmailChange = '/admin/auth/profile/email' | '/admin/auth/profile/email/confirm'
declare const conflict: paths[EmailChange]['post']['responses'][409]['content']['application/json']
conflict.code satisfies 'IDENTITY_CONFLICT'

declare const cartConflict: paths['/cart/items']['post']['responses'][409]['content']['application/json']
cartConflict.code satisfies 'VERSION_CONFLICT' | 'OFFER_UNAVAILABLE'

const api = createApiClient()
const params = { header: { 'Idempotency-Key': 'upload', 'X-XSRF-TOKEN': 'csrf' } }
const checkoutBody = { cart_version: 2, address_id: '01J00000000000000000000000', quote_hash: '0'.repeat(64) }
api.POST('/checkout', { params, body: checkoutBody })
type PreviewBody = paths['/checkout/previews']['post']['requestBody']['content']['application/json']
type SubmitBody = paths['/checkout']['post']['requestBody']['content']['application/json']
false satisfies ('coupon' extends keyof PreviewBody | keyof SubmitBody ? true : false)
// @ts-expect-error Checkout cannot submit without idempotency and CSRF headers.
api.POST('/checkout', { body: checkoutBody })
declare const preview: paths['/checkout/previews']['post']['responses'][200]['content']['application/json']
preview.data.groups[0]!.due_now_amount satisfies number
preview.data.quote_hash satisfies string | undefined
const body = { owner_type: 'return', owner_ref: 'R-1', collection: 'return_evidence', file: new File([], 'evidence.mp4') }

api.POST('/files', { params, body, bodySerializer: multipartBody })
// @ts-expect-error A multipart upload requires idempotency and CSRF headers.
api.POST('/files', { body })
// @ts-expect-error Binary uploads take a File, not a filename.
api.POST('/files', { params, body: { ...body, file: 'evidence.mp4' } })
// @ts-expect-error Readiness is not a public API operation.
api.GET('/internal/readiness')

const orderNumber = { number: 'ORD-20261125-000001' }
api.POST('/orders/{number}/cancellations', { params: { ...params, path: orderNumber }, body: { reason_code: 'BUYER' } })
// @ts-expect-error Cancellation requires an active order reason.
api.POST('/orders/{number}/cancellations', { params: { ...params, path: orderNumber }, body: {} })
declare const order: paths['/orders/{number}']['get']['responses'][200]['content']['application/json']
order.data.items[0]!.qty satisfies number
order.data.address?.regions.district.code satisfies string | undefined
type OrderWrite = '/orders/{number}/cancellations' | '/orders/{number}/completions' | '/admin/orders/{number}/actions/{action}'
declare const invalidOrder: paths[OrderWrite]['post']['responses'][422]['content']['application/json']
invalidOrder.errors?.reason_code satisfies string[] | undefined
