<!doctype html>
<html lang="id"><meta charset="utf-8"><title>Invoice {{ $invoice['number'] }}</title>
<style>body{font:12px "DejaVu Sans","Nanum Gothic",sans-serif;color:#242439}h1{font-size:22px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:6px;border-bottom:1px solid #ddd;text-align:left}.n{text-align:right}</style>
<body>
<h1>Snowego · Invoice {{ $invoice['number'] }}</h1>
<p>Pesanan {{ $invoice['order_number'] }} · {{ $invoice['purpose'] }}</p>
@if($invoice['replaces_invoice_number'])<p>Menggantikan {{ $invoice['replaces_invoice_number'] }}</p>@endif
<p>Terbit: {{ $invoice['issued_at'] }} · Jatuh tempo: {{ $invoice['due_at'] }}</p>
<table><thead><tr><th>Uraian</th><th>Jumlah</th><th class="n">Harga satuan (IDR)</th><th class="n">Nilai (IDR)</th></tr></thead><tbody>
@foreach($invoice['items'] as $line)
<tr><td>{{ $line['description'] }}</td><td>{{ $line['qty'] }}</td><td class="n">{{ number_format($line['unit_amount'],0,',','.') }}</td><td class="n">{{ number_format($line['amount'],0,',','.') }}</td></tr>
@endforeach
</tbody></table>
@foreach(['subtotal_amount'=>'Subtotal','discount_amount'=>'Diskon','tax_amount'=>'Pajak','shipping_amount'=>'Pengiriman','total_amount'=>'Total tagihan'] as $field=>$label)
<p class="n">{{ $label }}: Rp {{ number_format($invoice[$field],0,',','.') }}</p>
@endforeach
<p>Dokumen penerbitan tagihan, bukan bukti pembayaran.</p>
</body></html>
