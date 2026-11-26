<script setup lang="ts">
import type { InventoryAction } from '~/composables/useInventory'

definePageMeta({ permission: 'shipping.courier.view' })
const fields = { code: 'Kode', name: 'Nama' }
const { can } = useAdminAuth()
const groups = computed(() => [
  ...['manage', 'create', 'update'].every(action => can(`shipping.courier.${action}`)) ? [{ label: 'Kurir', value: 'couriers' }] : [],
  ...can('geography.region.import')
    ? [
        { label: 'Provinsi', value: 'provinces' }, { label: 'Kabupaten/kota', value: 'cities' }, { label: 'Kecamatan', value: 'districts' }
      ]
    : []
])
const group = ref('')
const parent = ref('')
const action = ref<InventoryAction>()
const needsParent = computed(() => ['cities', 'districts'].includes(group.value))
function sync() {
  if (!groups.value.some(g => g.value === group.value)) return
  action.value = { method: 'POST', url: `${inventoryApi}/courier-master-syncs`, body: { group: group.value, ...(needsParent.value ? { parent: parent.value } : {}) }, label: 'Sinkronkan daftar ini dari API.co.id? ID dan status aktif lokal dipertahankan.' }
}
async function saved() {
  action.value = undefined
  await refreshNuxtData()
}
</script>

<template>
  <section class="space-y-4">
    <UCard v-if="can('shipping.provider.sync') && groups.length">
      <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="sync"
      >
        <UFormField label="Master API.co.id">
          <USelect
            v-model="group"
            :items="groups"
            placeholder="Pilih daftar"
          />
        </UFormField>
        <UFormField
          v-if="needsParent"
          :label="group === 'cities' ? 'Kode provinsi provider' : 'Kode kota/kabupaten provider'"
        >
          <UInput
            v-model="parent"
            required
            :pattern="group === 'cities' ? '[0-9]{2}' : '[0-9]{4}'"
          />
        </UFormField>
        <UButton
          type="submit"
          :disabled="!group"
          label="Sinkronkan master"
        />
      </form>
      <ConfirmAdminAction
        v-if="action"
        :action="action"
        @cancel="action = undefined"
        @saved="saved"
      />
    </UCard>
    <ShippingMasterTable
      resource="couriers"
      grant="shipping.courier"
      title="Kurir"
      :fields="fields"
    />
  </section>
</template>
