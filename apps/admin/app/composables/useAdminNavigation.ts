export interface NavChild {
  permission?: string
  label: string
  icon: string
  to?: string
}

export interface NavLink {
  permission: string
  type: 'link'
  label: string
  icon: string
  to: string
}

export interface NavGroup {
  type: 'group'
  label: string
  icon: string
  children: NavChild[]
}

export type NavEntry = NavLink | NavGroup

// Menu groups follow docs/07-ADMIN-MENU-MATRIX.md; entries without `to` are not built yet.
export const adminNavigation: NavEntry[] = [
  { type: 'link', label: 'Dashboard', icon: 'i-lucide-layout-grid', to: '/', permission: 'reporting.dashboard.view' },
  {
    type: 'group',
    label: 'Commerce',
    icon: 'i-lucide-shopping-bag',
    children: [
      { label: 'Pesanan & RTS', icon: 'i-lucide-package' },
      { label: 'Kampanye Preorder', icon: 'i-lucide-calendar-clock' },
      { label: 'Batch Pengadaan', icon: 'i-lucide-boxes' },
      { label: 'Request Order', icon: 'i-lucide-message-square-quote' },
      { label: 'Invoice', icon: 'i-lucide-file-text' },
      { label: 'Return', icon: 'i-lucide-undo-2' }
    ]
  },
  {
    type: 'group',
    label: 'Finance',
    icon: 'i-lucide-wallet',
    children: [
      { label: 'Pembayaran', icon: 'i-lucide-credit-card' },
      { label: 'Rekonsiliasi', icon: 'i-lucide-clipboard-check' },
      { label: 'Refund', icon: 'i-lucide-hand-coins' },
      { label: 'Rekening Tujuan', icon: 'i-lucide-landmark' },
      { label: 'Laporan Transaksi', icon: 'i-lucide-chart-column' }
    ]
  },
  {
    type: 'group',
    label: 'Pengiriman',
    icon: 'i-lucide-truck',
    children: [
      { label: 'Paket & Dispatch', icon: 'i-lucide-package-check' },
      { label: 'Tracking', icon: 'i-lucide-map-pin' },
      { label: 'Kurir & Tarif', icon: 'i-lucide-route' }
    ]
  },
  {
    type: 'group',
    label: 'Katalog',
    icon: 'i-lucide-tags',
    children: [
      { label: 'Produk', icon: 'i-lucide-shirt' },
      { label: 'Varian & SKU', icon: 'i-lucide-layers' },
      { label: 'Kategori & Brand', icon: 'i-lucide-folder-tree' },
      { label: 'Koleksi & Tag', icon: 'i-lucide-bookmark' },
      { label: 'Atribut', icon: 'i-lucide-sliders-horizontal' },
      { label: 'Media', icon: 'i-lucide-image' }
    ]
  },
  {
    type: 'group',
    label: 'Inventori',
    icon: 'i-lucide-warehouse',
    children: [
      { label: 'Gudang', icon: 'i-lucide-building-2' },
      { label: 'Stok', icon: 'i-lucide-package-search' },
      { label: 'Mutasi', icon: 'i-lucide-arrow-left-right' },
      { label: 'Reservasi', icon: 'i-lucide-lock' },
      { label: 'Penerimaan & Koreksi', icon: 'i-lucide-clipboard-pen' }
    ]
  },
  {
    type: 'group',
    label: 'Pelanggan',
    icon: 'i-lucide-users',
    children: [{ label: 'Daftar Pelanggan', icon: 'i-lucide-contact-round' }]
  },
  {
    type: 'group',
    label: 'Konten & Promo',
    icon: 'i-lucide-megaphone',
    children: [
      { label: 'Homepage & Banner', icon: 'i-lucide-panels-top-left' },
      { label: 'Halaman & FAQ', icon: 'i-lucide-book-open' },
      { label: 'Promo & Kupon', icon: 'i-lucide-ticket-percent' }
    ]
  },
  {
    type: 'group',
    label: 'Master Data',
    icon: 'i-lucide-database',
    children: [
      { label: 'Wilayah', icon: 'i-lucide-map' },
      { label: 'Unit, Pajak & Alasan', icon: 'i-lucide-list-checks' }
    ]
  },
  {
    type: 'group',
    label: 'Administrasi',
    icon: 'i-lucide-shield-check',
    children: [
      { label: 'Pengguna', icon: 'i-lucide-user-cog', to: '/staff', permission: 'admin.staff.view' },
      { label: 'Role & Izin', icon: 'i-lucide-key-round', to: '/roles', permission: 'admin.roles.view' },
      { label: 'Audit Log', icon: 'i-lucide-scroll-text' },
      { label: 'Pengaturan', icon: 'i-lucide-settings', to: '/settings', permission: 'admin.profile.view' },
      { label: 'Integrasi', icon: 'i-lucide-plug' }
    ]
  }
]

function matches(path: string, to?: string) {
  if (!to) {
    return false
  }
  return to === '/' ? path === '/' : path === to || path.startsWith(`${to}/`)
}

export function useAdminNavigation() {
  const route = useRoute()

  const active = computed(() => {
    for (const entry of adminNavigation) {
      if (entry.type === 'link' && matches(route.path, entry.to)) {
        return { label: entry.label }
      }
      if (entry.type === 'group') {
        const child = entry.children.find(item => matches(route.path, item.to))
        if (child) {
          return { group: entry.label, label: child.label }
        }
      }
    }
    return { label: 'Dashboard' }
  })

  const isGroupActive = (group: NavGroup) => group.children.some(child => matches(route.path, child.to))

  return { active, isGroupActive }
}
