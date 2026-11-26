<script setup lang="ts">
import type { paths } from '@snowego/api-types'

definePageMeta({ permission: 'content.entry.view' })
type Page = paths['/admin/content']['get']['responses'][200]['content']['application/json']
type Entry = Page['data'][number]
const base = '/api/v1/admin/content'
const { can } = useAdminAuth()
const q = ref('')
const page = ref(1)
watch(q, () => {
  page.value = 1
})
const { data, status, refresh } = useFetch<Page>(base, { ...inventoryFetch, query: computed(() => ({ q: q.value || undefined, page: page.value })) })
const edit = ref<Entry>()
const preview = ref<Entry>()
const columns = [{ accessorKey: 'title', header: 'Judul' }, { accessorKey: 'slug', header: 'Slug' }, { accessorKey: 'type', header: 'Jenis' }, { accessorKey: 'status', header: 'Status editor' }, { id: 'actions', header: 'Aksi' }]
const { action, ask, finish } = useCaseAction(base, async () => {
  edit.value = undefined
  await refresh()
})
function open(row?: Entry) {
  edit.value = row ? { ...row } : { id: 0, version: 0, title: '', slug: '', type: 'PAGE', body: '', status: 'DRAFT', sort_order: 0, publish_at: null, media_id: null }
}
function save() {
  if (!edit.value) return
  const { id, version, publish_at, media_id, ...fields } = edit.value
  ask(id ? '/' + id : '', { ...fields, ...(id ? { version } : {}), publish_at: publish_at || null, media_id: media_id || null }, 'Simpan ' + fields.title, id ? 'PATCH' : 'POST')
}
</script>

<template>
  <section class="space-y-5">
    <h1 class="text-2xl font-bold">
      Konten Storefront
    </h1>
    <InventoryListTable
      title="Halaman, FAQ dan banner"
      :rows="data?.data ?? []"
      :columns="columns"
      :meta="data?.meta"
      :status="status"
      @retry="refresh()"
      @page="page = $event"
    >
      <template #filters>
        <UInput
          v-model="q"
          aria-label="Cari judul"
          placeholder="Cari judul"
        />
        <UButton
          v-if="can('content.entry.create')"
          label="Buat konten"
          @click="open()"
        />
      </template>
      <template #actions="{ item }">
        <UButton
          label="Pratinjau"
          variant="ghost"
          @click="preview = item"
        />
        <UButton
          v-if="can('content.entry.update')"
          label="Ubah"
          @click="open(item)"
        />
      </template>
    </InventoryListTable>
    <UCard v-if="preview">
      <h2>{{ preview.title }}</h2>
      <div v-html="preview.body" />
      <UButton
        label="Tutup pratinjau"
        @click="preview = undefined"
      />
    </UCard>
    <form
      v-if="edit"
      class="grid gap-3"
      @submit.prevent="save"
    >
      <UFormField label="Judul">
        <UInput
          v-model="edit.title"
          required
          maxlength="160"
        />
      </UFormField>
      <UFormField label="Slug">
        <UInput
          v-model="edit.slug"
          required
          pattern="[a-z0-9]+(-[a-z0-9]+)*"
          :disabled="!!edit.id"
        />
      </UFormField>
      <UFormField label="Jenis">
        <USelect
          v-model="edit.type"
          :items="['PAGE', 'FAQ', 'BANNER', 'FEATURED']"
        />
      </UFormField>
      <UFormField label="Isi">
        <UTextarea
          v-model="edit.body"
          required
          :rows="7"
        />
      </UFormField>
      <UFormField label="ID gambar katalog terbit">
        <UInput v-model="edit.media_id" />
      </UFormField>
      <UFormField label="Urutan">
        <UInput
          v-model="edit.sort_order"
          type="number"
          min="0"
        />
      </UFormField>
      <UFormField label="Status">
        <USelect
          v-model="edit.status"
          :items="['DRAFT', ...(can('content.entry.publish') ? ['PUBLISHED'] : []), ...(can('content.entry.archive') ? ['ARCHIVED'] : [])]"
        />
      </UFormField>
      <UFormField
        v-if="edit.status === 'PUBLISHED'"
        label="Waktu terbit (ISO 8601 beserta zona waktu, kosong untuk sekarang)"
      >
        <UInput v-model="edit.publish_at" />
      </UFormField>
      <div class="flex gap-2">
        <UButton
          type="submit"
          label="Simpan"
        /><UButton
          label="Batal"
          variant="outline"
          @click="edit = undefined"
        />
      </div>
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @cancel="action = undefined"
      @saved="finish"
    />
  </section>
</template>
