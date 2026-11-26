<script setup lang="ts">
definePageMeta({ middleware: 'customer', key: route => route.path })
const base = '/orders/' + encodeURIComponent(String(useRoute().params.number))
const { data, status, refresh } = await useFetch<StoreRead<'/orders/{number}'>>('/api/v1' + base, { server: false })
const o = computed(() => data.value?.data)
const { busy, problem, run } = useCustomerAction()
const reason = ref('')
async function act(path: string, body: Record<string, unknown>, question: string) {
  if (!window.confirm(question)) return
  await run(async () => {
    await customerWrite(base + path, 'POST', body)
    await refresh()
  })
}
</script>

<template>
  <section class="stack">
    <NuxtLink to="/pesanan">← Pesanan saya</NuxtLink>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="o">
        <h1>Detail pesanan</h1>
        <div class="panel stack">
          <h2>{{ o.number }}</h2>
          <p>{{ o.method }} · {{ o.status }} · {{ o.payment_status }} · {{ o.fulfillment_status }}</p>
          <div
            v-for="(i, n) in o.items"
            :key="i.id ?? n"
            class="list-row"
          >
            <span>{{ i.qty }} × {{ i.name }} {{ i.variant }}</span>
            <span>{{ i.unit_amount != null ? rupiah(i.unit_amount) : '' }}</span>
          </div>
          <strong>Total {{ rupiah(o.total_amount) }}</strong>
          <p v-if="o.address">
            {{ o.address.recipient }} · {{ o.address.line1 }} · {{ o.address.regions.district.name }} {{ o.address.postal_code }}
          </p>
        </div>
        <div class="panel stack">
          <h2>Tagihan & pengiriman</h2>
          <NuxtLink
            v-for="n in o.invoice_numbers"
            :key="n"
            :to="'/invoice/' + n"
          >Invoice {{ n }} →</NuxtLink>
          <NuxtLink
            v-for="n in o.payment_numbers"
            :key="n"
            :to="'/pembayaran/' + n"
          >Pembayaran {{ n }} →</NuxtLink>
          <NuxtLink
            v-for="n in o.shipment_numbers"
            :key="n"
            :to="'/pengiriman/' + n"
          >Lacak paket {{ n }} →</NuxtLink>
          <p v-if="o.checkout_shipping">
            {{ o.checkout_shipping.carrier_label }} · {{ o.checkout_shipping.service_code }} · {{ rupiah(o.checkout_shipping.amount) }}
          </p>
        </div>
        <article
          v-for="c in o.charge_consents"
          :key="c.id"
          class="panel stack"
        >
          <h2>Biaya tambahan · {{ rupiah(c.amount) }}</h2>
          <p>{{ c.status }} · {{ c.expires_at }}</p>
          <p
            v-for="(l, i) in c.lines"
            :key="i"
          >
            {{ l.description }} · {{ rupiah(l.amount) }}
          </p>
          <div
            v-if="c.status === 'PROPOSED'"
            class="actions"
          >
            <button
              :disabled="busy"
              @click="act('/charge-consents/' + c.id + '/decisions', { decision: 'ACCEPT' }, 'Setujui biaya tambahan ini?')"
            >
              Setujui biaya
            </button>
            <button
              class="subtle"
              :disabled="busy"
              @click="act('/charge-consents/' + c.id + '/decisions', { decision: 'REJECT' }, 'Tolak biaya tambahan?')"
            >
              Tolak
            </button>
          </div>
        </article>
        <div class="panel stack">
          <h2>Riwayat</h2>
          <p
            v-for="(h, i) in o.history"
            :key="i"
          >
            {{ h.occurred_at }} · {{ h.to_status }}
          </p>
        </div>
        <details
          v-if="o.status === 'PENDING_PAYMENT' && o.payment_status === 'UNPAID'"
          class="panel"
        >
          <summary>Batalkan pesanan</summary>
          <form @submit.prevent="act('/cancellations', { reason_code: reason }, 'Batalkan pesanan ini?')">
            <ReasonChoice
              v-model="reason"
              domain="orders"
            />
            <button :disabled="busy || !reason">
              Konfirmasi pembatalan
            </button>
          </form>
        </details>
        <button
          v-if="o.status === 'CONFIRMED' && o.fulfillment_status === 'DELIVERED'"
          :disabled="busy"
          @click="act('/completions', {}, 'Konfirmasi pesanan selesai?')"
        >
          Selesaikan pesanan
        </button>
        <RefundForm
          v-if="(o.refundable_amount ?? 0) > 0"
          :order="o"
        />
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
