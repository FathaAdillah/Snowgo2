import type { paths } from '@snowego/api-types'

type Json200<Operation> = Operation extends { responses: { 200: { content: { 'application/json': infer Result } } } } ? Result : never
export type StoreRead<P extends keyof paths> = Json200<paths[P]['get']>
export type ProductCard = StoreRead<'/products'>['data'][number]
export const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount)

export function useStoreSeo(title: () => string, description: () => string, canonicalPath: () => string) {
  const config = useRuntimeConfig()
  const canonical = computed(() => new URL(canonicalPath(), config.public.siteUrl).href)
  useSeoMeta({ title, description, ogTitle: title, ogDescription: description, ogUrl: canonical })
  useHead(() => ({ link: [{ rel: 'canonical', href: canonical.value }] }))
}
