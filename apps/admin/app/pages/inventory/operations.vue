<script setup lang="ts">
import { operationAccess } from '~/composables/useAdminNavigation'

definePageMeta({ permission: operationAccess, key: route => route.fullPath })
useHead({ title: 'Penerimaan & Koreksi · Snowego Admin' })
const { can } = useAdminAuth()
const route = useRoute()
const initial = Number(route.query.level)
const initialLevel = Number.isInteger(initial) && initial > 0 && initial <= 2147483647 ? initial : undefined
const mode = ref<'receipt' | 'adjustment' | undefined>(initialLevel && can('inventory.adjustment.create') ? 'adjustment' : undefined)
const history = useTemplateRef('history')
async function saved() {
  const adjusted = mode.value === 'adjustment'
  mode.value = undefined
  if (adjusted) await history.value?.refresh()
}
</script>

<template>
  <section class="min-w-0 space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-highlighted">
          Penerimaan &amp; Koreksi
        </h1><p class="text-sm text-muted">
          Catat barang masuk atau selisih hasil penghitungan fisik.
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <UButton
          v-if="can('inventory.receipt.create')"
          icon="i-lucide-plus"
          label="Terima Stok"
          @click="mode = 'receipt'"
        />
        <UButton
          v-if="can('inventory.adjustment.create')"
          icon="i-lucide-clipboard-pen"
          label="Koreksi Stok"
          variant="outline"
          @click="mode = 'adjustment'"
        />
      </div>
    </header>
    <InventoryStockForm
      v-if="mode && can(mode === 'receipt' ? 'inventory.receipt.create' : 'inventory.adjustment.create')"
      :key="mode"
      :mode="mode"
      :initial-level="initialLevel"
      @cancel="mode = undefined"
      @saved="saved"
    />
    <InventoryAdjustmentList
      v-if="can('inventory.adjustment.view')"
      ref="history"
    />
  </section>
</template>
