export function useCaseAction(base: string, saved: () => Promise<void>) {
  const action = ref<{ url: string, method: 'POST' | 'PUT', body: Record<string, unknown>, label: string }>()
  function ask(path: string, body: Record<string, unknown>, label: string, method: 'POST' | 'PUT' = 'POST') {
    action.value = { url: base + path, method, body, label }
  }
  async function finish() {
    action.value = undefined
    await saved()
  }
  return { action, ask, finish }
}
