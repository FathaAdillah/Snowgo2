import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import Ajv2020 from 'ajv/dist/2020.js'
import { apiBase, createApiClient, multipartBody } from './src/index.ts'

const spec = JSON.parse(await readFile(new URL('../../apps/api/api.json', import.meta.url)))
const ajv = new Ajv2020({ strict: false })
ajv.addSchema(spec, 'urn:snowego:api')
const client = createApiClient({ baseUrl: new URL(apiBase, process.env.API_CONTRACT_ORIGIN).href })

function validateResponse(path, status, body, method = 'get') {
  const response = spec.paths[path][method].responses[status]
  const pointer = response.$ref ?? `#/paths/${path.replaceAll('/', '~1')}/${method}/responses/${status}`
  const validate = ajv.compile({ $ref: `urn:snowego:api${pointer}/content/application~1json/schema` })
  assert.ok(validate(body), ajv.errorsText(validate.errors))
}

test('public health and private file errors match their published response schemas', async () => {
  const health = await client.GET('/health')
  assert.equal(health.response.status, 200)
  validateResponse('/health', 200, health.data)
  assert.equal(health.data.meta.request_id, health.response.headers.get('x-request-id'))

  const file = await client.GET('/files/{id}', { params: { path: { id: '01J00000000000000000000000' } } })
  assert.equal(file.response.status, 401)
  validateResponse('/files/{id}', 401, file.error)
  assert.equal(file.error.code, 'UNAUTHENTICATED')
  assert.equal(file.error.meta.request_id, file.response.headers.get('x-request-id'))
  assert.ok(!Object.keys(spec.paths).some(path => path.startsWith('/internal/')))
})

test('public delivery regions match the paginated contract without exposing internal IDs', async () => {
  const result = await client.GET('/regions', { params: { query: { parent: 'ID', kind: 'PROVINCE', per_page: 1 } } })
  assert.equal(result.response.status, 200)
  validateResponse('/regions', 200, result.data)
  if (process.env.CONTRACT_REGION_CODE) {
    assert.equal(result.data.data[0]?.code, process.env.CONTRACT_REGION_CODE, 'expected CI province fixture')
  }
  for (const region of result.data.data) {
    assert.equal(region.parent_code, 'ID')
    assert.deepEqual(Object.keys(region).sort(), ['code', 'kind', 'name', 'parent_code'])
  }
})

test('the file client sends a multipart File and the required headers', async () => {
  const upload = createApiClient({
    baseUrl: 'http://example.test/api/v1',
    fetch: async (request) => {
      assert.equal(request.headers.get('Idempotency-Key'), 'upload-test')
      assert.equal(request.headers.get('X-XSRF-TOKEN'), 'test-csrf')
      const form = await request.formData()
      assert.equal(form.get('file').name, 'proof.pdf')
      assert.equal(form.get('owner_ref'), 'P-1')
      return Response.json({}, { status: 202 })
    }
  })
  await upload.POST('/files', {
    params: { header: { 'Idempotency-Key': 'upload-test', 'X-XSRF-TOKEN': 'test-csrf' } },
    body: { owner_type: 'payment', owner_ref: 'P-1', collection: 'payment_proof', file: new File(['fixture'], 'proof.pdf') },
    bodySerializer: multipartBody
  })
})

test('customer account endpoints reject guests with the documented error envelope', async () => {
  for (const path of ['/profile', '/addresses', '/wishlist', '/cart', '/orders']) {
    const result = await client.GET(path)
    assert.equal(result.response.status, 401)
    validateResponse(path, 401, result.error)
    assert.equal(result.error.code, 'UNAUTHENTICATED')
  }
  const path = '/orders/{number}'
  const result = await client.GET(path, { params: { path: { number: 'O-0000000000' } } })
  assert.equal(result.response.status, 401)
  validateResponse(path, 401, result.error)
})

