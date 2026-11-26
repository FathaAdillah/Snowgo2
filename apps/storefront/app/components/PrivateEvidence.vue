<script setup lang="ts">
const props = defineProps<{ owner: string, number: string, collection: string, accept?: string }>()
const emit = defineEmits<{ ready: [id: string], uploaded: [] }>()
const file = ref<File>()
const id = ref('')
const scan = ref('')
const { busy, problem, run } = useCustomerAction()
watch(file, () => {
  id.value = ''
  scan.value = ''
  emit('ready', '')
})
async function inspect() {
  const result = await $fetch<StoreRead<'/files/{id}'>>('/api/v1/files/' + id.value)
  scan.value = result.data.status
  if (scan.value === 'READY') emit('ready', id.value)
}
async function upload() {
  if (!file.value) return
  const form = new FormData()
  form.append('file', file.value)
  form.append('collection', props.collection)
  form.append('owner_type', props.owner)
  form.append('owner_ref', props.number)
  const result = await customerWrite<{ data: { id: string, status: string } }>('/files', 'POST', form)
  id.value = result.data.id
  scan.value = result.data.status
  emit('uploaded')
  await inspect()
}
</script>

<template>
  <div class="stack">
    <label>Lampiran privat<input
      :disabled="busy"
      type="file"
      :accept="accept"
      @change="file = ($event.target as HTMLInputElement).files?.[0]"
    >
    </label>
    <div class="actions">
      <button
        type="button"
        :disabled="busy || !file"
        @click="run(upload)"
      >
        Unggah
      </button>
      <button
        v-if="id"
        type="button"
        class="subtle"
        :disabled="busy"
        @click="run(inspect)"
      >
        Periksa status
      </button>
    </div>
    <p
      v-if="scan"
      role="status"
    >
      Status berkas · {{ scan }}
    </p>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </div>
</template>
