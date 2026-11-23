<script setup lang="ts">
import type { StaffList } from '@snowego/api-types'

definePageMeta({ permission: 'admin.staff.view' })
useHead({ title: 'Staf dan sesi · Snowego Admin' })
type Account = StaffList['data'][number]
const { can, csrf, stepUp, user } = useAdminAuth()
const toast = useToast()
const page = ref(1)
const { data, error, refresh } = await useFetch<StaffList>('/api/v1/admin/staff', {
  query: { page }, cache: 'no-store', headers: { Accept: 'application/json' }
})
const email = ref('')
const role = ref('admin')
const canInvite = computed(() => can('admin.staff.create') && can('admin.staff.assign_roles'))
const canChangeStatus = computed(() => can('admin.staff.update') && can('admin.staff.change_status'))
const pending = ref<{ method: 'POST' | 'PATCH' | 'DELETE', url: string, body?: Record<string, string | boolean>, label: string, key: string }>()
const stepRequired = ref(false)
const password = ref('')
const code = ref('')
const busy = ref(false)
const notice = ref('')
function reset() {
  pending.value = undefined
  stepRequired.value = false
  password.value = code.value = notice.value = ''
}
function select(action: 'invite' | 'status' | 'revoke', staff?: Account) {
  reset()
  const base = '/api/v1/admin/staff'
  const key = crypto.randomUUID()
  if (action === 'invite' && canInvite.value) {
    pending.value = { method: 'POST', url: base, body: { email: email.value, role: role.value }, label: `Kirim undangan ke ${email.value} (${role.value})`, key }
  } else if (staff && action === 'status' && canChangeStatus.value) {
    pending.value = { method: 'PATCH', url: `${base}/${staff.id}`, body: { active: !staff.active }, label: `${staff.active ? 'Nonaktifkan' : 'Aktifkan'} ${staff.email}`, key }
  } else if (staff && action === 'revoke' && can('admin.staff.sessions.delete')) {
    pending.value = { method: 'DELETE', url: `${base}/${staff.id}/sessions`, label: `Cabut semua sesi ${staff.email}`, key }
  }
}
async function execute() {
  const action = pending.value
  if (!action || busy.value) return
  busy.value = true
  notice.value = ''
  try {
    if (stepRequired.value) {
      await stepUp(password.value, code.value)
      stepRequired.value = false
    }
    await $fetch(action.url, { method: action.method, body: action.body,
      headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': await csrf(), 'Idempotency-Key': action.key } })
    reset()
    email.value = ''
    toast.add({ title: 'Perubahan tersimpan', color: 'success' })
    await refresh()
  } catch (err) {
    if ((err as { data?: { code?: string } }).data?.code === 'STEP_UP_REQUIRED') stepRequired.value = true
    else notice.value = authErrorMessage(err)
  } finally {
    password.value = code.value = ''
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-highlighted">
      Staf dan sesi
    </h1>
    <UAlert
      v-if="error"
      color="error"
      title="Daftar staf tidak dapat dimuat."
    />
    <UCard v-if="canInvite">
      <template #header>
        <h2 class="font-semibold">
          Undang staf
        </h2>
      </template>
      <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="select('invite')"
      >
        <UFormField label="Email">
          <UInput
            v-model="email"
            type="email"
            required
          />
        </UFormField>
        <UFormField label="Nama role">
          <UInput
            v-model="role"
            required
          />
        </UFormField>
        <UButton
          type="submit"
          label="Buat undangan"
          :disabled="!!pending"
        />
      </form>
    </UCard>
    <UCard v-if="pending">
      <template #header>
        <h2 class="font-semibold">
          Konfirmasi: {{ pending.label }}
        </h2>
      </template>
      <form
        class="space-y-3"
        @submit.prevent="execute"
      >
        <UAlert
          v-if="notice"
          :title="notice"
          color="error"
        />
        <template v-if="stepRequired">
          <p>Untuk aksi ini, masukkan password dan kode authenticator yang belum dipakai.</p>
          <UFormField label="Password">
            <UInput
              v-model="password"
              type="password"
              autocomplete="current-password"
              required
            />
          </UFormField>
          <UFormField label="Kode authenticator">
            <UInput
              v-model="code"
              autocomplete="one-time-code"
              pattern="[0-9]{6}"
              required
            />
          </UFormField>
        </template>
        <div class="flex gap-2">
          <UButton
            type="submit"
            :loading="busy"
            label="Konfirmasi"
          />
          <UButton
            variant="outline"
            :disabled="busy"
            label="Batal"
            @click="reset"
          />
        </div>
      </form>
    </UCard>
    <UCard v-if="data && !error">
      <ul class="divide-y divide-default">
        <li
          v-for="staff in data.data"
          :key="staff.id"
          class="flex flex-wrap items-center justify-between gap-3 py-3"
        >
          <div>
            <p>{{ staff.email }}</p><p class="text-sm text-muted">
              {{ staff.roles.join(', ') }} · {{ staff.active ? 'Aktif' : 'Nonaktif' }}
            </p>
          </div>
          <div
            v-if="staff.id !== user?.id"
            class="flex gap-2"
          >
            <UButton
              v-if="canChangeStatus"
              variant="outline"
              :disabled="!!pending"
              :label="staff.active ? 'Nonaktifkan' : 'Aktifkan'"
              @click="select('status', staff)"
            />
            <UButton
              v-if="can('admin.staff.sessions.delete')"
              color="error"
              variant="outline"
              :disabled="!!pending"
              label="Cabut sesi"
              @click="select('revoke', staff)"
            />
          </div>
        </li>
      </ul>
      <div
        v-if="data.meta.last_page > 1"
        class="mt-4 flex gap-2"
      >
        <UButton
          :disabled="page <= 1"
          label="Sebelumnya"
          @click="page--"
        />
        <UButton
          :disabled="page >= data.meta.last_page"
          label="Berikutnya"
          @click="page++"
        />
      </div>
    </UCard>
  </div>
</template>
