<script setup lang="ts">
import type { BreadcrumbItem, DropdownMenuItem } from '@nuxt/ui'
import { adminNavigation as navigation, type NavEntry, type NavGroup } from '~/composables/useAdminNavigation'

const route = useRoute()
const { active, isGroupActive } = useAdminNavigation()
const { user, can, logout } = useAdminAuth()
const toast = useToast()
const adminNavigation = computed(() => navigation.reduce<NavEntry[]>((items, entry) => {
  if (entry.type === 'link') {
    if (can(entry.permission)) items.push(entry)
  } else {
    const children = entry.children.filter(child => can(child.permission))
    if (children.length) items.push({ ...entry, children })
  }
  return items
}, []))
const { data: health } = useApiHealth()

const collapsed = ref(false)
const openGroups = ref<Record<string, boolean>>({})

watch(() => route.path, () => {
  for (const entry of adminNavigation.value) {
    if (entry.type === 'group' && isGroupActive(entry)) {
      openGroups.value[entry.label] = true
    }
  }
}, { immediate: true })

const breadcrumb = computed<BreadcrumbItem[]>(() => [
  ...(active.value.group ? [{ label: active.value.group }] : []),
  { label: active.value.label, class: 'text-primary font-medium' }
])

const displayName = computed(() => user.value?.display_name ?? 'Staff')
const initials = computed(() => displayName.value.split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase())

const userMenu = computed<DropdownMenuItem[][]>(() => [
  [{ type: 'label', label: displayName.value, description: user.value?.email ?? 'Belum masuk' }],
  [
    ...(can('admin.profile.view') ? [{ label: 'Setting', icon: 'i-lucide-settings', to: '/settings' }] : []),
    ...(can('operator.logs.view') ? [{ label: 'Log Viewer', icon: 'i-lucide-scroll-text', to: '/log-viewerx', external: true }] : []),
    ...(can('operator.database.open') ? [{ label: 'OmniDB', icon: 'i-lucide-database', to: '/db/', external: true }] : [])
  ],
  [{ label: 'Keluar', icon: 'i-lucide-log-out', color: 'error', onSelect: async () => {
    try {
      await logout()
    } catch (error) {
      toast.add({ title: authErrorMessage(error), color: 'error' })
    }
  } }]
])

const groupItems = (group: NavGroup): DropdownMenuItem[] => group.children.map(child => ({
  label: child.label,
  icon: child.icon,
  to: child.to,
  disabled: !child.to
}))
</script>

