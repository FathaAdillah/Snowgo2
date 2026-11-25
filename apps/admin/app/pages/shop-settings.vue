<script setup lang="ts">
import type { paths } from '@snowego/api-types'

definePageMeta({ permission: 'admin.setting.view' })
type Page = paths['/admin/settings']['get']['responses'][200]['content']['application/json']
type Row = Page['data'][number]
const base = '/api/v1/admin/settings'
const { can } = useAdminAuth()
const { data, status, refresh } = useFetch<Page>(base, inventoryFetch)
const edit = ref<Row>()
const value = ref(0)
const { action, ask, finish } = useCaseAction(base, async () => {
  edit.value = undefined
  await refresh()
})
const labels: Record<string, string> = { 'inventory.reservation_ttl_minutes': 'Durasi reservasi RTS (menit)', 'payment.verification_hold_hours': 'Durasi pemeriksaan pembayaran (jam)', 'returns.window_hours': 'Jendela pengajuan retur (jam)', 'orders.auto_complete_hours': 'Penundaan pesanan selesai (jam)' }
const columns = [{ accessorKey: 'label', header: 'Pengaturan' }, { accessorKey: 'value', header: 'Nilai' }, { accessorKey: 'minimum', header: 'Minimum' }, { accessorKey: 'maximum', header: 'Maksimum' }, { id: 'actions', header: 'Aksi' }]
</script>

<template>
  <section class="space-y-5">
    <h1 class="text-2xl font-bold">
      Pengaturan Toko
    </h1>
    <InventoryListTable
      title="Batas waktu operasional"
      :columns="columns"
      :rows="(data?.data ?? []).map(r => ({ ...r, id: r.key, label: labels[r.key] }))"
      :status="status"
      @retry="refresh()"
    >
      <template #actions="{ item }">
        <UButton
          v-if="can('admin.setting.manage')"
          label="Ubah"
          @click="edit = item; value = item.value"
        />
      </template>
    </InventoryListTable>
    <form
      v-if="edit && can('admin.setting.manage')"
      class="flex items-end gap-2"
      @submit.prevent="ask('/' + edit.key, { value, revision: edit.revision }, 'Simpan ' + labels[edit.key], 'PUT')"
    >
      <UFormField :label="labels[edit.key]">
        <UInput
          v-model="value"
          required
          type="number"
          :min="edit.minimum"
          :max="edit.maximum"
        />
      </UFormField>
      <UButton
        label="Simpan"
        type="submit"
      /><UButton
        variant="outline"
        label="Batal"
        @click="edit = undefined; refresh()"
      />
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @cancel="action = undefined"
      @saved="finish"
    />
  </section>
</template>
