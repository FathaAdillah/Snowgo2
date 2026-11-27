<?php

namespace App\Modules\Notifications;

final class NotificationTemplates
{
    public const CHALLENGES = ['StaffInvited', 'StaffEmailChangeRequested', 'EmailVerificationRequested', 'PasswordResetRequested'];

    private const TITLES = [
        'GoogleAccountLinked' => 'Akun Google ditautkan ke Snowego',
        'OrderCreated' => 'Pesanan dibuat',
        'OrderPaid' => 'Pesanan dibayar',
        'OrderDelivered' => 'Pesanan telah diterima',
        'OrderCompleted' => 'Pesanan selesai',
        'OrderCancelled' => 'Pesanan dibatalkan',
        'OrderExpired' => 'Pesanan kedaluwarsa',
        'InvoiceIssued' => 'Invoice Snowego',
        'PaymentSubmitted' => 'Bukti pembayaran diterima',
        'PaymentApproved' => 'Pembayaran disetujui',
        'PaymentRejected' => 'Pembayaran ditolak',
        'PaymentRecovered' => 'Pembayaran dikonfirmasi',
        'PreorderConfirmed' => 'Preorder dikonfirmasi',
        'PreorderEtaUpdated' => 'Estimasi preorder diperbarui',
        'ProcurementStageChanged' => 'Tahap pengadaan diperbarui',
        'RequestOrderSubmitted' => 'Request order diajukan',
        'QuotationSent' => 'Penawaran tersedia',
        'QuotationResponded' => 'Penawaran ditanggapi',
        'QuotationAccepted' => 'Penawaran diterima',
        'ShipmentCreated' => 'Pengiriman disiapkan',
        'ShipmentStatusUpdated' => 'Status pengiriman diperbarui',
        'ShipmentCancelled' => 'Pengiriman dibatalkan',
        'RefundRequested' => 'Refund diajukan',
        'RefundApproved' => 'Refund disetujui',
        'RefundRejected' => 'Refund ditolak',
        'RefundCompleted' => 'Refund selesai',
        'ReturnRequested' => 'Retur diajukan',
        'ReturnReviewed' => 'Pengajuan retur ditinjau',
        'ReturnResolved' => 'Retur selesai',
    ];

    /** @return list<string> */
    public static function lifecycleEvents(): array
    {
        return array_keys(self::TITLES);
    }

    /** @return array{title:string,body:string,link:string} */
    public static function message(string $template, string $number, string $link): array
    {
        $title = self::TITLES[$template].($number === '' ? '' : ' '.$number);

        return [
            'title' => $title,
            'body' => $template === 'GoogleAccountLinked'
                ? 'Akun Google baru saja ditautkan. Jika ini bukan Anda, segera hubungi dukungan Snowego.'
                : 'Silakan masuk ke akun Snowego untuk melihat detail: '.$title.'.',
            'link' => $link,
        ];
    }
}
