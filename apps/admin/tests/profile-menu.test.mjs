import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { computed, createSSRApp, h, ref, watch } from 'vue'
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
  const settings = ['Pengaturan', '/settings', undefined]
  const logs = ['Log Viewer', '/log-viewerx', true]
  const db = ['OmniDB', '/db/', true]
  for (const [grants, expected] of [
    [[], []],
    [['operator.logs.view'], [logs]],
    [['operator.database.open'], [db]],
    [['operator.logs.view', 'operator.database.open'], [logs, db]]
  ]) {
    assert.deepEqual(await profileLinks(['admin.profile.view', ...grants]), [settings, ...expected])
  }
})
