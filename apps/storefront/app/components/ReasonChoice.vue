<script setup lang="ts">
const props = defineProps<{ domain: string }>()
const model = defineModel<string>({ required: true })
const { data, status, refresh } = await useFetch<{ data: { code: string, label: string }[] }>('/api/v1/customer-reasons', { server: false, query: { domain: props.domain } })
</script>

<template>
  <StoreResult
    :status="status"
    @retry="refresh()"
  >
    <label>Alasan<select
      v-model="model"
      required
    >
      <option
        value=""
        disabled
      >Pilih alasan</option>
      <option
        v-for="r in data?.data"
        :key="r.code"
        :value="r.code"
      >{{ r.label }}</option>
    </select>
    </label>
    <small v-if="!data?.data.length">Belum ada alasan yang diaktifkan toko.</small>
  </StoreResult>
</template>
