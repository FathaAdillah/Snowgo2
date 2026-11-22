# Snowego API

Laravel 13 modular monolith. Modul domain berada di `app/Modules/<Modul>` (route `Routes/api.php` di-mount pada `/api/v1`, migration di `Database/Migrations`) dan didaftarkan di `config/modules.php`. Envelope respons, request ID dan error renderer ada di `app/Support/Http`.

Perintah utama dijalankan dari root repository: `make test-api`, `make lint`. Lihat [README root](../../README.md) dan [arsitektur](../../docs/03-TECH-STACK-ARCHITECTURE.md).
