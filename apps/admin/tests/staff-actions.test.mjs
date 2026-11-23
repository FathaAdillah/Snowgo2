import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { computed, createSSRApp, effectScope, h, nextTick, ref, watch } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true, hmr: false }, appType: 'custom' })
after(() => server.close())
const { default: page } = await server.ssrLoadModule('/app/pages/staff.vue')
const { default: roleCards } = await server.ssrLoadModule('/app/pages/roles/index.vue')
const { useStaffFilters } = await server.ssrLoadModule('/app/composables/useStaffFilters.ts')
const openRole = { id: 2, name: 'packing', display_name: 'Packing', description: '', members: 0, system: false, editable: true, deletable: true, permissions: ['foundation.file.view'] }
const staff = { id: 2, email: 'staff@example.test', roles: ['admin'], active: true, manageable: true }
const grants = new Set()
Object.assign(globalThis, {
  computed, ref, watch, useStaffFilters, definePageMeta() {}, useHead() {}, useToast: () => ({}),
  useAdminAuth: () => ({ can: permission => grants.has(permission), user: ref({ id: 1 }) })
})
async function render(permissions, component = page, requests = [], buttons = {}) {
  grants.clear()
  permissions.forEach(permission => grants.add(permission))
  globalThis.useFetch = (url, options) => {
    const execute = () => requests.push(url)
    if (options.immediate !== false) execute()
    const fixtures = url.endsWith('/roles')
      ? [openRole, { ...openRole, id: 3, name: 'super_admin', system: true, editable: false, deletable: false }]
      : [staff, { ...staff, id: 1, email: 'self@example.test', manageable: false }]
    return { data: ref({ data: fixtures, meta: { last_page: 2, page: 1, per_page: 25, total: 31 } }), error: ref(null), status: ref('success'), execute }
  }
  const app = createSSRApp(component)
  for (const name of ['UCard', 'UBadge', 'UFormField', 'UInput', 'UAlert', 'USelect', 'ConfirmAdminAction']) {
    app.component(name, { setup: (_, { attrs, slots }) => () => h('div', {}, [attrs.label, slots.header?.(), slots.default?.(), slots.footer?.()]) })
  }
  app.component('UButton', (_, { attrs }) => {
    buttons[attrs.label] = attrs.onClick
    return h('button', attrs.label)
  })
  app.component('UTable', (_, { attrs, slots }) => h('table', attrs.data.map(original => h('tr', attrs.columns.map(({ id, accessorKey }) =>
    h('td', slots[`${id ?? accessorKey}-cell`]?.({ row: { original } }) ?? original[accessorKey]))))))
  app.component('UPagination', {
    props: ['total', 'itemsPerPage'],
    setup: props => () => h('nav', `${props.total} / ${props.itemsPerPage}`)
  })
  return renderToString(app)
}
test('read access does not render any mutation control', async () => {
  const html = await render(['admin.staff.view'])
  assert.match(html, /staff@example.test/)
  assert.match(html, /Anda/)
  assert.match(html, /31 \/ 25/)
  assert.doesNotMatch(html, /Tambah pengguna|Nonaktifkan|Cabut sesi/)
})
test('role choices are only requested when opening a form with assign permission', async () => {
  const requests = []
  const buttons = {}
  await render(['admin.staff.update'], page, requests, buttons)
  buttons.Edit()
  assert.deepEqual(requests, ['/api/v1/admin/staff'])
  requests.length = 0
  await render(['admin.staff.create', 'admin.staff.assign_roles'], page, requests, buttons)
  assert.deepEqual(requests, ['/api/v1/admin/staff'])
  buttons['Tambah pengguna']()
  assert.deepEqual(requests, ['/api/v1/admin/staff', '/api/v1/admin/staff/role-options'])
})
test('typing coalesces into one server query and each filter resets pagination', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  const scope = effectScope()
  t.after(() => scope.stop())
  const { query, statusFilter, params, changePage } = scope.run(useStaffFilters)
  changePage(3)
  query.value = 'pa'
  await nextTick()
  t.mock.timers.tick(200)
  query.value = ' packing '
  await nextTick()
  t.mock.timers.tick(200)
  assert.deepEqual(params.value, { page: 3 })
  t.mock.timers.tick(100)
  assert.deepEqual(params.value, { page: 1, search: 'packing', status: undefined })
  changePage(2)
  statusFilter.value = 'disabled'
  await nextTick()
  assert.deepEqual(params.value, { page: 1, search: 'packing', status: 'disabled' })
  statusFilter.value = 'all'
  query.value = ''
  await nextTick()
  assert.deepEqual(params.value, { page: 1, search: undefined, status: undefined })
})
test('create, update and delete-session controls require their own grants', async () => {
  const partial = await render(['admin.staff.create', 'admin.staff.update', 'admin.staff.sessions.delete'])
  assert.match(partial, /Cabut sesi/)
  assert.doesNotMatch(partial, /Tambah pengguna|Nonaktifkan/)
  const edit = await render(['admin.staff.create', 'admin.staff.assign_roles', 'admin.staff.update', 'admin.staff.change_status'])
  assert.match(edit, /Tambah pengguna/)
  assert.equal(edit.match(/>Nonaktifkan</g)?.length, 1)
  assert.doesNotMatch(edit, /Cabut sesi/)
})
test('role mutations require individual grants and the card must allow the action', async () => {
  const partial = await render(['admin.roles.view', 'admin.roles.create', 'admin.roles.update'], roleCards)
  assert.doesNotMatch(partial, /Buat role|>Edit<|>Hapus</)
  const all = await render(['admin.roles.view', 'admin.roles.create', 'admin.roles.update', 'admin.roles.assign_permissions', 'admin.roles.delete'], roleCards)
  assert.match(all, /Buat role/)
  assert.match(all, /Semua izin/)
  assert.equal(all.match(/>Edit</g)?.length, 1)
  assert.equal(all.match(/>Hapus</g)?.length, 1)
})

