<script setup lang="ts">
definePageMeta({ permission: 'shipping.rate.view' })
const fields = { service_id: 'ID layanan', origin_region_id: 'ID kecamatan asal', destination_region_id: 'ID kecamatan tujuan', base_amount: 'Tarif dasar (Rp)', amount_per_kg: 'Per kg (Rp)', eta_min_days: 'ETA minimum (hari)', eta_max_days: 'ETA maksimum (hari)' }
</script>

<template>
  <ShippingMasterTable
    resource="shipping-rates"
    grant="shipping.rate"
    title="Tarif Kirim"
    :fields="fields"
  />
</template>
