import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { computed, createSSRApp, h, reactive, ref, watch } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server = await createServer({
  configFile: false,
  plugins: [vue()],
  resolve: { alias: { '~': fileURLToPath(new URL('../app', import.meta.url)) } },
  server: { middlewareMode: true, hmr: false, ws: false },
  appType: 'custom'
})
after(() => server.close())
const { default: layout } = await server.ssrLoadModule('/app/layouts/default.vue')
const noop = () => null

async function profileLinks(permissions) {
  Object.assign(globalThis, {
    computed, ref, watch, useRoute: () => ({ path: '/' }), useToast: noop, useApiHealth: () => ({}),
    useAdminNavigation: () => ({ active: ref({ label: 'Dashboard' }), isGroupActive: () => false }),
    useAdminAuth: () => ({ user: ref({ display_name: 'Staf' }), can: ability => permissions.includes(ability) })
  })
  const app = createSSRApp(layout)
  for (const name of ['AppLogo', 'NuxtLink', 'UIcon', 'UTooltip', 'UCollapsible', 'UButton', 'UDashboardPanel', 'UDashboardNavbar', 'UDashboardSidebarCollapse', 'UBreadcrumb', 'UBadge', 'UColorModeButton', 'UPopover', 'UEmpty']) {
    app.component(name, noop)
  }
  app.component('UDashboardGroup', (_, { slots }) => slots.default())
  app.component('UDashboardSidebar', (_, { slots }) => slots.footer({ collapsed: false }))
  let links
  app.component('UDropdownMenu', (_, { attrs }) => {
    links = attrs.items.flat().filter(item => item.to).map(({ label, to, external }) => [label, to, external])
    return h('div')
  })
  await renderToString(app)
  return links
}

test('profile puts permitted operator tools directly below settings as full-page links', async () => {
  const settings = ['Setting', '/settings', undefined]
  const logs = ['Log Viewer', '/log-viewerx', true]
  const db = ['OmniDB', '/db/', true]
  const horizon = ['Horizon', '/horizon', true]
  for (const [grants, expected] of [
    [[], []],
    [['operator.logs.view'], [logs]],
    [['operator.database.open'], [db]],
    [['operator.logs.view', 'operator.database.open'], [logs, db]],
    [['operator.logs.view', 'operator.database.open', 'operator.horizon.view'], [logs, db, horizon]]
  ]) {
    assert.deepEqual(await profileLinks(['admin.profile.view', ...grants]), [settings, ...expected])
  }
})

test('settings is not duplicated in sidebar navigation', async () => {
  const { adminNavigation } = await server.ssrLoadModule('/app/composables/useAdminNavigation.ts')
  assert.ok(!adminNavigation.flatMap(item => item.children ?? [item]).some(link => link.to === '/settings'))
})

test('self-service controls follow individual grants and never show a superadmin MFA toggle', async () => {
  const { default: settings } = await server.ssrLoadModule('/app/pages/settings.vue')
  const all = ['admin.profile.update', 'admin.profile.change_email', 'admin.profile.change_password', 'admin.profile.manage_mfa']
  async function render(grants, roles, mfa) {
    Object.assign(globalThis, {
      computed, ref, reactive, definePageMeta() {}, useHead() {}, useToast: noop,
      useAdminAuth: () => ({ user: ref({ email: 'staff@example.test', roles, login_mfa_required: mfa }), can: permission => grants.includes(permission) })
    })
    const app = createSSRApp(settings)
    for (const component of ['UModal', 'UAlert', 'UFormField', 'UInput']) app.component(component, noop)
    app.component('UCard', (_, { slots }) => slots.default())
    app.component('UButton', (_, { attrs }) => h('button', attrs.label))
    return renderToString(app)
  }
  assert.doesNotMatch(await render([], [], true), /Simpan nama|Ganti email login|Ganti password|Matikan MFA/)
  const root = await render(all, ['super_admin'], true)
  for (const label of ['Simpan nama', 'Ganti email login', 'Ganti password']) assert.ok(root.includes(label))
  assert.doesNotMatch(root, /Matikan MFA/)
  assert.match(await render(all, ['admin'], true), /Matikan MFA saat login/)
  assert.match(await render(all, ['admin'], false), /Aktifkan MFA saat login/)
})
