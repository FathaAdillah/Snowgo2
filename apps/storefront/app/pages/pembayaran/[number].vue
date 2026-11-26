<script setup lang="ts">
definePageMeta({ middleware: 'customer', key: route => route.path })
const base = '/payments/' + encodeURIComponent(String(useRoute().params.number))
const { data, status, refresh } = await useFetch<StoreRead<'/payments/{number}'>>('/api/v1' + base, { server: false })
const payment = computed(() => data.value?.data)
const proof = ref('')
const { busy, notice, problem, run } = useCustomerAction()
async function submit() {
  await customerWrite(base + '/proofs', 'POST', { file_id: proof.value })
  proof.value = ''
  await refresh()
}
async function cancel() {
  if (!window.confirm('Batalkan instruksi transfer ini?')) return
  await run(async () => {
    await customerWrite(base + '/cancellations', 'POST')
    await refresh()
  })
}
</script>

<template>
  <section class="stack">
    <h1>Pembayaran transfer</h1>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="payment">
        <div class="panel stack">
          <h2>{{ payment.number }}</h2>
          <p>{{ payment.status }} · Batas waktu {{ payment.expires_at }}</p>
          <strong>{{ rupiah(payment.amount) }}</strong>
          <div v-if="payment.account_number">
            <h3>Rekening tujuan</h3>
            <p>{{ payment.bank_code }} · {{ payment.holder_name }}</p>
            <strong>{{ payment.account_number }}</strong>
          </div>
          <NuxtLink
            v-for="n in payment.invoice_numbers"
            :key="n"
            :to="'/invoice/' + n"
          >Invoice {{ n }}</NuxtLink>
        </div>
        <section
          v-if="['UNPAID', 'WAITING_CONFIRMATION'].includes(payment.status)"
          class="panel stack"
        >
          <h2>Bukti transfer</h2>
          <PrivateEvidence
            owner="payment"
            :number="payment.number"
            collection="payment_proof"
            @ready="proof = $event"
          />
          <button
            :disabled="busy || !proof"
            @click="run(submit, 'Bukti dikirim untuk diperiksa finance.')"
          >
            Kirim bukti yang sudah diperiksa
          </button>
        </section>
        <button
          v-if="payment.status === 'UNPAID'"
          class="subtle"
          :disabled="busy"
          @click="cancel"
        >
          Batalkan instruksi
        </button>
        <button
          class="subtle"
          @click="refresh()"
        >
          Perbarui status
        </button>
      </template>
    </StoreResult>
    <p
      v-if="notice"
      role="status"
    >
      {{ notice }}
    </p>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
  </section>
</template>