test('permission picker groups actions, counts grants and never toggles unavailable permissions', async () => {
  const { default: picker } = await server.ssrLoadModule('/app/components/RolePermissionPicker.vue')
  const catalog = [
    { section: 'admin', resource: 'staff', name: 'admin.staff.view', action: 'view', assignable: true },
    { section: 'admin', resource: 'staff', name: 'admin.staff.create', action: 'create', assignable: false },
    { section: 'admin', resource: 'roles', name: 'admin.roles.view', action: 'view', assignable: true },
    { section: 'foundation', resource: 'file', name: 'foundation.file.view', action: 'view', assignable: true }
  ]
  let changed
  const buttons = new Map()
  const app = createSSRApp(picker, { catalog, 'modelValue': ['admin.staff.view'], 'onUpdate:modelValue': value => changed = value })
  app.component('UCard', (_, { slots }) => h('div', [slots.header(), slots.default()]))
  app.component('UBadge', (_, { slots }) => h('span', slots.default()))
  app.component('UButton', (_, { attrs, slots }) => {
    if (attrs.title) buttons.set(attrs.title, attrs)
    return h('button', [attrs.label, slots.default?.()])
  })
  const html = await renderToString(app)
  assert.match(html, /1 izin dipilih/)
  assert.match(html, /Administrasi/)
  assert.match(html, /Berkas/)
  assert.match(html, /Pengguna <span>1<\/span>/)
  assert.deepEqual([...buttons.keys()], ['admin.staff.view', 'admin.staff.create'])
  assert.ok(buttons.get('admin.staff.create').disabled)
  buttons.get('admin.staff.create').onClick()
  assert.equal(changed, undefined)
  buttons.get('admin.staff.view').onClick()
  assert.deepEqual(changed, [])
})
