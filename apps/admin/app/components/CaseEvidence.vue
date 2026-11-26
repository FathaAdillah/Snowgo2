<script setup lang="ts">
const props = defineProps<{ owner: 'refund' | 'return', number: string, collection: string, uploadAllowed: boolean, evidence?: { id: string, status: string }[] }>()
const emit = defineEmits<{ uploaded: [id: string] }>()
const { can, csrf } = useAdminAuth()
const busy = ref(false)
const notice = ref('')
async function upload(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  busy.value = true
  const form = new FormData()
  form.set('owner_type', props.owner)
  form.set('owner_ref', props.number)
  form.set('collection', props.collection)
  form.set('file', file)
  try {
    const response = await $fetch<{ data: { id: string } }>('/api/v1/admin/files', { method: 'POST', body: form, headers: { 'X-XSRF-TOKEN': await csrf(), 'Idempotency-Key': crypto.randomUUID() } })
    emit('uploaded', response.data.id)
    notice.value = 'Bukti diterima. Tunggu pemindaian sebelum konfirmasi.'
  } catch (error) {
    notice.value = authErrorMessage(error)
  } finally {
    busy.value = false
    input.value = ''
  }
}
async function download(id: string) {
  try {
    const response = await $fetch<{ data: { url: string } }>(`/api/v1/admin/files/${id}/download`)
    window.location.assign(response.data.url)
  } catch (error) { notice.value = authErrorMessage(error) }
}
</script>

<template>
  <div class="space-y-2">
    <UFormField
      v-if="uploadAllowed && can('foundation.file.create')"
      label="Unggah bukti privat"
    >
      <input
        type="file"
        :disabled="busy"
        @change="upload"
      >
    </UFormField>
    <div
      v-for="file in evidence"
      :key="file.id"
      class="flex gap-2 items-center"
    >
      <span>{{ file.id }} · {{ file.status }}</span>
      <AdminRowAction
        v-if="file.status === 'READY' && can('foundation.file.download')"
        :label="`Unduh bukti ${file.id}`"
        icon="i-lucide-download"
        @click="download(file.id)"
      />
    </div>
    <p role="status">
      {{ notice }}
    </p>
  </div>
</template>
