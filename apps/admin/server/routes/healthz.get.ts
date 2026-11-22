// Liveness for container health checks; does not call the API.
export default defineEventHandler(() => ({ status: 'ok' }))
