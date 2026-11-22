<script setup lang="ts">
const { data, status, refresh } = useApiHealth()
</script>

<template>
  <UContainer class="py-10">
    <UPageHeader
      title="Snowego Admin"
      description="Kerangka panel staff. Login MFA, RBAC dan menu operasional dibangun pada E02 dan E12."
    />

    <UCard class="mt-6 max-w-md">
      <template #header>
        <p class="font-medium">
          Status API
        </p>
      </template>

      <div class="flex items-center justify-between gap-4">
        <UBadge
          v-if="status === 'pending'"
          color="neutral"
          variant="subtle"
          label="Memeriksa…"
        />
        <UBadge
          v-else-if="data?.data.status === 'ok'"
          color="success"
          variant="subtle"
          label="API sehat"
        />
        <UBadge
          v-else
          color="error"
          variant="subtle"
          label="API tidak terjangkau"
        />

        <UButton
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="ghost"
          aria-label="Periksa ulang"
          :loading="status === 'pending'"
          @click="refresh()"
        />
      </div>
    </UCard>
  </UContainer>
</template>
