<script setup lang="ts">
definePageMeta({ middleware: 'customer', key: route => route.path })
const base = '/returns/' + encodeURIComponent(String(useRoute().params.number))
const { data, status, refresh } = await useFetch<StoreRead<'/returns/{number}'>>('/api/v1' + base, { server: false })
const r = computed(() => data.value?.data)
const tracking = ref('')
const { busy, problem, run } = useCustomerAction()
async function dispatch() {
  await customerWrite(base + '/dispatches', 'POST', { tracking_number: tracking.value })
  await refresh()
}
</script>

<template>
  <section class="stack">
    <NuxtLink to="/purnajual">← Refund & Retur</NuxtLink>
    <h1>Detail retur</h1>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="r">
        <div class="panel stack">
          <h2>{{ r.number }}</h2>
          <p>{{ r.status }} · {{ r.reason_code }}</p>
          <NuxtLink :to="'/pengiriman/' + r.shipment_number">Paket {{ r.shipment_number }}</NuxtLink>
          <p v-if="r.instructions">
            Instruksi toko · {{ r.instructions }}
          </p>
          <p v-if="r.customer_tracking_number">
            Resi retur · {{ r.customer_tracking_number }}
          </p>
          <p v-if="r.resolution">
            {{ r.resolution }} · {{ r.outcome_note }}
          </p>
          <NuxtLink
            v-if="r.refund_number"
            :to="'/purnajual/refund/' + r.refund_number"
          >Refund {{ r.refund_number }}</NuxtLink>
          <NuxtLink
            v-if="r.replacement_number"
            :to="'/pengiriman/' + r.replacement_number"
          >Lacak pengganti</NuxtLink>
        </div>
        <div class="panel stack">
          <h2>Bukti retur</h2>
          <p v-if="['REQUESTED', 'UNDER_REVIEW'].includes(r.status)">
            Unggah minimal dua foto berbeda untuk pemeriksaan toko.
          </p>
          <div
            v-for="f in r.evidence"
            :key="f.id"
          >
            {{ f.status }} <PrivateDownload
              v-if="f.status === 'READY'"
              :path="'/files/' + f.id + '/download'"
              label="Unduh bukti"
            />
          </div>
          <PrivateEvidence
            v-if="['REQUESTED', 'UNDER_REVIEW'].includes(r.status)"
            owner="return"
            :number="r.number"
            collection="return_evidence"
            accept="image/jpeg,image/png,image/webp"
            @uploaded="refresh()"
            @ready="refresh()"
          />
        </div>
        <form
          v-if="r.status === 'AWAITING_SHIPMENT'"
          class="panel stack"
          @submit.prevent="run(dispatch)"
        >
          <label>Resi pengiriman balik<input
            v-model="tracking"
            required
            maxlength="120"
          >
          </label>
          <button :disabled="busy">
            Konfirmasi pengiriman
          </button>
        </form>
      </template>
    </StoreResult>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </section>
</template>