test('public catalog and missing product use their published schemas', async () => {
  for (const path of ['/products', '/categories']) {
    const result = await client.GET(path, { params: { query: { per_page: 1 } } })
    assert.equal(result.response.status, 200)
    validateResponse(path, 200, result.data)
  }
  if (process.env.CONTRACT_PRODUCT_SLUG) {
    const slug = process.env.CONTRACT_PRODUCT_SLUG
    const result = await client.GET('/products/{slug}', { params: { path: { slug } } })
    assert.equal(result.response.status, 200)
    validateResponse('/products/{slug}', 200, result.data)
    assert.equal(result.data.data.variants[0].offers[0].unit_amount, 25000)
    assert.equal(result.data.data.variants[0].offers[0].available, true)
    const list = await client.GET('/products', { params: { query: { method: 'RTS', q: 'fixture' } } })
    validateResponse('/products', 200, list.data)
    assert.equal(list.data.data[0]?.slug, slug)
    assert.equal(list.data.data[0]?.offers[0].available, true)
  }
  const missing = await client.GET('/products/{slug}', { params: { path: { slug: 'ci-missing-5b025cdf' } } })
  assert.equal(missing.response.status, 404)
  validateResponse('/products/{slug}', 404, missing.error)
})

test('checkout data and mutations reject unauthenticated API callers', async () => {
  for (const path of ['/checkout/previews', '/checkout', '/checkouts/{id}']) {
    const read = path.endsWith('{id}')
    const result = read ? await client.GET(path, { params: { path: { id: '01J00000000000000000000000' } } }) : await client.POST(path, { body: {} })
    assert.equal(result.response.status, 401)
    validateResponse(path, 401, result.error, read ? 'get' : 'post')
  }
})

test('public preorder campaigns and customer allocation reads use distinct boundaries', async () => {
  const page = await client.GET('/preorders', { params: { query: { per_page: 1 } } })
  assert.equal(page.response.status, 200)
  validateResponse('/preorders', 200, page.data)
  const params = { path: { slug: 'ci-missing-5b025cdf' } }
  for (const [path, status] of [['/preorders/{slug}', 404], ['/preorders/{slug}/allocations', 401]]) {
    const result = await client.GET(path, { params })
    assert.equal(result.response.status, status)
    validateResponse(path, status, result.error)
  }
})

test('order, campaign and inventory reads are staff-only and use the documented error envelope', async () => {
  for (const path of ['/admin/procurement-batches', '/admin/procurement-batches/eligible-items', '/admin/preorder-campaigns', '/admin/orders', '/admin/warehouses', '/admin/stock-levels', '/admin/stock-movements', '/admin/stock-adjustments', '/admin/stock-reservations']) {
    const result = await client.GET(path)
    assert.ok([401, 403].includes(result.response.status))
    validateResponse(path, result.response.status, result.error)
  }
})

test('procurement detail and every mutation reject public callers with the published envelope', async () => {
  const base = '/admin/procurement-batches'
  for (const [method, path] of [['GET', `${base}/{number}`], ['POST', base], ...['stage-events', 'receipts', 'closures'].map(s => ['POST', `${base}/{number}/${s}`])]) {
    const result = await client[method](path, { params: { path: { number: 'BAT-UNASSIGNED' } }, ...(method === 'POST' && { body: {} }) })
    assert.ok([401, 403, 419].includes(result.response.status))
    validateResponse(path, result.response.status, result.error, method.toLowerCase())
  }
})

test('request orders keep both case surfaces private and document every thread mutation', async () => {
  for (const base of ['/request-orders', '/admin/request-orders']) {
    const staff = base.startsWith('/admin')
    const routes = [['GET', base], ['GET', `${base}/{number}`], ['POST', `${base}/{number}/messages`],
      ['POST', `${base}/{number}/${staff ? 'reviews' : 'cancellations'}`]]
    if (!staff) routes.push(['POST', base])
    for (const [method, path] of routes) {
      if (!staff && method === 'POST') {
        assert.equal(spec.paths[path].post.responses[429].headers['Retry-After'].schema.type, 'string')
      }
      const result = await client[method](path, { params: { path: { number: 'REQ-MISSING' } }, ...(method === 'POST' && { body: {} }) })
      assert.ok([401, 403, 419].includes(result.response.status))
      validateResponse(path, result.response.status, result.error, method.toLowerCase())
    }
  }
})
