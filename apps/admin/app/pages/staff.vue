<script setup lang="ts">
import type { StaffList, StaffRoleOptions } from '@snowego/api-types'

definePageMeta({ permission: 'admin.staff.view' })
useHead({ title: 'Pengguna · Snowego Admin' })
type Staff = StaffList['data'][number]
type Pending = { url: string, method: 'POST' | 'PATCH' | 'DELETE', label: string, body?: Record<string, unknown> }
const { can, user } = useAdminAuth()
const canAssign = computed(() => can('admin.staff.assign_roles'))
const canInvite = computed(() => can('admin.staff.create') && canAssign.value)
const canUpdate = computed(() => can('admin.staff.update'))
const canChangeStatus = computed(() => canUpdate.value && can('admin.staff.change_status'))
const base = '/api/v1/admin/staff'
const options = { cache: 'no-store' as const, headers: { Accept: 'application/json' } }
const page = ref(1)
const search = ref('')
const query = ref('')
const { data, error, refresh } = await useFetch<StaffList>(base, { ...options, query: { page, search } })
const choices = canAssign.value ? await useFetch<StaffRoleOptions>(`${base}/role-options`, options) : undefined
const roles = computed(() => choices?.data.value?.data ?? [])
const mode = ref<'invite' | 'edit'>()
const target = ref<Staff>()
const display = ref('')
const email = ref('')
const invitedRole = ref('')
const selected = ref<string[]>([])
const pending = ref<Pending>()
function applySearch() {
  page.value = 1
  search.value = query.value.trim()
}
function form(staff?: Staff) {
  mode.value = staff ? 'edit' : 'invite'
  target.value = staff
  display.value = staff?.display_name ?? ''
  email.value = staff?.email ?? ''
  selected.value = [...(staff?.roles ?? [])]
  invitedRole.value = ''
}
function submit() {
  if (mode.value === 'invite' && canInvite.value && invitedRole.value) {
    pending.value = { url: base, method: 'POST', label: `Undang ${email.value} sebagai ${invitedRole.value}?`,
      body: { email: email.value, role: invitedRole.value, display_name: display.value.trim() || null } }
  } else if (mode.value === 'edit' && target.value && canUpdate.value) {
    const staff = target.value
    const body: Record<string, unknown> = {}
    if (display.value.trim() && display.value.trim() !== staff.display_name) body.display_name = display.value.trim()
    if (canAssign.value && [...selected.value].sort().join() !== [...staff.roles].sort().join()) body.roles = selected.value
    if (Object.keys(body).length) pending.value = { url: `${base}/${staff.id}`, method: 'PATCH', label: `Simpan perubahan untuk ${staff.email}?`, body }
    else mode.value = undefined
  }
}
function status(staff: Staff) {
  pending.value = { url: `${base}/${staff.id}`, method: 'PATCH', label: `${staff.active ? 'Nonaktifkan' : 'Aktifkan'} ${staff.email}?`, body: { active: !staff.active } }
}
function revoke(staff: Staff) {
  pending.value = { url: `${base}/${staff.id}/sessions`, method: 'DELETE', label: `Keluar dari seluruh perangkat ${staff.email}?` }
}
async function saved() {
  pending.value = mode.value = undefined
  await refresh()
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-highlighted">
          Pengguna
        </h1><p class="text-sm text-muted">
          Kelola akun staf, role, dan akses perangkat.
        </p>
      </div>
      <UButton
        v-if="canInvite"
        icon="i-lucide-user-plus"
        label="Tambah pengguna"
        @click="form()"
      />
    </div>
    <UCard v-if="mode">
      <template #header>
        <h2 class="font-semibold">
          {{ mode === 'invite' ? 'Undang pengguna' : 'Edit pengguna' }}
        </h2>
      </template>
      <form
        class="grid gap-4 sm:grid-cols-2"
        @submit.prevent="submit"
      >
        <UFormField label="Nama tampilan">
          <UInput
            v-model="display"
            maxlength="100"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Email">
          <UInput
            v-model="email"
            type="email"
            required
            :readonly="mode === 'edit'"
            class="w-full"
          />
        </UFormField>
        <UFormField
          v-if="canAssign"
          label="Role"
        >
          <USelect
            v-if="mode === 'invite'"
            v-model="invitedRole"
            :items="roles"
            placeholder="Pilih role"
            class="w-full"
          />
          <USelect
            v-else
            v-model="selected"
            multiple
            :items="roles"
            class="w-full"
          />
        </UFormField>
        <UAlert
          v-if="choices?.error.value"
          title="Daftar role tidak dapat dimuat."
          color="error"
        />
        <div class="flex items-end justify-end gap-2 sm:col-span-2">
          <UButton
            label="Batal"
            variant="outline"
            @click="mode = undefined"
          />
          <UButton
            type="submit"
            :label="mode === 'invite' ? 'Kirim undangan' : 'Simpan'"
            :disabled="mode === 'invite' ? !invitedRole : canAssign && !selected.length"
          />
        </div>
      </form>
    </UCard>
    <UCard>
      <template #header>
        <form
          class="flex gap-2"
          @submit.prevent="applySearch"
        >
          <UInput
            v-model="query"
            type="search"
            maxlength="100"
            aria-label="Cari pengguna"
            placeholder="Cari nama atau email"
            icon="i-lucide-search"
          />
          <UButton
            type="submit"
            label="Cari"
            variant="outline"
          />
        </form>
      </template>
      <UAlert
        v-if="error"
        color="error"
        title="Daftar pengguna tidak dapat dimuat."
      />
      <div
        v-else
        class="overflow-x-auto"
      >
        <table class="w-full text-left text-sm">
          <thead class="text-muted">
            <tr>
              <th
                v-for="col in ['Nama', 'Email', 'Role', 'Status', 'Aksi']"
                :key="col"
                scope="col"
                class="p-3"
              >
                {{ col }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-default">
            <tr
              v-for="staff in data?.data"
              :key="staff.id"
            >
              <th
                scope="row"
                class="p-3 font-medium"
              >
                {{ staff.display_name || '—' }} <UBadge
                  v-if="staff.id === user?.id"
                  size="sm"
                  variant="subtle"
                >
                  Anda
                </UBadge>
              </th>
              <td class="p-3">
                {{ staff.email }}
              </td>
              <td class="p-3">
                <div class="flex flex-wrap gap-1">
                  <UBadge
                    v-for="role in staff.roles"
                    :key="role"
                    variant="subtle"
                  >
                    {{ role }}
                  </UBadge>
                </div>
              </td>
              <td class="p-3">
                <UBadge
                  :color="!staff.active ? 'neutral' : staff.mfa_confirmed ? 'success' : 'warning'"
                  variant="subtle"
                >
                  {{ !staff.active ? 'Nonaktif' : staff.mfa_confirmed ? 'Aktif' : 'Diundang' }}
                </UBadge>
              </td>
              <td class="p-3">
                <div
                  v-if="staff.manageable && staff.id !== user?.id"
                  class="flex flex-wrap gap-1"
                >
                  <UButton
                    v-if="canUpdate"
                    label="Edit"
                    variant="ghost"
                    @click="form(staff)"
                  />
                  <UButton
                    v-if="canChangeStatus"
                    :label="staff.active ? 'Nonaktifkan' : 'Aktifkan'"
                    variant="ghost"
                    @click="status(staff)"
                  />
                  <UButton
                    v-if="can('admin.staff.sessions.delete')"
                    label="Cabut sesi"
                    color="error"
                    variant="ghost"
                    @click="revoke(staff)"
                  />
                </div>
              </td>
            </tr>
            <tr v-if="data && !data.data.length">
              <td
                colspan="5"
                class="p-6 text-center text-muted"
              >
                Tidak ada pengguna yang cocok.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <template
        v-if="data && data.meta.last_page > 1"
        #footer
      >
        <div class="flex items-center justify-end gap-2">
          <UButton
            :disabled="page <= 1"
            label="Sebelumnya"
            variant="outline"
            @click="page--"
          />
          <span>{{ data.meta.page }} / {{ data.meta.last_page }}</span>
          <UButton
            :disabled="page >= data.meta.last_page"
            label="Berikutnya"
            variant="outline"
            @click="page++"
          />
        </div>
      </template>
    </UCard>
    <ConfirmAdminAction
      v-if="pending"
      :action="pending"
      @cancel="pending = undefined"
      @saved="saved"
    />
  </section>
</template>
