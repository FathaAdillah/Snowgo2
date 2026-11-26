<script setup lang="ts">
definePageMeta({ middleware: 'customer', key: route => route.path })
const base = '/invoices/' + encodeURIComponent(String(useRoute().params.number))
const { data, status, refresh } = await useFetch<StoreRead<'/invoices/{number}'>>('/api/v1' + base, { server: false })
const { data: banks, status: bankStatus, refresh: retryBanks } = await useFetch<StoreRead<'/bank-accounts'>>('/api/v1/bank-accounts', { server: false })
const i = computed(() => data.value?.data)
const bank = ref('')
const { busy, problem, run } = useCustomerAction()
const write = useRetryableCustomerWrite()
async function pay() {
  if (!i.value) return
  const response = await write<{ data: { number: string } }>('/payments', { invoice_numbers: [i.value.number], bank_account_code: bank.value })
  await navigateTo('/pembayaran/' + response.data.number)
}
</script>

<template>
  <section class="stack">
    <h1>Invoice</h1>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="i">
        <div class="panel stack">
          <h2>{{ i.number }}</h2>
          <NuxtLink :to="'/pesanan/' + i.order_number">{{ i.order_number }}</NuxtLink>
          <p>{{ i.status }} · {{ i.purpose }} · Jatuh tempo {{ i.due_at }}</p>
          <div
            v-for="(line, index) in i.items"
            :key="index"
            class="list-row"
          >
            {{ line.qty }} × {{ line.description }} <strong>{{ rupiah(line.amount) }}</strong>
          </div>
          <h3>Total {{ rupiah(i.total_amount) }}</h3>
          <PrivateDownload
            v-if="i.pdf_ready"
            :path="base + '/pdf'"
            label="Unduh PDF privat"
          />
          <p v-else>
            PDF {{ i.pdf_status }}. <button @click="refresh()">
              Periksa lagi
            </button>
          </p>
        </div>
        <form
          v-if="i.status === 'ISSUED'"
          class="panel stack"
          @submit.prevent="run(pay)"
        >
          <h2>Bayar melalui transfer</h2>
          <StoreResult
            :status="bankStatus"
            :empty="!banks?.data.length"
            empty-label="Belum ada rekening aktif."
            @retry="retryBanks()"
          >
            <label>Rekening tujuan<select
              v-model="bank"
              required
            >
              <option
                value=""
                disabled
              >Pilih bank</option>
              <option
                v-for="b in banks?.data"
                :key="b.code"
                :value="b.code"
              >{{ b.bank_code }} · {{ b.holder_name }} · {{ b.masked_number }}</option>
            </select>
            </label>
            <button :disabled="busy || !bank">
              Dapatkan instruksi transfer
            </button>
          </StoreResult>
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
