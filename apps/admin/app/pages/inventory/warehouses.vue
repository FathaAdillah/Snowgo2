<script setup lang="ts">
import { warehouseAccess } from '~/composables/useAdminNavigation'
import type { Warehouse } from '~/composables/useInventory'

definePageMeta({ permission: warehouseAccess })
useHead({ title: 'Gudang · Snowego Admin' })
const { can } = useAdminAuth()
const editing = ref<Warehouse | 'new'>()
const list = useTemplateRef('list')
async function saved() {
  editing.value = undefined
  await list.value?.refresh()
}
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-highlighted">
          Gudang
        </h1><p class="text-sm text-muted">
          Kelola lokasi penyimpanan dan gudang utama untuk stok siap kirim.
        </p>
      </div>
      <UButton
        v-if="can('inventory.warehouse.create')"
        label="Tambah Gudang"
        icon="i-lucide-plus"
        size="lg"
        @click="editing = 'new'"
      />
    </header>
    <InventoryWarehouseForm
      v-if="editing"
      :key="editing === 'new' ? 'new' : editing.id"
      :warehouse="editing === 'new' ? undefined : editing"
      @cancel="editing = undefined"
      @saved="saved"
    />
    <InventoryWarehouseList
      v-if="can('inventory.warehouse.view')"
      ref="list"
      @edit="editing = $event"
    />
  </section>
</template>
