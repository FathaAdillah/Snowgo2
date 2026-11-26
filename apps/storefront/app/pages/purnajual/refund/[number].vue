<script setup lang="ts">
definePageMeta({ middleware: 'customer', key: route => route.path })
const path = '/api/v1/refunds/' + encodeURIComponent(String(useRoute().params.number))
const { data, status, refresh } = await useFetch<StoreRead<'/refunds/{number}'>>(path, { server: false })
const r = computed(() => data.value?.data)
</script>

<template>
  <section class="stack">
    <NuxtLink to="/purnajual">← Refund & Retur</NuxtLink>
    <h1>Detail refund</h1>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="r">
        <section class="panel stack">
          <h2>{{ r.number }}</h2>
          <p>{{ r.status }} · {{ r.reason_code }}</p>
          <p>Diajukan {{ rupiah(r.requested_amount) }}</p>
          <p v-if="r.approved_amount != null">
            Disetujui {{ rupiah(r.approved_amount) }}
          </p>
          <p v-if="r.completed_at">
            Selesai {{ r.completed_at }}
          </p>
          <NuxtLink :to="'/pesanan/' + r.order_number">{{ r.order_number }}</NuxtLink>
        </section>
        <section class="panel stack">
          <h2>Bukti pendukung</h2>
          <div
            v-for="f in r.evidence"
            :key="f.id"
          >
            <span>{{ f.status }}</span>
            <PrivateDownload
              v-if="f.status === 'READY'"
              :path="'/files/' + f.id + '/download'"
              label="Unduh bukti"
            />
          </div>
          <PrivateEvidence
            v-if="['REQUESTED', 'UNDER_REVIEW'].includes(r.status)"
            owner="refund"
            :number="r.number"
            collection="refund_evidence"
            @uploaded="refresh()"
            @ready="refresh()"
          />
        </section>
      </template>
    </StoreResult>
  </section>
</template>
