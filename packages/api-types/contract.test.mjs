import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import Ajv2020 from 'ajv/dist/2020.js'
import { apiBase, createApiClient, multipartBody } from './src/index.ts'

const spec = JSON.parse(await readFile(new URL('../../apps/api/api.json', import.meta.url)))
const ajv = new Ajv2020({ strict: false })
ajv.addSchema(spec, 'urn:snowego:api')
const client = createApiClient({ baseUrl: new URL(apiBase, process.env.API_CONTRACT_ORIGIN).href })

function validateResponse(path, status, body) {
  const response = spec.paths[path].get.responses[status]
  const pointer = response.$ref ?? `#/paths/${path.replaceAll('/', '~1')}/get/responses/${status}`
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