<template>
  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      v-model:collapsed="collapsed"
      collapsible
      :default-size="16"
      :collapsed-size="4.5"
      :ui="{
        root: 'snow-sidebar border-e border-default',
        content: 'snow-sidebar',
        header: 'h-auto border-b border-default py-4',
        body: 'gap-0 px-3 py-3',
        footer: 'border-t border-default'
      }"
    >
      <template #header="{ collapsed: isCollapsed }">
        <NuxtLink
          to="/"
          aria-label="Snowego Admin"
          class="rounded-md outline-primary/25 focus-visible:outline-3"
          :class="isCollapsed ? 'mx-auto' : 'px-2'"
        >
          <AppLogo :collapsed="isCollapsed" />
        </NuxtLink>
      </template>

      <template #default="{ collapsed: isCollapsed }">
        <nav
          class="flex flex-col gap-0.5"
          aria-label="Menu admin"
        >
          <template
            v-for="entry in adminNavigation"
            :key="entry.label"
          >
            <UTooltip
              v-if="entry.type === 'link'"
              :text="entry.label"
              :disabled="!isCollapsed"
              :content="{ side: 'right' }"
            >
              <NuxtLink
                :to="entry.to"
                class="nav-link"
                :class="{ 'justify-center': isCollapsed }"
                exact-active-class="nav-link-active"
                :aria-label="isCollapsed ? entry.label : undefined"
              >
                <UIcon
                  :name="entry.icon"
                  class="size-5 shrink-0"
                />
                <span v-if="!isCollapsed">{{ entry.label }}</span>
              </NuxtLink>
            </UTooltip>

            <UDropdownMenu
              v-else-if="isCollapsed"
              :items="groupItems(entry)"
              :content="{ side: 'right', align: 'start' }"
            >
              <button
                type="button"
                class="nav-link justify-center"
                :class="{ 'nav-link-active': isGroupActive(entry) }"
                :aria-label="entry.label"
              >
                <UIcon
                  :name="entry.icon"
                  class="size-5 shrink-0"
                />
              </button>
            </UDropdownMenu>

            <UCollapsible
              v-else
              :open="openGroups[entry.label] ?? false"
              @update:open="(value: boolean) => (openGroups[entry.label] = value)"
            >
              <button
                type="button"
                class="nav-link group w-full"
                :class="{ 'nav-group-active': isGroupActive(entry) }"
              >
                <UIcon
                  :name="entry.icon"
                  class="size-5 shrink-0"
                />
                <span class="truncate">{{ entry.label }}</span>
                <UIcon
                  name="i-lucide-chevron-down"
                  class="ms-auto size-4 shrink-0 text-dimmed transition-transform group-data-[state=open]:rotate-180"
                />
              </button>
              <template #content>
                <div class="ms-5 mt-0.5 mb-1 flex flex-col gap-0.5 border-s border-default ps-2 dark:border-(--ui-text-dimmed)">
                  <template
                    v-for="child in entry.children"
                    :key="child.label"
                  >
                    <NuxtLink
                      v-if="child.to"
                      :to="child.to"
                      class="nav-link nav-child"
                      active-class="nav-link-active"
                    >
                      <UIcon
                        :name="child.icon"
                        class="size-4 shrink-0"
                      />
                      <span class="truncate">{{ child.label }}</span>
                    </NuxtLink>
                    <span
                      v-else
                      class="nav-link nav-child nav-link-disabled"
                      aria-disabled="true"
                      title="Segera hadir"
                    >
                      <UIcon
                        :name="child.icon"
                        class="size-4 shrink-0"
                      />
                      <span class="truncate">{{ child.label }}</span>
                    </span>
                  </template>
                </div>
              </template>
            </UCollapsible>
          </template>
        </nav>
      </template>

      <template #footer="{ collapsed: isCollapsed }">
        <UDropdownMenu
          :items="userMenu"
          :content="{ align: 'center', collisionPadding: 12 }"
          :ui="{ content: isCollapsed ? 'w-56' : 'w-(--reka-dropdown-menu-trigger-width)' }"
        >
          <UButton
            :avatar="{ text: initials, ui: { root: 'bg-primary text-inverted' } }"
            :label="isCollapsed ? undefined : displayName"
            :trailing-icon="isCollapsed ? undefined : 'i-lucide-chevrons-up-down'"
            color="neutral"
            variant="ghost"
            block
            :square="isCollapsed"
            class="data-[state=open]:bg-elevated"
            :ui="{ trailingIcon: 'text-dimmed' }"
          />
        </UDropdownMenu>
      </template>
    </UDashboardSidebar>

    <UDashboardPanel id="main">
      <template #header>
        <UDashboardNavbar :ui="{ root: 'h-auto min-h-(--ui-header-height) py-2' }">
          <template #leading>
            <UDashboardSidebarCollapse class="hidden lg:inline-flex" />
          </template>

          <template #title>
            <div class="flex min-w-0 flex-col">
              <span class="truncate text-[0.9375rem] font-bold text-highlighted">{{ active.label }}</span>
              <UBreadcrumb
                :items="breadcrumb"
                :ui="{ link: 'text-xs' }"
              />
            </div>
          </template>

          <template #right>
            <UBadge
              :color="health?.data.status === 'ok' ? 'success' : 'error'"
              variant="subtle"
              :icon="health?.data.status === 'ok' ? 'i-lucide-circle-check' : 'i-lucide-circle-alert'"
              :label="health?.data.status === 'ok' ? 'API sehat' : 'API tidak terjangkau'"
              class="hidden sm:inline-flex"
            />
            <UColorModeButton />
            <UPopover :content="{ side: 'bottom', align: 'end' }">
              <UButton
                icon="i-lucide-bell"
                color="neutral"
                variant="ghost"
                aria-label="Notifikasi"
              />
              <template #content>
                <div class="w-72">
                  <p class="border-b border-default px-4 py-3 text-sm font-semibold text-highlighted">
                    Notifikasi
                  </p>
                  <UEmpty
                    icon="i-lucide-bell-off"
                    title="Belum ada notifikasi"
                    variant="naked"
                    size="sm"
                  />
                </div>
              </template>
            </UPopover>
          </template>
        </UDashboardNavbar>
      </template>

      <template #body>
        <slot />
      </template>
    </UDashboardPanel>
  </UDashboardGroup>
</template>
