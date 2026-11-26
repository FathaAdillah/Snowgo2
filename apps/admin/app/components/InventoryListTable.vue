<script setup lang="ts" generic="T extends { id: number | string }">
import type { TableColumn } from '@nuxt/ui'

defineProps<{
  title: string
  rows: T[]
  columns: TableColumn<T>[]
  status: string
  meta?: { page: number, total: number, per_page: number }
}>()
const emit = defineEmits<{ retry: [], page: [page: number] }>()
</script>

<template>
  <UCard :ui="{ body: 'p-3 sm:p-6' }">
    <div
      v-if="$slots.filters"
      class="mb-3 flex flex-wrap gap-2 border-b border-default pb-4"
    >
      <slot name="filters" />
    </div>
    <UAlert
      v-if="status === 'error'"
      color="error"
      :title="`${title} tidak dapat dimuat.`"
      :actions="[{ label: 'Coba lagi', onClick: () => emit('retry') }]"
    />
    <UTable
      v-else
      :caption="title"
      :columns="columns"
      :data="rows"
      :get-row-id="row => String(row.id)"
      :watch-options="{ deep: false }"
      :loading="status !== 'success'"
      :ui="{ th: 'py-4', td: 'py-3' }"
      empty="Tidak ada data yang cocok."
    >
      <template #loading>
        <span role="status">Memuat data…</span>
      </template>
      <template
        v-if="$slots.actions"
        #actions-cell="{ row }"
      >
        <div class="flex items-center gap-2">
          <slot
            name="actions"
            :item="row.original"
          />
        </div>
      </template>
      <template
        v-if="$slots.status"
        #status-cell="{ row }"
      >
        <slot
          name="status"
          :item="row.original"
        />
      </template>
    </UTable>
    <div
      v-if="status === 'success' && (meta?.total || $slots.cursor)"
      class="mt-4 flex flex-wrap items-center justify-end gap-3"
    >
      <UPagination
        v-if="meta && meta.total > 0"
        :page="meta.page"
        :items-per-page="meta.per_page"
        :total="meta.total"
        :sibling-count="1"
        show-controls
        first-icon="i-lucide-chevrons-left"
        prev-icon="i-lucide-chevron-left"
        next-icon="i-lucide-chevron-right"
        last-icon="i-lucide-chevrons-right"
        size="lg"
        :ui="{ item: 'min-w-9 justify-center', label: 'min-w-0' }"
        :aria-label="`Halaman ${title}`"
        @update:page="emit('page', $event)"
      />
      <slot name="cursor" />
    </div>
  </UCard>
</template>
