<script setup lang="ts">
import type { InventoryAction } from '~/composables/useInventory'

const props = defineProps<{ resource: string, grant: string, title: string, fields: Record<string, string> }>()
type Row = { id: number, active: boolean } & Record<string, string | number | boolean>
const { can } = useAdminAuth()
const query = ref('')
const { params, changePage } = useInventoryPaging(() => ({ q: query.value.trim() || undefined }))
const url = `${inventoryApi}/${props.resource}`
const { data, status, refresh } = useFetch<{ data: Row[], meta: { page: number, per_page: number, total: number } }>(url, { ...inventoryFetch, query: params })
const columns = [{ accessorKey: 'id', header: 'ID' }, ...Object.entries(props.fields).map(([accessorKey, header]) => ({ accessorKey, header })), { accessorKey: 'active', header: 'Aktif' }, { id: 'actions', header: 'Aksi' }]
const allowed = (key: string) => can(`${props.grant}.manage`) && can(`${props.grant}.${key}`)
const editing = ref<number | null>()
const draft = ref<Record<string, string | number>>({})
const action = ref<InventoryAction>()
function edit(row?: Row) {
  editing.value = row?.id ?? null
  draft.value = Object.fromEntries(Object.keys(props.fields).map(k => [k, typeof row?.[k] === 'number' ? Number(row[k]) : String(row?.[k] ?? '')]))
}
function save() {
  const id = editing.value
  const body = Object.fromEntries(Object.entries(draft.value).map(([k, v]) => [k, ['code', 'name'].includes(k) ? String(v).trim() : Number(v)]))
  action.value = { url: id ? `${url}/${id}` : url, method: id ? 'PATCH' : 'POST', body: id ? body : { ...body, active: true }, label: `${id ? `Ubah #${id}` : 'Buat'} · ${props.title}` }
}
function toggle(row: Row) {
  action.value = { url: `${url}/${row.id}`, method: 'PATCH', body: { active: !row.active }, label: `${row.active ? 'Arsipkan' : 'Aktifkan'} ${props.title} #${row.id}` }
}
async function saved() {
  action.value = editing.value = undefined
  await refresh()
}
</script>

<template>
  <section class="space-y-5">
    <h1 class="text-2xl font-bold">
      {{ title }}
    </h1>
    <InventoryListTable
      :title="title"
      :columns="columns"
      :rows="data?.data ?? []"
      :status="status"
      :meta="data?.meta"
      @page="changePage"
      @retry="refresh()"
    >
      <template #filters>
        <UInput
          v-model="query"
          :placeholder="resource === 'shipping-rates' ? 'Cari ID tarif' : 'Cari nama atau kode'"
        /><UButton
          v-if="allowed('create')"
          label="Buat"
          @click="edit()"
        />
      </template>
      <template #actions="{ item }">
        <UButton
          v-if="allowed('update')"
          label="Edit"
          variant="ghost"
          @click="edit(item)"
        /><UButton
          v-if="allowed('update') && (!item.active || allowed('archive'))"
          :label="item.active ? 'Arsipkan' : 'Aktifkan'"
          variant="ghost"
          @click="toggle(item)"
        />
      </template>
    </InventoryListTable>
    <UCard v-if="editing !== undefined">
      <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="save"
      >
        <UFormField
          v-for="(label, field) in props.fields"
          :key="field"
          :label="label"
        >
          <UInput
            v-model="draft[field]"
            required
            :disabled="!!editing && field.endsWith('_id')"
            :type="['code', 'name'].includes(field) ? 'text' : 'number'"
          />
        </UFormField><UButton
          type="submit"
          label="Tinjau"
        /><UButton
          variant="ghost"
          label="Batal"
          @click="editing = undefined"
        />
      </form>
    </UCard>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="saved"
      @cancel="action = undefined"
    />
  </section>
</template>
