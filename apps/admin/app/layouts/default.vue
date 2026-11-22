<template>
  <div class="min-h-screen">
    <UHeader title="Snowego Admin">
      <template #left>
        <NuxtLink
          to="/"
          class="rounded-md p-1 -ms-1 outline-primary/25 focus-visible:outline-3"
        >
          <AppLogo class="h-6 w-auto shrink-0" />
        </NuxtLink>
      </template>

      <template #right>
        <UColorModeButton />
      </template>
    </UHeader>

    <UMain>
      <slot />
    </UMain>
  </div>
</template>
