<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { StaffList, StaffRoleOptions } from '@snowego/api-types'
import type { StaffStatusFilter } from '~/composables/useStaffFilters'

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
const columns: TableColumn<Staff>[] = [
  { accessorKey: 'display_name', header: 'Nama' },
  { accessorKey: 'email', header: 'Email' },
  { accessorKey: 'roles', header: 'Role' },
  { id: 'status', header: 'Status' },
  { id: 'actions', header: 'Aksi' }
]
const statuses = [
  { label: 'Semua status', value: 'all' },
  { label: 'Aktif', value: 'active' },
  { label: 'Diundang', value: 'invited' },
  { label: 'Nonaktif', value: 'disabled' }
] satisfies { label: string, value: StaffStatusFilter }[]
const { query, statusFilter, params, changePage, applyFilters } = useStaffFilters()
const { data, error, refresh, status: listStatus } = useFetch<StaffList>(base, {
  ...options, key: 'staff-list', query: params, lazy: true, deep: false
})
const loading = computed(() => listStatus.value === 'pending' || listStatus.value === 'idle')
const { data: choices, status: roleStatus, execute: loadRoles } = useFetch<StaffRoleOptions>(`${base}/role-options`, {
  ...options, immediate: false
})
const mode = ref<'invite' | 'edit'>()
const target = ref<Staff>()
const display = ref('')
const email = ref('')
const invitedRole = ref('')
const selected = ref<string[]>([])
const pending = ref<Pending>()
watch(data, (result) => {
  if (result && result.meta.page === params.value.page && result.meta.page > result.meta.last_page) changePage(result.meta.last_page)
})
function form(staff?: Staff) {
  mode.value = staff ? 'edit' : 'invite'
  target.value = staff
  display.value = staff?.display_name ?? ''
  email.value = staff?.email ?? ''
  selected.value = [...(staff?.roles ?? [])]
  invitedRole.value = ''
  if (canAssign.value) void loadRoles()
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
            :items="choices?.data ?? []"
            :loading="roleStatus === 'pending'"
            :disabled="roleStatus !== 'success'"
            placeholder="Pilih role"
            class="w-full"
          />
          <USelect
            v-else
            v-model="selected"
            multiple
            :items="choices?.data ?? []"
            :loading="roleStatus === 'pending'"
            :disabled="roleStatus !== 'success'"
            class="w-full"
          />
        </UFormField>
        <UAlert
          v-if="canAssign && roleStatus === 'error'"
          title="Daftar role tidak dapat dimuat."
          color="error"
          :actions="[{ label: 'Coba lagi', onClick: () => loadRoles() }]"
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
          class="flex flex-wrap items-center gap-2"
          role="search"
          @submit.prevent="applyFilters"
        >
          <UInput
            v-model="query"
            type="search"
            maxlength="100"
            aria-label="Cari pengguna"
            placeholder="Cari nama atau email"
            icon="i-lucide-search"
          />
          <USelect
            v-model="statusFilter"
            :items="statuses"
            aria-label="Filter status pengguna"
          />
        </form>
      </template>
      <UAlert
        v-if="error"
        color="error"
        title="Daftar pengguna tidak dapat dimuat."
        :actions="[{ label: 'Coba lagi', onClick: () => refresh() }]"
      />
      <UTable
        v-else
        caption="Daftar pengguna staf"
        :columns="columns"
        :data="data?.data ?? []"
        :get-row-id="row => String(row.id)"
        :watch-options="{ deep: false }"
        :loading="loading"
        empty="Tidak ada pengguna yang cocok."
      >
        <template #loading>
          <span role="status">Memuat pengguna…</span>
        </template>
        <template #display_name-cell="{ row: { original: staff } }">
          {{ staff.display_name || '—' }}
          <UBadge
            v-if="staff.id === user?.id"
            size="sm"
            variant="subtle"
          >
            Anda
          </UBadge>
        </template>
        <template #roles-cell="{ row: { original: staff } }">
          <div class="flex flex-wrap gap-1">
            <UBadge
              v-for="role in staff.roles"
              :key="role"
              variant="subtle"
            >
              {{ role }}
            </UBadge>
          </div>
        </template>
        <template #status-cell="{ row: { original: staff } }">
          <UBadge
            :color="!staff.active ? 'neutral' : staff.mfa_confirmed ? 'success' : 'warning'"
            variant="subtle"
          >
            {{ !staff.active ? 'Nonaktif' : staff.mfa_confirmed ? 'Aktif' : 'Diundang' }}
          </UBadge>
        </template>
        <template #actions-cell="{ row: { original: staff } }">
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
        </template>
      </UTable>
      <template
        v-if="data && !error"
        #footer
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-sm text-muted">{{ data.meta.total }} pengguna</span>
          <UPagination
            v-if="data.meta.last_page > 1"
            :page="params.page"
            :total="data.meta.total"
            :items-per-page="data.meta.per_page"
            :sibling-count="1"
            aria-label="Halaman pengguna"
            @update:page="changePage"
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
