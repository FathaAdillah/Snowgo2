<script setup lang="ts">
import type { RoleList } from '@snowego/api-types'

definePageMeta({ permission: 'admin.roles.view' })
useHead({ title: 'Role & Izin · Snowego Admin' })
const { can } = useAdminAuth()
const { data, error, refresh } = await useFetch<RoleList>('/api/v1/admin/roles', { cache: 'no-store', headers: { Accept: 'application/json' } })
const removing = ref<RoleList['data'][number]>()
async function saved() {
  removing.value = undefined
  await refresh()
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-highlighted">
          Role &amp; Izin
        </h1><p class="text-sm text-muted">
          Atur akses staf berdasarkan tugasnya.
        </p>
      </div>
      <UButton
        v-if="can('admin.roles.create') && can('admin.roles.assign_permissions')"
        to="/roles/new"
        icon="i-lucide-plus"
        label="Buat role"
      />
    </div>
    <UAlert
      v-if="error"
      color="error"
      title="Daftar role tidak dapat dimuat."
    />
    <div
      v-else
      class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
    >
      <UCard
        v-for="role in data?.data"
        :key="role.id"
      >
        <div class="flex items-center justify-between gap-2">
          <h2 class="text-lg font-semibold">
            {{ role.display_name }}
          </h2>
          <UBadge
            v-if="role.system"
            variant="subtle"
          >
            Sistem
          </UBadge>
        </div>
        <p class="text-xs text-muted">
          {{ role.name }}
        </p>
        <p class="my-3 min-h-10 text-sm">
          {{ role.description || (role.system ? 'Akses penuh setelah MFA. Role ini dilindungi.' : 'Belum ada deskripsi.') }}
        </p>
        <div class="flex flex-wrap gap-2">
          <UBadge
            variant="soft"
            color="neutral"
          >
            {{ role.members }} pengguna
          </UBadge>
          <UBadge
            variant="soft"
            color="neutral"
          >
            {{ role.system ? 'Semua izin' : `${role.permissions.length} izin` }}
          </UBadge>
        </div>
        <template #footer>
          <div class="flex gap-2">
            <UButton
              v-if="role.editable && can('admin.roles.update') && can('admin.roles.assign_permissions')"
              :to="`/roles/${role.id}`"
              label="Edit"
              variant="outline"
              icon="i-lucide-pencil"
            />
            <UButton
              v-else
              :to="`/roles/${role.id}`"
              label="Lihat detail"
              variant="ghost"
            />
            <UButton
              v-if="role.deletable && can('admin.roles.delete')"
              label="Hapus"
              color="error"
              variant="ghost"
              @click="removing = role"
            />
          </div>
        </template>
      </UCard>
    </div>
    <ConfirmAdminAction
      v-if="removing"
      :action="{ method: 'DELETE', url: `/api/v1/admin/roles/${removing.id}`, label: `Hapus role ${removing.display_name}?`, body: { revision: removing.revision } }"
      @cancel="removing = undefined"
      @saved="saved"
    />
  </section>
</template>
