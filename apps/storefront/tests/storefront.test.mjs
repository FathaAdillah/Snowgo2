import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server = await createServer({
  configFile: false, plugins: [vue()], server: { middlewareMode: true, hmr: false, ws: false }, appType: 'custom'
})
after(() => server.close())

async function render(name, props, content) {
  const { default: component } = await server.ssrLoadModule('/app/components/' + name + '.vue')
  const app = createSSRApp({ render: () => h(component, props, () => content) })
  app.component('NuxtLink', { props: ['to'], setup: (props, { slots }) => () => h('a', { href: props.to }, slots.default()) })
  return renderToString(app)
}

test('an empty catalog keeps the reference copy and package icon, filtered results offer a way back', async () => {
  const catalog = await render('StoreEmpty', {})
  assert.match(catalog, /Produk belum tersedia/)
  assert.match(catalog, /Produk aktif akan muncul di halaman ini\./)
  assert.match(catalog, /class="empty-icon"/)
  assert.doesNotMatch(catalog, /href="\/shop"/)
  const filtered = await render('StoreEmpty', { filtered: true })
  assert.match(filtered, /Produk tidak ditemukan/)
  assert.match(filtered, /href="\/shop"/)
})

test('a slow or failed read cannot render the successful empty state', async () => {
  const props = { empty: true, emptyLabel: 'Belum ada pesanan.' }
  assert.match(await render('StoreResult', { ...props, status: 'pending' }), /Memuat/)
  const failed = await render('StoreResult', { ...props, status: 'error' })
  assert.match(failed, /Coba lagi/)
  assert.doesNotMatch(failed, /Belum ada pesanan/)
  assert.match(await render('StoreResult', { ...props, status: 'success' }), /Belum ada pesanan/)
  assert.match(await render('StoreResult', { empty: false, status: 'success' }, 'Pesanan siap'), /Pesanan siap/)
})

test('retrying an ambiguous checkout reuses its key and forwards the same CSRF-protected body', async () => {
  const { useRetryableCustomerWrite } = await server.ssrLoadModule('/app/composables/useCustomerAction.ts')
  const calls = []
  const write = useRetryableCustomerWrite()
  globalThis.document = { cookie: 'XSRF-TOKEN=test%2Ftoken' }
  globalThis.$fetch = async (path, options) => {
    if (path === '/sanctum/csrf-cookie') return
    calls.push({ path, ...options })
    if (calls.length === 1) throw new Error('Response lost')
    return { data: { order_numbers: ['test-order'] } }
  }
  try {
    const body = { cart_version: 4, address_id: 'address', quote_hash: 'confirmed-preview' }
    await assert.rejects(write('/checkout', body))
    assert.deepEqual(await write('/checkout', body), { data: { order_numbers: ['test-order'] } })
    await write('/checkout', { ...body, cart_version: 5 })
    assert.equal(calls[0].path, '/api/v1/checkout')
    assert.equal(calls[0].headers['X-XSRF-TOKEN'], 'test/token')
    assert.equal(calls[1].credentials, 'same-origin')
    assert.equal(calls[0].headers['Idempotency-Key'], calls[1].headers['Idempotency-Key'])
    assert.notEqual(calls[1].headers['Idempotency-Key'], calls[2].headers['Idempotency-Key'])
  } finally {
    delete globalThis.document
    delete globalThis.$fetch
  }
})
