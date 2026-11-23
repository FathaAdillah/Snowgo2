<script setup lang="ts">
import type { paths } from '@snowego/api-types'
import type { InventoryAction, Warehouse } from '~/composables/useInventory'

const props = defineProps<{ warehouse?: Warehouse }>()
const emit = defineEmits<{ cancel: [], saved: [] }>()
const { can } = useAdminAuth()
const form = reactive({ code: props.warehouse?.code ?? '', name: props.warehouse?.name ?? '', district_code: props.warehouse?.district_code ?? '', address: props.warehouse?.address ?? '' })
const promote = ref(false)
const action = ref<InventoryAction>()
function submit() {
  const existing = props.warehouse
  if (!can(`inventory.warehouse.${existing ? 'update' : 'create'}`)) return
  if (existing) {
    const body: NonNullable<paths['/admin/warehouses/{id}']['patch']['requestBody']>['content']['application/json'] = {}
    for (const key of Object.keys(form) as (keyof typeof form)[]) {
      if (form[key].trim() !== existing[key]) body[key] = form[key].trim()
    }
    if (promote.value && existing.active && !existing.is_default) body.is_default = true
    if (!Object.keys(body).length) return emit('cancel')
    action.value = { url: `${inventoryApi}/warehouses/${existing.id}`, method: 'PATCH', body, label: `Simpan perubahan gudang ${existing.code}${body.is_default ? ' dan jadikan gudang utama' : ''}?` }
  } else {
    const body = { ...form, active: true, is_default: false } satisfies paths['/admin/warehouses']['post']['requestBody']['content']['application/json']
    action.value = { url: `${inventoryApi}/warehouses`, method: 'POST', body, label: `Buat gudang ${form.code}?` }
  }
}
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="font-semibold">
        {{ warehouse ? `Edit gudang ${warehouse.code}` : 'Tambah gudang aktif' }}
      </h2>
    </template>
    <form
      class="grid gap-4 sm:grid-cols-2"
      @submit.prevent="submit"
    >
      <UFormField label="Kode gudang">
        <UInput
          v-model.trim="form.code"
          required
          pattern="[A-Za-z0-9_.\-]+"
          maxlength="32"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Nama gudang">
        <UInput
          v-model.trim="form.name"
          required
          maxlength="120"
          class="w-full"
        />
      </UFormField>
      <UFormField
        label="Kode kecamatan"
        hint="Contoh: 11.01.01"
      >
        <UInput
          v-model.trim="form.district_code"
          required
          maxlength="48"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Alamat">
        <UInput
          v-model.trim="form.address"
          required
          maxlength="255"
          class="w-full"
        />
      </UFormField>
      <UCheckbox
        v-if="warehouse?.active && !warehouse.is_default"
        v-model="promote"
        label="Jadikan gudang utama"
      />
      <div class="flex justify-end gap-2 sm:col-span-2">
        <UButton
          label="Batal"
          variant="outline"
          @click="emit('cancel')"
        />
        <UButton
          label="Simpan"
          type="submit"
        />
      </div>
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @cancel="action = undefined"
      @saved="emit('saved')"
    />
  </UCard>
</template>
