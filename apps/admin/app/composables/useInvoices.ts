import type { paths } from '@snowego/api-types'

export type InvoicePage = paths['/admin/invoices']['get']['responses'][200]['content']['application/json']
export type InvoiceDetail = paths['/admin/invoices/{number}']['get']['responses'][200]['content']['application/json']
export const invoicesApi = '/api/v1/admin/invoices'
export const invoiceStatus = { ISSUED: 'Terbit', VOID: 'Dibatalkan', PAID: 'Lunas' }
export const invoicePurpose = { FULL_PAYMENT: 'Pembayaran penuh', DEPOSIT: 'Uang muka', BALANCE: 'Pelunasan', ADDITIONAL_CHARGE: 'Biaya tambahan' }
export const invoicePdfStatus = { PENDING: 'Dalam antrean', RENDERING: 'Sedang dibuat', FAILED: 'Gagal dibuat', READY: 'Siap diunduh' }
export const invoiceMoney = (value: number) => `Rp ${value.toLocaleString('id-ID')}`
