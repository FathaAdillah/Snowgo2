<script setup lang="ts">
definePageMeta({ permission: 'shipping.courier.view' })
const fields = { code: 'Kode', name: 'Nama' }
</script>

<template>
  <ShippingMasterTable
    resource="couriers"
    grant="shipping.courier"
    title="Kurir"
    :fields="fields"
  />
</template>
