<script setup lang="ts">
definePageMeta({ middleware: 'customer', key: route => route.path })
const base = '/request-orders/' + encodeURIComponent(String(useRoute().params.number))
const { data, status, refresh } = await useFetch<StoreRead<'/request-orders/{number}'>>('/api/v1' + base, { server: false })
const r = computed(() => data.value?.data)
const { busy, problem, notice, run } = useCustomerAction()
const write = useRetryableCustomerWrite()
const message = ref('')
const address = ref('')
const note = ref('')
const reason = ref('')
const canDiscuss = computed(() => ['SUBMITTED', 'UNDER_REVIEW', 'NEED_INFORMATION', 'QUOTED', 'REVISION_REQUESTED'].includes(r.value?.status ?? ''))
async function send() {
  await customerWrite(base + '/messages', 'POST', { body: message.value })
  message.value = ''
  await refresh()
}
async function decide(number: string, decision: 'ACCEPT' | 'REJECT' | 'REVISE') {
  if (decision === 'ACCEPT' && !window.confirm('Setujui harga dan buat pesanan dari penawaran ini?')) return
  await run(async () => {
    const result = await write<{ data: { order_number?: string } }>(base + '/quotations/' + number + '/decisions', {
      decision, ...(note.value ? { note: note.value } : {}), ...(decision === 'ACCEPT' ? { address_id: address.value } : {})
    })
    if (result.data.order_number) await navigateTo('/pesanan/' + result.data.order_number)
    else await refresh()
  })
}
async function cancel() {
  if (!window.confirm('Batalkan pengajuan ini?')) return
  await run(async () => {
    await customerWrite(base + '/cancellations', 'POST', { reason_code: reason.value })
    await refresh()
  })
}
</script>

<template>
  <section class="stack">
    <NuxtLink to="/request-order">← Request Order</NuxtLink>
    <StoreResult
      :status="status"
      @retry="refresh()"
    >
      <template v-if="r">
        <h1>{{ r.title }}</h1>
        <div class="panel stack">
          <p>{{ r.number }} · {{ r.status }}</p>
          <p>{{ r.description }}</p>
          <p
            v-for="i in r.items"
            :key="i.id"
          >
            {{ i.qty }} × {{ i.name }} {{ i.options }}
          </p>
        </div>
        <article
          v-for="q in r.quotations"
          :key="q.number"
          class="panel stack"
        >
          <h2>Penawaran {{ q.number }}</h2>
          <p>{{ q.status }} · Berlaku sampai {{ q.valid_until }}</p>
          <div
            v-for="i in q.items"
            :key="i.request_item_id"
          >
            {{ i.qty }} × {{ i.label }} · {{ rupiah(i.unit_amount) }} / unit
          </div>
          <strong>Total {{ rupiah(q.total_amount) }}</strong>
          <p v-if="q.deposit_amount != null">
            DP {{ rupiah(q.deposit_amount) }}
          </p>
          <p v-if="q.shipping_estimate_amount != null">
            Estimasi ongkir {{ rupiah(q.shipping_estimate_amount) }}
          </p>
          <p class="prose-copy">
            {{ q.terms }}
          </p>
          <template v-if="r.status === 'QUOTED' && q.status === 'SENT'">
            <AddressSelect v-model="address" />
            <label>Catatan tanggapan<textarea v-model="note" />
            </label>
            <div class="actions">
              <button
                :disabled="busy || !address"
                @click="decide(q.number, 'ACCEPT')"
              >
                Setujui & pesan
              </button>
              <button
                class="subtle"
                :disabled="busy"
                @click="decide(q.number, 'REVISE')"
              >
                Minta revisi
              </button>
              <button
                class="subtle"
                :disabled="busy"
                @click="decide(q.number, 'REJECT')"
              >
                Tolak
              </button>
            </div>
          </template>
        </article>
        <div class="panel stack">
          <h2>Percakapan</h2>
          <p
            v-for="(m, i) in r.messages"
            :key="i"
          >
            <strong>{{ m.author_label }}</strong> · {{ m.created_at }}<br>{{ m.body }}
          </p>
          <form
            v-if="canDiscuss"
            @submit.prevent="run(send, 'Pesan terkirim.')"
          >
            <label>Pesan<textarea
              v-model="message"
              maxlength="5000"
              required
            />
            </label>
            <button :disabled="busy">
              Kirim pesan
            </button>
          </form>
        </div>
        <div class="panel stack">
          <h2>Lampiran</h2>
          <p
            v-for="f in r.files"
            :key="f.id"
          >
            {{ f.mime }} · {{ f.status }} <PrivateDownload
              v-if="f.status === 'READY'"
              :path="'/files/' + f.id + '/download'"
              label="Unduh lampiran"
            />
          </p>
          <PrivateEvidence
            v-if="canDiscuss"
            owner="request_order"
            :number="r.number"
            collection="request_attachment"
            @uploaded="refresh()"
            @ready="refresh()"
          />
        </div>
        <details
          v-if="canDiscuss || r.status === 'EXPIRED'"
          class="panel"
        >
          <summary>Batalkan pengajuan</summary>
          <form @submit.prevent="cancel">
            <ReasonChoice
              v-model="reason"
              domain="request_orders"
            />
            <button :disabled="busy || !reason">
              Konfirmasi pembatalan
            </button>
          </form>
        </details>
      </template>
    </StoreResult>
    <p
      v-if="problem"
      role="alert"
    >
      {{ problem }}
    </p>
    <p
      v-if="notice"
      role="status"
    >
      {{ notice }}
    </p>
  </section>
</template>
