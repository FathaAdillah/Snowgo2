<x-mail::message>
# Undangan staf Snowego

Gunakan tautan pribadi ini dalam satu jam untuk membuat password dan mengaktifkan autentikasi dua faktor. Jangan bagikan tautan ini.

<x-mail::button :url="$url">
Aktifkan akun staf
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
