export default defineEventHandler(async (event) => {
  const { meta } = await sitemapProducts(event)
  return sitemapXml(event, Array.from({ length: meta.last_page }, (_, i) => '/sitemaps/' + (i + 1) + '.xml'), true)
})
