<script setup lang="ts">
definePageMeta({ permission: 'shipping.service.view' })
const fields = { courier_id: 'ID kurir', code: 'Kode', name: 'Nama' }
</script>

<template>
  <ShippingMasterTable
    resource="courier-services"
    grant="shipping.service"
    title="Layanan Kurir"
    :fields="fields"
  />
</template>
