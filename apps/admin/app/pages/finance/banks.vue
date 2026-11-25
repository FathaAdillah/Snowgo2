<script setup lang="ts">
import type { paths } from '@snowego/api-types'

definePageMeta({ permission: 'payments.bank.view' })
useHead({ title: 'Rekening Tujuan · Finance' })
type Page = paths['/admin/bank-accounts']['get']['responses'][200]['content']['application/json']
type Bank = Page['data'][number]
const url = '/api/v1/admin/bank-accounts'
const { can } = useAdminAuth()
const may = (action: string) => can('payments.bank.manage') && can(`payments.bank.${action}`)
const search = ref('')
const { params, changePage } = useInventoryPaging(() => ({ q: search.value.trim() || undefined }))
const { data, status, refresh } = useFetch<Page>(url, { ...inventoryFetch, query: params })
const edit = ref<number>()
const form = ref({ code: '', bank_code: '', holder_name: '', account_number: '', active: true })
const action = ref<{ url: string, method: 'POST' | 'PATCH', body: Record<string, unknown>, label: string }>()
function open(b?: Bank) {
  edit.value = b?.id ?? 0
  form.value = { code: b?.code ?? '', bank_code: b?.bank_code ?? '', holder_name: b?.holder_name ?? '', account_number: '', active: b?.active ?? true }
}
function save() {
  const { account_number, ...fields } = form.value
  action.value = { url: edit.value ? `${url}/${edit.value}` : url, method: edit.value ? 'PATCH' : 'POST', body: account_number ? { ...fields, account_number } : fields, label: `Simpan rekening ${fields.code} untuk ${fields.holder_name}` }
}
async function saved() {
  action.value = undefined
  edit.value = undefined
  form.value.account_number = ''
  await refresh()
}
</script>

<template>
  <section class="space-y-5">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">
        Rekening tujuan
      </h1>
      <UButton
        v-if="may('create')"
        label="Tambah rekening"
        @click="open()"
      />
    </header>
    <UCard v-if="edit !== undefined && may(edit ? 'update' : 'create')">
      <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="save"
      >
        <UFormField label="Kode rekening">
          <UInput
            v-model="form.code"
            required
            :disabled="!!edit"
          />
        </UFormField>
        <UFormField label="Kode bank">
          <UInput
            v-model="form.bank_code"
            required
          />
        </UFormField>
        <UFormField :label="edit ? 'Nomor baru (opsional)' : 'Nomor rekening'">
          <UInput
            v-model="form.account_number"
            :required="!edit"
            inputmode="numeric"
            pattern="[0-9]{6,32}"
          />
        </UFormField>
        <UFormField label="Atas nama">
          <UInput
            v-model="form.holder_name"
            required
          />
        </UFormField>
        <USwitch
          v-model="form.active"
          label="Aktif"
        />
        <UButton
          type="submit"
          label="Simpan"
        /><UButton
          variant="outline"
          label="Batal"
          @click="edit = undefined"
        />
      </form>
    </UCard>
    <InventoryListTable
      title="Daftar rekening"
      :rows="data?.data ?? []"
      :status="status"
      :meta="data?.meta"
      :columns="[{ accessorKey: 'code', header: 'Kode' }, { accessorKey: 'bank_code', header: 'Bank' }, { accessorKey: 'masked_number', header: 'Nomor' }, { accessorKey: 'holder_name', header: 'Atas nama' }, { accessorKey: 'active', header: 'Aktif' }, { id: 'actions', header: 'Aksi' }]"
      @retry="refresh()"
      @page="changePage"
    >
      <template #filters>
        <UFormField label="Cari rekening">
          <UInput v-model="search" />
        </UFormField>
      </template>
      <template #actions="{ item }">
        <UButton
          v-if="may('update')"
          label="Ubah"
          variant="outline"
          @click="open(item)"
        />
      </template>
    </InventoryListTable>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @saved="saved"
      @cancel="action = undefined"
    />
  </section>
</template>
