<x-mail::message>
# Atur ulang password

Kami menerima permintaan untuk mengatur ulang password akun Snowego kamu. Tautan berlaku {{ config('auth.passwords.users.expire') }} menit dan hanya bisa dipakai sekali. Semua sesi aktif akan keluar setelah password diganti.

<x-mail::button :url="$url">
Atur ulang password
</x-mail::button>

Abaikan email ini jika kamu tidak meminta reset password.

{{ config('app.name') }}
</x-mail::message>
