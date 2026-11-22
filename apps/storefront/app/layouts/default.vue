<template>
  <div class="min-h-screen bg-white text-slate-900">
    <header class="border-b border-slate-200">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
        <NuxtLink
          to="/"
          class="text-lg font-semibold tracking-tight"
        >
          Snowe <span class="text-brand-600">GO</span>
        </NuxtLink>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-10">
      <slot />
    </main>
  </div>
</template>
