import assert from 'node:assert/strict'
import test from 'node:test'
import { useAdminAuth } from '../app/composables/useAdminAuth.ts'

for (const status of [200, 401, 503]) {
  test(`logout handles HTTP ${status}`, async () => {
    const staff = { value: { id: 1 } }
    const effects = []
    Object.assign(globalThis, {
      useState: () => staff,
      document: { cookie: '' },
      $fetch: async (path) => {
        if (path.endsWith('/logout') && status !== 200) throw Object.assign(new Error('Logout failed'), { status })
      },
      clearNuxtData: () => effects.push('cleared'),
      navigateTo: path => effects.push(path)
    })
    const exiting = useAdminAuth().logout()
    if (status === 503) {
      await assert.rejects(exiting)
      assert.ok(staff.value)
      assert.deepEqual(effects, [])
    } else {
      await exiting
      assert.equal(staff.value, null)
      assert.deepEqual(effects, ['cleared', '/login'])
    }
  })
}
