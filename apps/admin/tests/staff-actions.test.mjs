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
const { default: rowAction } = await server.ssrLoadModule('/app/components/AdminRowAction.vue')
const { useStaffFilters } = await server.ssrLoadModule('/app/composables/useStaffFilters.ts')
const openRole = { id: 2, name: 'packing', display_name: 'Packing', description: '', members: 0, system: false, editable: true, deletable: true, permissions: ['foundation.file.view'] }
const staff = { id: 2, email: 'staff@example.test', roles: ['admin'], active: true, manageable: true }
const grants = new Set()
Object.assign(globalThis, {
  computed, ref, watch, useStaffFilters, definePageMeta() {}, useHead() {}, useToast: () => ({}),
  useAdminAuth: () => ({ can: permission => grants.has(permission), user: ref({ id: 1 }) })
})
async function render(permissions, component = page, requests = [], buttons = {}, fixture = {}) {
  grants.clear()
  permissions.forEach(permission => grants.add(permission))
  globalThis.useFetch = (url, options) => {
    const execute = () => requests.push(url)
    if (options.immediate !== false) execute()
    const fixtures = url.endsWith('/roles')
      ? [openRole, { ...openRole, id: 3, name: 'super_admin', system: true, editable: false, deletable: false }]
      : fixture.rows ?? [staff, { ...staff, id: 1, email: 'self@example.test', manageable: false }]
    const total = fixture.total ?? 31
    fixture.query = options.query ?? fixture.query
    return { data: ref({ data: fixtures, meta: { last_page: Math.max(1, Math.ceil(total / 25)), page: 1, per_page: 25, total } }), error: ref(null), status: ref('success'), execute, refresh: execute }
  }
  const app = createSSRApp(component)
  app.component('AdminRowAction', rowAction)
  app.component('UTooltip', (_, { slots }) => slots.default())
  for (const name of ['UCard', 'UBadge', 'UFormField', 'UInput', 'UAlert', 'USelect']) {
    app.component(name, { setup: (_, { attrs, slots }) => () => h('div', {}, [attrs.label, slots.header?.(), slots.default?.(), slots.footer?.()]) })
  }
  app.component('ConfirmAdminAction', (_, { attrs }) => {
    fixture.confirm = attrs.action
    fixture.saved = attrs.onSaved
    return h('aside', attrs.action.label)
  })
  app.component('UAvatar', (_, { attrs }) => h('span', attrs.text))
  app.component('UButton', (_, { attrs }) => {
    buttons[attrs.label ?? attrs['aria-label']] = attrs.onClick
    return h('button', { 'aria-label': attrs['aria-label'], 'data-icon': attrs.icon, 'data-variant': attrs.variant }, attrs.label)
  })
  app.component('UTable', (_, { attrs, slots }) => h('table', attrs.data.map(original => h('tr', attrs.columns.map(({ id, accessorKey }) =>
    h('td', slots[`${id ?? accessorKey}-cell`]?.({ row: { original } }) ?? original[accessorKey]))))))
  app.component('UPagination', {
    props: ['total', 'itemsPerPage'],
    setup: (props, { attrs }) => {
      fixture.pager = attrs
      return () => h('nav', `${props.total} / ${props.itemsPerPage}`)
    }
  })
  return renderToString(app)
}
test('read access does not render any mutation control', async () => {
  const html = await render(['admin.staff.view'])
  assert.match(html, /staff@example.test/)
  assert.match(html, /Anda/)
  assert.match(html, /31 \/ 25/)
  assert.doesNotMatch(html, /Tambah Pengguna|aria-label="Edit |Nonaktifkan|Cabut sesi/)
})
test('role choices are only requested when opening a form with assign permission', async () => {
  const requests = []
  const buttons = {}
  await render(['admin.staff.update'], page, requests, buttons)
  buttons['Edit staff@example.test']()
  assert.deepEqual(requests, ['/api/v1/admin/staff'])
  requests.length = 0
  await render(['admin.staff.create', 'admin.staff.assign_roles'], page, requests, buttons)
  assert.deepEqual(requests, ['/api/v1/admin/staff'])
  buttons['Tambah Pengguna']()
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
test('row actions are named outline icons and restricted by status, grants and target', async () => {
  const partial = await render(['admin.staff.create', 'admin.staff.sessions.delete'])
  assert.match(partial, /aria-label="Cabut sesi staff@example.test"/)
  assert.doesNotMatch(partial, /Tambah Pengguna|aria-label="Edit |Nonaktifkan/)
  const edit = await render(['admin.staff.create', 'admin.staff.assign_roles', 'admin.staff.update', 'admin.staff.change_status', 'admin.staff.sessions.delete'])
  assert.match(edit, /Tambah Pengguna/)
  assert.match(edit, /aria-label="Edit staff@example.test" data-icon="i-lucide-pencil" data-variant="outline"/)
  assert.match(edit, /aria-label="Nonaktifkan staff@example.test" data-icon="i-lucide-trash-2" data-variant="outline"/)
  assert.doesNotMatch(edit, /Cabut sesi|aria-label="[^"]*self@example.test"/)
  const rows = [{ ...staff, active: false }, { ...staff, id: 3, email: 'protected@example.test', manageable: false }]
  const disabled = await render(['admin.staff.update', 'admin.staff.change_status'], page, [], {}, { rows })
  assert.match(disabled, /aria-label="Aktifkan staff@example.test" data-icon="i-lucide-user-check"/)
  assert.doesNotMatch(disabled, /aria-label="[^"]*protected@example.test"/)
})
test('edit keeps session revocation accessible and the red table icon only changes account status', async () => {
  const fixture = {}
  const buttons = {}
  let state
  const persistentPage = { ...page, setup: (props, ctx) => (state ??= page.setup(props, ctx)) }
  const show = () => render(['admin.staff.update', 'admin.staff.sessions.delete', 'admin.staff.change_status'], persistentPage, [], buttons, fixture)
  await show()
  buttons['Nonaktifkan staff@example.test']()
  await show()
  assert.equal(fixture.confirm.method, 'PATCH')
  assert.deepEqual(fixture.confirm.body, { active: false })
  state = undefined
  await show()
  buttons['Edit staff@example.test']()
  assert.match(await show(), /Cabut sesi/)
  state.display.value = 'Draf nama'
  buttons['Cabut sesi']()
  await show()
  assert.equal(fixture.confirm.method, 'DELETE')
  assert.equal(fixture.confirm.url, '/api/v1/admin/staff/2/sessions')
  await fixture.saved()
  assert.match(await show(), /Edit pengguna/)
  state.submit()
  await show()
  assert.deepEqual(fixture.confirm.body, { display_name: 'Draf nama' })
  await fixture.saved()
  assert.doesNotMatch(await show(), /<h2[^>]*>Edit pengguna/)
})
test('pager retains all four controls on one page and sends page changes to the server query', async () => {
  for (const total of [0, 10, 80]) {
    const fixture = { total }
    await render(['admin.staff.view'], page, [], {}, fixture)
    if (!total) {
      assert.equal(fixture.pager, undefined)
      continue
    }
    assert.equal(fixture.pager.page, 1)
    assert.deepEqual(['first-icon', 'prev-icon', 'next-icon', 'last-icon'].map(key => fixture.pager[key]),
      ['i-lucide-chevrons-left', 'i-lucide-chevron-left', 'i-lucide-chevron-right', 'i-lucide-chevrons-right'])
    if (total > 25) {
      fixture.pager['onUpdate:page'](3)
      assert.equal(fixture.query.value.page, 3)
    }
  }
})
test('role mutations require individual grants and the card must allow the action', async () => {
  const partial = await render(['admin.roles.view', 'admin.roles.create', 'admin.roles.update'], roleCards)
  assert.doesNotMatch(partial, /Buat role|aria-label="(?:Edit|Hapus) role /)
  const all = await render(['admin.roles.view', 'admin.roles.create', 'admin.roles.update', 'admin.roles.assign_permissions', 'admin.roles.delete'], roleCards)
  assert.match(all, /Buat role/)
  assert.match(all, /Semua izin/)
  assert.equal(all.match(/aria-label="Edit role Packing"/g)?.length, 1)
  assert.equal(all.match(/aria-label="Hapus role Packing"/g)?.length, 1)
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
