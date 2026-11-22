<x-mail::message>
# Verifikasi email kamu

Klik tombol di bawah untuk mengaktifkan akun Snowego. Tautan berlaku {{ \App\Modules\Identity\Challenges\EmailVerifications::TTL_MINUTES }} menit dan hanya bisa dipakai sekali.

<x-mail::button :url="$url">
Verifikasi email
</x-mail::button>

Abaikan email ini jika kamu tidak mendaftar di Snowego.

{{ config('app.name') }}
</x-mail::message>
