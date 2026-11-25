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
const procurement = await server.ssrLoadModule('/app/composables/useProcurement.ts')
const requestOrders = await server.ssrLoadModule('/app/composables/useRequestOrders.ts')
const { adminNavigation, hasPageAccess } = await server.ssrLoadModule('/app/composables/useAdminNavigation.ts')
const components = {}
for (const name of ['InventoryListTable', 'InventoryWarehouseList', 'InventoryAdjustmentList', 'InventoryStockForm', 'InventoryWarehouseForm', 'InventoryWarehousePicker']) {
  components[name] = await load(`components/${name}.vue`)
}
const wh = { id: 9, code: 'BDG', name: 'Bandung', active: true, is_default: false, district_code: '11.01.01', address: 'Jalan A' }

test('quotation controls follow per-action grants and cannot edit a frozen version', async () => {
  const quote = { number: 'QUO-1', version: 1, revision: 3, term: 'FULL', total_amount: 100, valid_until: '2027-01-01T00:00:00Z', items: [], terms: 'Goods only' }
  const show = (status, ...actions) => render('components/RequestQuotations', actions.map(a => `request_orders.quotation.${a}`), {},
    { record: { number: 'REQ-1', status: 'QUOTED', items: [], quotations: [{ ...quote, status }] } })
  assert.doesNotMatch((await show('DRAFT', 'view')).html, /Edit draf|Tarik penawaran|Kirim penawaran/)
  assert.doesNotMatch((await show('DRAFT', 'dispatch')).html, /Kirim penawaran/)
  const dispatcher = await show('DRAFT', 'manage', 'dispatch')
  dispatcher.buttons['Kirim penawaran'].onClick()
  assert.equal(dispatcher.state.action.value.url, '/api/v1/admin/request-orders/REQ-1/quotations/QUO-1/dispatches')
  assert.deepEqual(dispatcher.state.action.value.body, { revision: 3 })
  const sent = await show('SENT', 'manage', 'update', 'withdraw', 'create')
  assert.match(sent.html, /Tarik penawaran/)
  assert.match(sent.html, /Buat penawaran/)
  assert.doesNotMatch(sent.html, /Edit draf|Kirim penawaran/)
})

