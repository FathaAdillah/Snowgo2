<script setup lang="ts">
import type { InventoryList } from '~/composables/useInventory'

const model = defineModel<number>()
const search = ref('')
const { params } = useInventoryPaging(() => ({ q: search.value.trim() || undefined, active: '1' }))
const { data, status, refresh } = useFetch<InventoryList<'/admin/warehouses'>>(`${inventoryApi}/warehouses`, { ...inventoryFetch, query: params })
const choices = computed(() => (data.value?.data ?? []).map(w => ({ value: w.id, label: `${w.code} — ${w.name}` })))
</script>

<template>
  <USelectMenu
    v-model="model"
    v-model:search-term="search"
    :items="choices"
    value-key="value"
    :ignore-filter="true"
    :loading="status === 'pending'"
    placeholder="Cari kode atau nama gudang aktif"
    class="w-full"
  />
  <UButton
    v-if="status === 'error'"
    label="Coba lagi memuat gudang"
    variant="link"
    @click="refresh()"
  />
</template>
