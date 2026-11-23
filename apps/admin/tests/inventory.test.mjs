import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { computed, createSSRApp, h, reactive, ref, useTemplateRef, watch } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server = await createServer({
  configFile: false, plugins: [vue()], resolve: { alias: { '~': fileURLToPath(new URL('../app', import.meta.url)) } },
  server: { middlewareMode: true, hmr: false, ws: false }, appType: 'custom'
})
after(() => server.close())
const load = async path => (await server.ssrLoadModule(`/app/${path}`)).default
const inventory = await server.ssrLoadModule('/app/composables/useInventory.ts')
const { adminNavigation, hasPageAccess } = await server.ssrLoadModule('/app/composables/useAdminNavigation.ts')
const components = {}
for (const name of ['InventoryListTable', 'InventoryWarehouseList', 'InventoryAdjustmentList', 'InventoryStockForm', 'InventoryWarehouseForm', 'InventoryWarehousePicker']) {
  components[name] = await load(`components/${name}.vue`)
}
const wh = { id: 9, code: 'BDG', name: 'Bandung', active: true, is_default: false, district_code: '11.01.01', address: 'Jalan A' }

async function render(path, grants, fixture = {}, props = {}) {
  const requests = []
  const buttons = {}
  let pager, retry, state, meta
  Object.assign(globalThis, inventory, {
    computed, reactive, ref, watch, useTemplateRef, useHead() {}, definePageMeta(value) { meta = value },
    useRoute: () => ({ query: fixture.route ?? {} }), useAdminAuth: () => ({ can: grant => grants.includes(grant) }),
    useFetch(url, options) {
      requests.push({ url, query: options.query })
      return {
        data: ref({ data: fixture.rows ?? [wh], meta: { page: 1, per_page: 20, total: fixture.total ?? 1, next_cursor: fixture.cursor } }),
        status: ref(fixture.status ?? 'success'), refresh: () => requests.push({ url })
      }
    }
  })
  const component = await load(`${path}.vue`)
  const app = createSSRApp({ ...component, setup: (p, ctx) => (state = component.setup(p, ctx)) }, props)
  for (const [name, value] of Object.entries(components)) app.component(name, value)
  for (const name of ['UCard', 'UFormField', 'UBadge', 'UInput', 'USelect', 'USelectMenu', 'UCheckbox', 'UTextarea', 'ConfirmAdminAction']) {
    app.component(name, (_, { attrs, slots }) => h('div', [attrs.label, slots.header?.(), slots.default?.()]))
  }
  app.component('UAlert', (_, { attrs }) => {
    retry = attrs.actions?.[0].onClick
    return h('aside', attrs.title)
  })
  app.component('UButton', (_, { attrs }) => {
    const label = attrs.label ?? attrs['aria-label']
    buttons[label] = attrs
    return h('button', label)
  })
  app.component('UPagination', (_, { attrs }) => {
    pager = attrs
    return h('nav')
  })
  app.component('UTable', (_, { attrs, slots }) => {
    if (attrs.loading) return h('div', slots.loading())
    if (!attrs.data.length) return h('div', attrs.empty)
    return h('table', attrs.data.map(original => h('tr', attrs.columns.map(({ accessorKey, id }) =>
      h('td', slots[`${id ?? accessorKey}-cell`]?.({ row: { original } }) ?? original[accessorKey])))))
  })
  const html = await renderToString(app)
  return { html, buttons, requests, state, pager, retry, meta }
}

test('inventory navigation matches page permissions and only offers implemented destinations', async () => {
  const entries = adminNavigation.find(entry => entry.label === 'Inventori').children
  for (const [grant, visible] of [
    ['inventory.warehouse.view', 'warehouses'], ['inventory.warehouse.create', 'warehouses'], ['inventory.stock.view', 'stock'],
    ['inventory.movement.view', 'movements'], ['inventory.receipt.create', 'operations'],
    ['inventory.adjustment.create', 'operations'], ['inventory.adjustment.view', 'operations'], ['inventory.reservation.view', null]
  ]) {
    const allowed = entries.filter(item => hasPageAccess(item.permission, p => p === grant))
    assert.deepEqual(allowed.map(item => item.to), visible ? [`/inventory/${visible}`] : [])
    if (visible) assert.deepEqual((await render(`pages/inventory/${visible}`, [grant])).meta.permission, allowed[0].permission)
  }
  assert.equal(entries.filter(entry => hasPageAccess(entry.permission, () => true)).length, 4)
})

test('warehouse screen separates create-only, view, update and archive', async () => {
  const show = (...permissions) => render('pages/inventory/warehouses', permissions.map(p => `inventory.warehouse.${p}`))
  const createOnly = await show('create')
  assert.match(createOnly.html, /Tambah Gudang/)
  assert.deepEqual(createOnly.requests, [])
  const reader = await show('view')
  assert.match(reader.html, /Bandung/)
  assert.doesNotMatch(reader.html, /Tambah Gudang|Edit gudang|Arsipkan gudang/)
  assert.doesNotMatch((await show('view', 'update')).html, /Arsipkan gudang/)
  const editor = await show('view', 'update', 'archive')
  assert.match(editor.html, /Edit gudang BDG/)
  assert.match(editor.html, /Arsipkan gudang BDG/)
  const list = await render('components/InventoryWarehouseList', ['inventory.warehouse.update', 'inventory.warehouse.archive'])
  list.buttons['Arsipkan gudang BDG'].onClick()
  assert.deepEqual(list.state.action.value.body, { active: false })
  assert.equal(list.state.action.value.url, '/api/v1/admin/warehouses/9')
})