async function render(path, grants, fixture = {}, props = {}) {
  const requests = []
  const buttons = {}
  let pager, retry, state, meta
  Object.assign(globalThis, inventory, procurement, requestOrders, {
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
  for (const name of ['UCard', 'UFormField', 'UBadge', 'UInput', 'USelect', 'USelectMenu', 'UCheckbox', 'UTextarea', 'ConfirmAdminAction', 'ProcurementCreate']) {
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
    ['inventory.adjustment.create', 'operations'], ['inventory.adjustment.view', 'operations'], ['inventory.reservation.view', 'reservations'], ['inventory.transfer.manage', null]
  ]) {
    const allowed = entries.filter(item => hasPageAccess(item.permission, p => p === grant))
    assert.deepEqual(allowed.map(item => item.to), visible ? [`/inventory/${visible}`] : [])
    if (visible) assert.deepEqual((await render(`pages/inventory/${visible}`, [grant])).meta.permission, allowed[0].permission)
  }
  assert.equal(entries.filter(entry => hasPageAccess(entry.permission, () => true)).length, 5)
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

test('Commerce exposes a usable procurement page only with read permission', async () => {
  const link = adminNavigation.find(e => e.label === 'Commerce').children.find(e => e.label === 'Batch Pengadaan')
  assert.equal(link.to, '/procurement')
  assert.equal(hasPageAccess(link.permission, p => p === 'procurement.batch.manage'), false)
  assert.equal(hasPageAccess(link.permission, p => p === 'procurement.batch.view'), true)
  const show = (...grants) => render('pages/procurement/index', grants.map(g => `procurement.batch.${g}`), { rows: [{ number: 'BAT-1', status: 'OPEN' }] })
  const read = await show('view')
  assert.equal(read.meta.permission, link.permission)
  assert.equal(read.buttons['Lihat batch BAT-1'].to, '/procurement/BAT-1')
  assert.doesNotMatch(read.html, /Tambah Batch/)
  assert.doesNotMatch((await show('view', 'manage')).html, /Tambah Batch/)
  assert.match((await show('view', 'manage', 'create')).html, /Tambah Batch/)
})

test('procurement actions require individual grants and a valid lifecycle state', async () => {
  const batch = { number: 'BAT-1', status: 'IN_PROGRESS', stage: 'PURCHASED', next_stages: ['SELLER_SHIPPED', 'FAILED'], closure_target: null,
    items: [{ order_item_id: 'I1', order_number: 'O1', sku: 'A', received_qty: 1, failed_qty: 0, qty: 3 }] }
  const show = (b, ...grants) => render('components/ProcurementActions', ['view', 'manage', ...grants].map(g => `procurement.batch.${g}`), {}, { batch: b })
  assert.deepEqual((await show(batch)).buttons, {})
  assert.deepEqual(Object.keys((await show(batch, 'stage')).buttons), ['Catat tahap'])
  assert.deepEqual(Object.keys((await show({ ...batch, stage: 'FAILED', next_stages: [], closure_target: 'CANCELLED' }, 'receipt', 'stage', 'close')).buttons), ['Batalkan batch'])
  const receive = await show(batch, 'receipt')
  receive.buttons['Catat penerimaan atau kekurangan'].onClick()
  receive.state.received.value = 1
  receive.state.failed.value = 1
  receive.state.submit()
  assert.equal(receive.state.action.value, undefined)
  receive.state.reason.value = 'SHORT'
  receive.state.submit()
  assert.deepEqual(receive.state.action.value.body, { reason_code: 'SHORT', items: [{ order_item_id: 'I1', received_qty: 1, failed_qty: 1 }] })
  assert.match(receive.state.action.value.url, /BAT-1\/receipts$/)
})

test('request queue menu and detail link require the case read permission', async () => {
  const link = adminNavigation.find(e => e.label === 'Commerce').children.find(e => e.label === 'Request Order')
  assert.equal(link.to, '/request-orders')
  assert.equal(hasPageAccess(link.permission, p => p === 'request_orders.request.review'), false)
  const list = await render('pages/request-orders/index', ['request_orders.request.view'], { rows: [{ number: 'REQ-1', status: 'SUBMITTED', title: 'Album' }] })
  assert.equal(list.meta.permission, link.permission)
  assert.equal(list.buttons['Lihat request REQ-1'].to, '/request-orders/REQ-1')
  assert.equal(list.requests[0].url, '/api/v1/admin/request-orders')
})

test('separates internal drafts, customer replies and state-dependent review actions', async () => {
  const record = { number: 'REQ-1', can_message: true, review_actions: ['START', 'REJECT'] }
  const show = (...grants) => render('components/RequestCaseActions', ['view', ...grants].map(g => `request_orders.request.${g}`), {}, { record })
  assert.deepEqual((await show()).buttons, {})
  assert.deepEqual((await show('review', 'note')).buttons['Balas pelanggan'], undefined)
  const edit = await show('review', 'reply', 'note', 'start')
  assert.deepEqual(Object.keys(edit.buttons), ['Mulai review', 'Balas pelanggan', 'Catatan internal'])
  edit.buttons['Catatan internal'].onClick()
  edit.state.text.value = 'Rahasia'
  edit.state.submit()
  assert.deepEqual(edit.state.action.value.body, { visibility: 'STAFF', body: 'Rahasia' })
  edit.buttons['Balas pelanggan'].onClick()
  assert.equal(edit.state.text.value, '')
  assert.equal(edit.state.action.value, undefined)
  edit.state.text.value = 'Bisa dipesan.'
  edit.state.submit()
  assert.deepEqual(edit.state.action.value.body, { visibility: 'CUSTOMER', body: 'Bisa dipesan.' })
  edit.buttons['Mulai review'].onClick()
  edit.state.submit()
  assert.deepEqual(edit.state.action.value.body, { decision: 'START' })
  assert.match(edit.state.action.value.url, /REQ-1\/reviews$/)
  Object.assign(record, { can_message: false, review_actions: [] })
  assert.deepEqual((await show('review', 'reply', 'note', 'start')).buttons, {})
})

test('reservation table shows partial dispatch without offering stock commands', async () => {
  const row = { id: 17, order_number: 'RTS-42', sku: 'ALB', status: 'COMMITTED', qty: 7, shipped_qty: 3, released_qty: 1, expires_at: '2026-11-24T12:00:00Z' }
  const list = await render('pages/inventory/reservations', ['inventory.reservation.view'], { rows: [row], total: 21 })
  assert.match(list.html, /RTS-42/)
  assert.match(list.html, /Dialokasikan/)
  assert.deepEqual(list.buttons, {})
  assert.equal(list.requests[0].url, '/api/v1/admin/stock-reservations')
  list.pager['onUpdate:page'](2)
  assert.equal(list.requests[0].query.value.page, 2)
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

test('unscoped sidebar navigation remounts the ledger and correction form without the previous level', async () => {
  for (const page of ['movements', 'operations']) {
    const path = `pages/inventory/${page}`
    const grants = ['inventory.movement.view', 'inventory.adjustment.create']
    const scoped = await render(path, grants, { route: { level: '9' } })
    const base = `/inventory/${page}`
    assert.notEqual(scoped.meta.key({ fullPath: `${base}?level=9` }), scoped.meta.key({ fullPath: base }))
    const unscoped = await render(path, grants)
    if (page === 'movements') {
      assert.equal(scoped.requests[0].query.value.level_id, 9)
      assert.equal(unscoped.requests[0].query.value.level_id, undefined)
    } else {
      assert.equal(scoped.state.mode.value, 'adjustment')
      assert.equal(unscoped.state.mode.value, undefined)
      assert.equal(unscoped.state.initialLevel, undefined)
    }
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
  const form = withPicker.state
  form.selectedWarehouse.value = { value: 52, label: 'SBY — Surabaya' }
  form.reason.value = 'COUNT'
  form.sku.value = 'SKU-1'
  form.quantity.value = 8
  form.submit()
  assert.equal(form.action.value.body.warehouse_id, 52)
  assert.match(form.action.value.label, /SBY — Surabaya \(#52\)/)
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
