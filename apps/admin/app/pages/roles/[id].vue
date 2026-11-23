<script setup lang="ts">
import type { PermissionCatalog, RoleDetail } from '@snowego/api-types'

definePageMeta({ permission: 'admin.roles.view', key: route => route.path })
const { can } = useAdminAuth()
const id = String(useRoute().params.id)
const creating = id === 'new'
if (!creating && !/^[1-9]\d*$/.test(id)) throw createError({ statusCode: 404 })
if (creating && !(can('admin.roles.create') && can('admin.roles.assign_permissions'))) throw createError({ statusCode: 403 })
const base = '/api/v1/admin/roles'
const options = { cache: 'no-store' as const, headers: { Accept: 'application/json' } }
const [catalog, detail] = await Promise.all([
  $fetch<PermissionCatalog>('/api/v1/admin/permissions', options),
  creating ? undefined : $fetch<RoleDetail>(`${base}/${id}`, options)
])
const role = detail?.data
const editable = computed(() => creating || (!!role?.editable && can('admin.roles.update') && can('admin.roles.assign_permissions')))
const title = creating ? 'Buat role' : editable.value ? 'Edit role' : 'Detail role'
useHead({ title: `${title} · Snowego Admin` })
const name = ref('')
const display = ref(role?.display_name ?? '')
const description = ref(role?.description ?? '')
const selected = ref<string[]>(role?.system ? catalog.data.map(p => p.name) : [...(role?.permissions ?? [])])
const confirm = ref(false)
const body = computed(() => ({
  ...(creating ? { name: name.value } : { revision: role?.revision }),
  display_name: display.value.trim(), description: description.value.trim(), permissions: selected.value
}))
</script>

<template>
  <section class="space-y-5">
    <UButton
      to="/roles"
      variant="link"
      icon="i-lucide-arrow-left"
      label="Role & Izin"
    />
    <h1 class="text-2xl font-bold text-highlighted">
      {{ title }}
    </h1>
    <UAlert
      v-if="role?.system"
      title="Role sistem dilindungi"
      description="Superadmin memiliki seluruh izin setelah MFA. Akses alat operator tidak bisa didelegasikan."
    />
    <form
      class="space-y-5"
      @submit.prevent="editable && (confirm = true)"
    >
      <UCard>
        <div class="grid gap-4 sm:grid-cols-2">
          <UFormField label="Nama tampilan">
            <UInput
              v-model="display"
              required
              maxlength="100"
              class="w-full"
              :readonly="!editable"
            />
          </UFormField>
          <UFormField
            label="Kode role"
            description="Huruf kecil, angka, dan garis bawah. Kode tidak dapat diubah."
          >
            <UInput
              v-if="creating"
              v-model="name"
              required
              pattern="[a-z][a-z0-9_]*"
              maxlength="64"
              class="w-full"
            />
            <UInput
              v-else
              :model-value="role?.name"
              readonly
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Deskripsi"
            class="sm:col-span-2"
          >
            <UTextarea
              v-model="description"
              maxlength="500"
              class="w-full"
              :readonly="!editable"
            />
          </UFormField>
        </div>
      </UCard>
      <RolePermissionPicker
        v-model="selected"
        :catalog="catalog.data"
        :readonly="!editable"
      />
      <div class="flex justify-end gap-2">
        <UButton
          to="/roles"
          variant="outline"
          :label="editable ? 'Batal' : 'Kembali'"
        />
        <UButton
          v-if="editable"
          type="submit"
          label="Simpan role"
        />
      </div>
    </form>
    <ConfirmAdminAction
      v-if="confirm"
      :action="{ method: creating ? 'POST' : 'PATCH', url: creating ? base : `${base}/${id}`, label: `Simpan ${display} dengan ${selected.length} izin?`, body }"
      @cancel="confirm = false"
      @saved="navigateTo('/roles')"
    />
  </section>
</template>
