import type { H3Event } from 'h3'

export async function sitemapProducts(event: H3Event, page = 1) {
  const { apiInternalOrigin } = useRuntimeConfig(event)
  return $fetch<{ data: string[], meta: { last_page: number } }>(apiInternalOrigin + '/api/v1/sitemap/products', { query: { page } })
}
export function sitemapXml(event: H3Event, paths: string[], index = false) {
  const root = index ? 'sitemapindex' : 'urlset'
  const item = index ? 'sitemap' : 'url'
  const origin = new URL(useRuntimeConfig(event).public.siteUrl).origin
  setResponseHeader(event, 'content-type', 'application/xml; charset=utf-8')
  return '<?xml version="1.0" encoding="UTF-8"?><' + root + ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' + paths.map((path) => {
    const url = new URL(path, origin).href.replaceAll('&', '&amp;').replaceAll('<', '&lt;')
    return '<' + item + '><loc>' + url + '</loc></' + item + '>'
  }).join('') + '</' + root + '>'
}
