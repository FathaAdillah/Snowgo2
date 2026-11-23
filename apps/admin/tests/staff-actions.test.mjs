import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { computed, createSSRApp, h, ref } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true, hmr: false }, appType: 'custom' })
after(() => server.close())
const { default: page } = await server.ssrLoadModule('/app/pages/staff.vue')
const grants = new Set()
Object.assign(globalThis, {
  computed, ref, definePageMeta() {}, useHead() {}, useToast: () => ({}),
  useAdminAuth: () => ({ can: permission => grants.has(permission), user: ref({ id: 1 }) }),
  useFetch: () => ({ data: ref({ data: [{ id: 2, email: 'staff@example.test', roles: ['admin'], active: true }], meta: { last_page: 1 } }), error: ref(null) })
})
async function render(permissions) {
  grants.clear()
  permissions.forEach(permission => grants.add(permission))
  const app = createSSRApp(page)
  for (const name of ['UCard', 'UFormField', 'UInput', 'UAlert', 'UButton']) {
    app.component(name, { setup: (_, { attrs, slots }) => () => h('div', {}, [attrs.label, slots.default?.()]) })
  }
  return renderToString(app)
}
test('read access does not render any mutation control', async () => {
  const html = await render(['admin.staff.view'])
  assert.match(html, /staff@example.test/)
  assert.doesNotMatch(html, /Buat undangan|Nonaktifkan|Cabut sesi/)
})
test('create, update and delete-session controls require their own grants', async () => {
  const partial = await render(['admin.staff.create', 'admin.staff.update', 'admin.staff.sessions.delete'])
  assert.match(partial, /Cabut sesi/)
  assert.doesNotMatch(partial, /Buat undangan|Nonaktifkan/)
  const edit = await render(['admin.staff.create', 'admin.staff.assign_roles', 'admin.staff.update', 'admin.staff.change_status'])
  assert.match(edit, /Buat undangan/)
  assert.match(edit, /Nonaktifkan/)
  assert.doesNotMatch(edit, /Cabut sesi/)
})
