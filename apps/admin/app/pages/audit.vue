<script setup lang="ts">
import type { paths } from '@snowego/api-types'

definePageMeta({ permission: 'admin.audit.view' })
type Page = paths['/admin/audit-logs']['get']['responses'][200]['content']['application/json']
const filters = reactive({ actor_id: '', action: '', subject_type: '', from: '', to: '' })
const cursors = ref<string[]>([])
watch(filters, () => {
  cursors.value = []
}, { flush: 'sync' })
const query = computed(() => ({ ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value)), cursor: cursors.value.at(-1) }))
const { data, status, refresh } = useFetch<Page>('/api/v1/admin/audit-logs', { ...inventoryFetch, query })
const columns = [{ accessorKey: 'occurred_at', header: 'Waktu' }, { accessorKey: 'actor_id', header: 'ID staf / pengguna' }, { accessorKey: 'action', header: 'Aksi' }, { accessorKey: 'subject_type', header: 'Entitas' }, { accessorKey: 'subject_ref', header: 'ID entitas' }, { accessorKey: 'request_id', header: 'ID request' }]
</script>

<template>
  <section class="space-y-5">
    <h1 class="text-2xl font-bold">
      Audit Log
    </h1>
    <InventoryListTable
      title="Riwayat aktivitas"
      :columns="columns"
      :rows="data?.data ?? []"
      :status="status"
      @retry="refresh()"
    >
      <template #filters>
        <UFormField label="ID pelaku">
          <UInput
            v-model="filters.actor_id"
            type="number"
            min="1"
          />
        </UFormField>
        <UFormField label="Aksi">
          <UInput v-model="filters.action" />
        </UFormField>
        <UFormField label="Entitas">
          <UInput v-model="filters.subject_type" />
        </UFormField>
        <UFormField label="Dari">
          <UInput
            v-model="filters.from"
            type="date"
          />
        </UFormField>
        <UFormField label="Sampai">
          <UInput
            v-model="filters.to"
            type="date"
          />
        </UFormField>
      </template>
      <template #cursor>
        <UButton
          label="Sebelumnya"
          :disabled="!cursors.length"
          @click="cursors.pop()"
        />
        <UButton
          label="Berikutnya"
          :disabled="!data?.meta.next_cursor"
          @click="data?.meta.next_cursor && cursors.push(data.meta.next_cursor)"
        />
      </template>
    </InventoryListTable>
  </section>
</template>
