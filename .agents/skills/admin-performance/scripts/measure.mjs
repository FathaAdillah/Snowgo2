import { chromium } from 'playwright-core'
import { Buffer } from 'node:buffer'
import process from 'node:process'

const origin = new URL(process.argv[2] ?? 'http://admin.localhost:8080').origin
const runs = Number(process.argv[3] ?? 3)
const mode = process.argv[4] ?? 'fixture'
if (new URL(origin).hostname !== 'admin.localhost' || !Number.isInteger(runs) || runs < 1 || runs > 10 || !['fixture', 'guest'].includes(mode)) {
  throw new Error('Usage: node measure.mjs http://admin.localhost[:port] [1–10] [fixture|guest]')
}
const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : { channel: 'chrome' })
try {
  for (let run = 1; run <= runs; run++) {
    const page = await browser.newPage({ serviceWorkers: 'block', viewport: { width: 1365, height: 900 } })
    await page.addInitScript(() => performance.setResourceTimingBufferSize(4000))
    if (mode === 'fixture') {
      // Intercept only same-origin API calls. Don't pause each Vite asset for automation.
      const cdp = await page.context().newCDPSession(page)
      await cdp.send('Fetch.enable', { patterns: [{ urlPattern: `${origin}/api/*` }, { urlPattern: `${origin}/sanctum/*` }] })
      cdp.on('Fetch.requestPaused', async ({ requestId, request }) => {
        const path = new URL(request.url).pathname
        const data = path === '/api/v1/admin/auth/me'
          ? { id: 1, display_name: 'Benchmark', email: 'fixture@example.test', roles: [], permissions: ['reporting.dashboard.view'] }
          : path === '/api/v1/health' ? { status: 'ok' } : undefined
        await cdp.send('Fetch.fulfillRequest', {
          requestId, responseCode: data ? 200 : 404,
          responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Cache-Control', value: 'no-store' }],
          body: Buffer.from(JSON.stringify(data ? { data } : { code: 'NOT_FOUND' })).toString('base64')
        })
      })
    }
    for (const cache of ['fresh', 'reload']) {
      const start = Date.now()
      await page.goto(origin, { waitUntil: 'commit', timeout: 90000 })
      const result = await page.waitForFunction((mode) => {
        const el = mode === 'fixture'
          ? [...document.querySelectorAll('h2')].find(el => el.textContent.trim() === 'Ringkasan Penjualan')
          : document.querySelector('input[type="password"]')
        if (!el?.getBoundingClientRect().height) return
        const entries = performance.getEntriesByType('resource')
        const nav = performance.getEntriesByType('navigation')[0]
        return {
          readyMs: Math.round(performance.now()), htmlTtfbMs: Math.round(nav.responseStart - nav.requestStart),
          resources: entries.length, rekaModules: entries.filter(e => e.name.includes('/reka-ui/dist/')).length,
          externalHosts: [...new Set(entries.map(e => new URL(e.name)).filter(u => /^https?:$/.test(u.protocol) && u.origin !== location.origin).map(u => u.hostname))]
        }
      }, mode, { timeout: 90000 })
      console.log(JSON.stringify({ mode, run, cache, elapsedMs: Date.now() - start, ...await result.jsonValue() }))
    }
    await page.close()
  }
} finally {
  await browser.close()
}
