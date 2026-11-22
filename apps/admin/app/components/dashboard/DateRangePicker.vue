<script setup lang="ts">
import type { CalendarDate } from '@internationalized/date'
import { getLocalTimeZone, parseDate, today } from '@internationalized/date'
import { MAX_RANGE_DAYS, type DateRange } from '~/composables/useDashboard'

const model = defineModel<DateRange>({ required: true })

const open = ref(false)
const pending = shallowRef<{ start: CalendarDate, end: CalendarDate } | null>(null)

watch(model, (value) => {
  pending.value = { start: parseDate(value.from), end: parseDate(value.to) }
}, { immediate: true })

const formatter = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
const label = (date: CalendarDate) => formatter.format(date.toDate(getLocalTimeZone()))

const presets = [
  { label: '7 hari terakhir', days: 7 },
  { label: '14 hari terakhir', days: 14 },
  { label: '30 hari terakhir', days: 30 },
  { label: '90 hari terakhir', days: 90 }
]

function applyPreset(days: number) {
  const end = today(getLocalTimeZone())
  pending.value = { start: end.subtract({ days: days - 1 }), end }
  apply()
}

const tooLong = computed(() => !!pending.value?.start && !!pending.value.end
  && pending.value.end.compare(pending.value.start) >= MAX_RANGE_DAYS)

function apply() {
  if (!pending.value?.start || !pending.value.end || tooLong.value) {
    return
  }
  model.value = { from: pending.value.start.toString(), to: pending.value.end.toString() }
  open.value = false
}

// UCalendar's range model is a union of date classes; bridge it without fighting its nominal types.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const calendarModel = computed<any>(() => pending.value)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function onCalendarUpdate(value: any) {
  pending.value = value
}
</script>

<template>
  <UPopover
    v-model:open="open"
    :ui="{ content: 'p-0' }"
  >
    <UButton
      color="neutral"
      variant="outline"
      size="sm"
      icon="i-lucide-calendar-range"
      trailing-icon="i-lucide-chevron-down"
      :label="`${label(parseDate(model.from))} - ${label(parseDate(model.to))}`"
    />

    <template #content>
      <div class="flex flex-col sm:flex-row">
        <div class="flex shrink-0 flex-col gap-1 border-b border-default p-2 sm:w-40 sm:border-e sm:border-b-0">
          <UButton
            v-for="preset in presets"
            :key="preset.days"
            :label="preset.label"
            color="neutral"
            variant="ghost"
            size="xs"
            @click="applyPreset(preset.days)"
          />
        </div>
        <div class="p-3">
          <UCalendar
            :model-value="calendarModel"
            range
            :number-of-months="2"
            :max-value="today(getLocalTimeZone())"
            @update:model-value="onCalendarUpdate"
          />
          <div class="mt-2 flex items-center justify-end gap-2">
            <span
              v-if="tooLong"
              class="me-auto text-xs text-error"
            >Maksimal {{ MAX_RANGE_DAYS }} hari</span>
            <UButton
              label="Batal"
              color="neutral"
              variant="ghost"
              size="xs"
              @click="open = false"
            />
            <UButton
              label="Terapkan"
              size="xs"
              :disabled="!pending?.start || !pending?.end || tooLong"
              @click="apply"
            />
          </div>
        </div>
      </div>
    </template>
  </UPopover>
</template>
