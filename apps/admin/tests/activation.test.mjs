import assert from 'node:assert/strict'
import { after, test } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { computed, createSSRApp, h, reactive, ref } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true, hmr: false, ws: false }, appType: 'custom' })
after(() => server.close())
Object.assign(globalThis, { computed, reactive, ref })

async function render(name, props, controls) {
  const { default: component } = await server.ssrLoadModule(`/app/components/${name}.vue`)
  const app = createSSRApp(component, props)
  for (const [name, render] of Object.entries(controls)) {
    app.component(name, { inheritAttrs: false, setup: (_, { attrs, slots }) => () => render(attrs, slots) })
  }
  return renderToString(app)
}

test('password form requires a matching confirmation and allows 12–128 Unicode characters', async () => {
  let validate
  const html = await render('ActivationPassword', { busy: false }, {
    UForm: (attrs, slots) => {
      validate = attrs.validate
      return h('form', slots.default?.())
    },
    UFormField: (_, slots) => h('div', slots.default?.()),
    UInput: attrs => h('input', { type: attrs.type, autocomplete: attrs.autocomplete }),
    UButton: attrs => h('button', attrs.label)
  })
  assert.equal((html.match(/type="password" autocomplete="new-password"/g) ?? []).length, 2)
  for (const n of [11, 12, 128, 129]) {
    const password = '𐐷'.repeat(n)
    assert.deepEqual(validate({ password, confirmation: password }).map(e => e.name), n < 12 || n > 128 ? ['password'] : [])
  }
  assert.deepEqual(validate({ password: 'long enough for test', confirmation: '' }).map(e => e.name), ['confirmation'])
})

test('authenticator renders a local QR, not the seed or a remote media URL, and requires six digits', async () => {
  const uri = 'otpauth://totp/Test:Demo?secret=JBSWY3DPEHPK3PXP&issuer=Test'
  const html = await render('ActivationAuthenticator', { uri, busy: false }, {
    UFormField: (_, slots) => h('div', slots.default?.()),
    UPinInput: (attrs) => {
      assert.equal(attrs.length, 6)
      assert.equal(attrs.type, 'number')
      assert.ok(Object.hasOwn(attrs, 'otp'))
      return h('input')
    },
    UButton: attrs => h('button', { disabled: attrs.disabled }, attrs.label)
  })
  assert.match(html, /<img src="data:image\/svg\+xml,/)
  assert.match(decodeURIComponent(html), /<path fill="black" d="M/)
  assert.doesNotMatch(html, /JBSWY3DPEHPK3PXP|otpauth:|src="https?:/)
  assert.match(html, /<button disabled>Verifikasi kode/)
})