test('warehouse forms send create payloads and only modified fields on edit', async () => {
  const fields = { code: wh.code, name: wh.name, address: wh.address, district_code: wh.district_code }
  const create = (await render('components/InventoryWarehouseForm', ['inventory.warehouse.create'])).state
  Object.assign(create.form, fields)
  create.submit()
  assert.deepEqual(create.action.value.body, { ...fields, active: true, is_default: false })
  const edit = (await render('components/InventoryWarehouseForm', ['inventory.warehouse.update'], {}, { warehouse: wh })).state
  edit.form.name = 'Bandung Baru'
  edit.promote.value = true
  edit.submit()
  assert.deepEqual(edit.action.value.body, { name: 'Bandung Baru', is_default: true })
})

test('stock pagination and list states stay distinct; retries reuse the endpoint', async () => {
  const show = fixture => render('pages/inventory/stock', ['inventory.stock.view'], fixture)
  for (const total of [1, 45]) {
    const list = await show({ total })
    assert.equal(list.pager.total, total)
    assert.ok(Object.hasOwn(list.pager, 'show-controls'))
    assert.doesNotMatch(list.html, /Koreksi saldo|Lihat mutasi saldo/)
    if (total > 20) {
      list.pager['onUpdate:page'](2)
      assert.equal(list.requests[0].query.value.page, 2)
    }
  }
  assert.match((await show({ rows: [], total: 0 })).html, /Tidak ada data/)
  assert.match((await show({ status: 'pending', rows: [] })).html, /Memuat data/)
  const failure = await show({ status: 'error' })
  assert.match(failure.html, /tidak dapat dimuat/)
  assert.equal(failure.pager, undefined)
  failure.retry()
  assert.deepEqual(failure.requests.map(request => request.url), Array(2).fill('/api/v1/admin/stock-levels'))
})

test('movement cursor round-trip and WIB date filters use server-side query values', async () => {
  const { requests, buttons, state } = await render('pages/inventory/movements', ['inventory.movement.view'], { route: { level: '9' }, cursor: 'MTA=' })
  const query = requests[0].query
  assert.equal(query.value.level_id, 9)
  buttons.Berikutnya.onClick()
  assert.equal(query.value.cursor, 'MTA=')
  buttons.Sebelumnya.onClick()
  assert.equal(query.value.cursor, undefined)
  buttons.Berikutnya.onClick()
  state.from.value = state.to.value = '2026-11-24'
  state.type.value = 'PURCHASE'
  state.apply()
  assert.deepEqual(query.value, { level_id: 9, type: 'PURCHASE', cursor: undefined, from: '2026-11-24T00:00:00+07:00', to: '2026-11-24T23:59:59.999999+07:00' })
  assert.match(inventory.inventoryTime('2026-11-23 17:15:00.123456+00'), /24 Nov 2026.*00.15 WIB/)
})

test('operations permission gates protect history requests and transaction buttons independently', async () => {
  const show = grant => render('pages/inventory/operations', [`inventory.${grant}`])
  const read = await show('adjustment.view')
  assert.match(read.html, /Riwayat koreksi/)
  assert.doesNotMatch(read.html, /Terima Stok|Koreksi Stok/)
  assert.equal(read.requests[0].url, '/api/v1/admin/stock-adjustments')
  for (const [grant, label] of [['receipt.create', 'Terima Stok'], ['adjustment.create', 'Koreksi Stok']]) {
    const write = await show(grant)
    assert.deepEqual(Object.keys(write.buttons), [label])
    assert.deepEqual(write.requests, [])
  }
})

test('receipt and correction confirm signed quantities against their own endpoints', async () => {
  const receipt = await render('components/InventoryStockForm', ['inventory.receipt.create'], {}, { mode: 'receipt' })
  assert.deepEqual(receipt.requests, [])
  assert.match(receipt.html, /ID gudang aktif/)
  receipt.state.reason.value = ' COUNT '
  receipt.state.warehouse.value = 9
  receipt.state.sku.value = ' SKU-1 '
  receipt.state.quantity.value = -2
  receipt.state.submit()
  assert.equal(receipt.state.action.value, undefined)
  receipt.state.quantity.value = 2
  receipt.state.submit()
  assert.equal(receipt.state.action.value.url, '/api/v1/admin/stock-receipts')
  assert.deepEqual(receipt.state.action.value.body, { reason_code: 'COUNT', note: null, sku: 'SKU-1', warehouse_id: 9, qty: 2, type: 'PURCHASE' })
  const withPicker = await render('components/InventoryStockForm', ['inventory.receipt.create', 'inventory.warehouse.view'], {}, { mode: 'receipt' })
  assert.equal(withPicker.requests[0].url, '/api/v1/admin/warehouses')
  assert.equal(withPicker.requests[0].query.value.active, '1')
  const correction = (await render('components/InventoryStockForm', ['inventory.adjustment.create'], {}, { mode: 'adjustment', initialLevel: 7 })).state
  correction.reason.value = 'COUNT'
  correction.quantity.value = 0
  correction.submit()
  assert.equal(correction.action.value, undefined)
  correction.quantity.value = -3
  correction.note.value = 'Selisih fisik'
  correction.submit()
  assert.equal(correction.action.value.url, '/api/v1/admin/stock-adjustments')
  assert.deepEqual(correction.action.value.body, { reason_code: 'COUNT', note: 'Selisih fisik', level_id: 7, delta: -3 })
})
