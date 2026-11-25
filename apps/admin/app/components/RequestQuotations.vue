<script setup lang="ts">
import type { RequestCase } from '~/composables/useRequestOrders'
import type { InventoryAction } from '~/composables/useInventory'
import { inventoryTime } from '~/composables/useInventory'

type Quote = RequestCase['quotations'][number]
const props = defineProps<{ record: RequestCase }>()
const emit = defineEmits<{ saved: [] }>()
const { can } = useAdminAuth()
const allowed = (action: string) => can('request_orders.quotation.manage') && can(`request_orders.quotation.${action}`)
const editable = computed(() => ['UNDER_REVIEW', 'QUOTED', 'REVISION_REQUESTED'].includes(props.record.status))
const base = computed(() => `${caseApi}/${encodeURIComponent(props.record.number)}/quotations`)
const action = ref<InventoryAction>()
const editing = ref<Quote | 'new'>()
const withdrawing = ref<Quote>()
const reason = ref('')
const until = ref('')
const term = ref('FULL')
const terms = ref('')
const deposit = ref<number>()
const shipping = ref<number>()
type FormLine = { selected: boolean, request_item_id: string, label: string, options: string, qty: number, unit_amount: number, weight_g?: number, foreign_currency: string, foreign_amount: string, exchange_rate: string }
const lines = ref<FormLine[]>([])
const total = computed(() => lines.value.filter(i => i.selected).reduce((sum, i) => sum + i.qty * i.unit_amount, 0))
const money = (n: number) => `Rp ${n.toLocaleString('id-ID')}`
function edit(q?: Quote) {
  withdrawing.value = undefined
  editing.value = q ?? 'new'
  const date = q ? new Date(q.valid_until) : null
  until.value = date ? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) : ''
  term.value = q?.term ?? 'FULL'
  terms.value = q?.terms ?? ''
  deposit.value = q?.deposit_amount ?? undefined
  shipping.value = q?.shipping_estimate_amount ?? undefined
  lines.value = props.record.items.map((item) => {
    const line = q?.items.find(i => i.request_item_id === item.id)
    return { selected: !q || !!line, request_item_id: item.id, label: line?.label ?? item.name, qty: line?.qty ?? item.qty,
      options: (line ? line.options : item.options) ?? '', unit_amount: line?.unit_amount ?? 0, weight_g: line?.weight_g ?? undefined,
      foreign_currency: line?.foreign_currency ?? '', foreign_amount: line?.foreign_amount ?? '', exchange_rate: line?.exchange_rate ?? '' }
  })
}
function save() {
  const q = editing.value
  if (!q || !total.value || !allowed(q === 'new' ? 'create' : 'update')) return
  action.value = { url: q === 'new' ? base.value : `${base.value}/${q.number}`, method: q === 'new' ? 'POST' : 'PATCH',
    label: `Simpan draf senilai ${money(total.value)}?`, body: { ...(q === 'new' ? {} : { revision: q.revision }), valid_until: new Date(until.value).toISOString(), term: term.value, terms: terms.value,
      deposit_amount: term.value === 'DEPOSIT' ? Number(deposit.value) : null, shipping_estimate_amount: shipping.value === undefined || String(shipping.value) === '' ? null : Number(shipping.value),
      items: lines.value.filter(i => i.selected).map(({ selected: _selected, ...i }) =>
        Object.fromEntries(Object.entries(i).filter(([, v]) => v !== undefined && v !== ''))) } }
}
function prepare(q: Quote, send: boolean) {
  if (!allowed(send ? 'dispatch' : 'withdraw')) return
  action.value = { url: `${base.value}/${q.number}/${send ? 'dispatches' : 'withdrawals'}`, method: 'POST',
    body: send ? { revision: q.revision } : { reason_code: reason.value }, label: send ? `Kirim versi ${q.version} senilai ${money(q.total_amount)} dan bekukan isinya?` : `Tarik penawaran ${q.number}?` }
}
function saved() {
  editing.value = withdrawing.value = action.value = undefined
  reason.value = ''
  emit('saved')
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-semibold">
          Penawaran harga
        </h2>
        <UButton
          v-if="editable && allowed('create') && !record.quotations.some(q => q.status === 'DRAFT')"
          label="Buat penawaran"
          @click="edit()"
        />
      </div>
    </template>
    <p v-if="!record.quotations.length">
      Belum ada penawaran.
    </p>
    <article
      v-for="q in record.quotations"
      :key="q.number"
      class="space-y-2 border-b border-default py-3"
    >
      <h3 class="font-semibold">
        {{ q.number }} · Versi {{ q.version }} <UBadge variant="subtle">
          {{ q.status }}
        </UBadge>
      </h3>
      <p>{{ money(q.total_amount) }} · {{ q.deposit_amount ? `DP ${money(q.deposit_amount)}` : 'Bayar penuh' }} · Berlaku sampai {{ inventoryTime(q.valid_until) }}</p>
      <p v-if="q.shipping_estimate_amount != null">
        Estimasi ongkir (belum ditagih): {{ money(q.shipping_estimate_amount) }}
      </p>
      <p class="whitespace-pre-wrap">
        {{ q.terms }}
      </p>
      <ul>
        <li
          v-for="line in q.items"
          :key="line.request_item_id"
        >
          {{ line.label }} {{ line.options }} · {{ line.qty }} × {{ money(line.unit_amount) }}
        </li>
      </ul>
      <div class="flex flex-wrap gap-2">
        <UButton
          v-if="editable && q.status === 'DRAFT' && allowed('update')"
          label="Edit draf"
          variant="outline"
          @click="edit(q)"
        />
        <UButton
          v-if="editable && q.status === 'DRAFT' && allowed('dispatch')"
          label="Kirim penawaran"
          @click="prepare(q, true)"
        />
        <UButton
          v-if="['DRAFT', 'SENT'].includes(q.status) && allowed('withdraw')"
          label="Tarik penawaran"
          color="warning"
          variant="outline"
          @click="withdrawing = q; editing = undefined; reason = ''"
        />
      </div>
    </article>
    <form
      v-if="withdrawing"
      class="mt-4 space-y-3"
      @submit.prevent="prepare(withdrawing, false)"
    >
      <UFormField :label="`Kode alasan aktif untuk ${withdrawing.number}`">
        <UInput
          v-model="reason"
          required
          maxlength="48"
        />
      </UFormField>
      <UButton
        type="submit"
        label="Konfirmasi penarikan"
      />
      <UButton
        label="Batal"
        variant="ghost"
        @click="withdrawing = undefined"
      />
    </form>
    <form
      v-if="editing"
      class="mt-5 space-y-4"
      @submit.prevent="save"
    >
      <h3 class="font-semibold">
        {{ editing === 'new' ? 'Draf baru' : `Edit ${editing.number}` }}
      </h3>
      <div class="grid gap-3 sm:grid-cols-2">
        <UFormField label="Berlaku sampai (waktu lokal)">
          <UInput
            v-model="until"
            type="datetime-local"
            required
          />
        </UFormField>
        <UFormField label="Termin">
          <USelect
            v-model="term"
            :items="['FULL', 'DEPOSIT']"
          />
        </UFormField>
        <UFormField
          v-if="term === 'DEPOSIT'"
          label="DP (Rp)"
        >
          <UInput
            v-model.number="deposit"
            type="number"
            min="1"
            :max="total - 1"
            required
          />
        </UFormField>
        <UFormField label="Estimasi ongkir (Rp, tidak ditagih)">
          <UInput
            v-model.number="shipping"
            type="number"
            min="0"
          />
        </UFormField>
      </div>
      <UFormField label="Syarat penawaran">
        <UTextarea
          v-model="terms"
          required
          maxlength="5000"
          class="w-full"
        />
      </UFormField>
      <fieldset
        v-for="line in lines"
        :key="line.request_item_id"
        class="rounded-lg border border-default p-3"
      >
        <UCheckbox
          v-model="line.selected"
          :label="`Sertakan ${record.items.find(i => i.id === line.request_item_id)?.name}`"
        />
        <div
          v-if="line.selected"
          class="mt-2 grid gap-2 sm:grid-cols-2"
        >
          <UFormField label="Nama barang">
            <UInput
              v-model="line.label"
              required
              maxlength="200"
            />
          </UFormField>
          <UFormField label="Opsi">
            <UInput
              v-model="line.options"
              maxlength="1000"
            />
          </UFormField>
          <UFormField label="Jumlah">
            <UInput
              v-model.number="line.qty"
              type="number"
              min="1"
              max="10000"
              required
            />
          </UFormField>
          <UFormField label="Harga satuan (Rp)">
            <UInput
              v-model.number="line.unit_amount"
              type="number"
              min="0"
              required
            />
          </UFormField>
          <UFormField label="Berat (gram)">
            <UInput
              v-model.number="line.weight_g"
              type="number"
              min="1"
            />
          </UFormField>
          <UFormField label="Mata uang pemasok">
            <UInput
              v-model="line.foreign_currency"
              pattern="[A-Z]{3}"
              maxlength="3"
            />
          </UFormField>
          <UFormField label="Harga dalam mata uang asing">
            <UInput
              v-model="line.foreign_amount"
              inputmode="decimal"
            />
          </UFormField>
          <UFormField label="Kurs referensi">
            <UInput
              v-model="line.exchange_rate"
              inputmode="decimal"
            />
          </UFormField>
        </div>
      </fieldset>
      <p>Total barang: {{ money(total) }}</p>
      <UButton
        type="submit"
        :disabled="total <= 0"
        label="Simpan draf"
      />
      <UButton
        label="Batal"
        variant="ghost"
        @click="editing = undefined"
      />
    </form>
    <ConfirmAdminAction
      v-if="action"
      :action="action"
      @cancel="action = undefined"
      @saved="saved"
    />
  </UCard>
</template>
