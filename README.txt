PLUGIN KOMPENSASI MASA AKTIF - PHPNuxBill 2025.10.25

Isi paket:
- compensasi_masa_aktif.php : plugin/controller
- ui/compensasi_masa_aktif.tpl : template admin

Instalasi:
1. Backup database dan folder PHPNuxBill.
2. Salin compensasi_masa_aktif.php ke system/plugin/.
3. Salin compensasi_masa_aktif.tpl ke system/plugin/ui/.
4. Login sebagai SuperAdmin/Admin, buka menu Layanan > Kompensasi Masa Aktif.
5. Tabel riwayat dibuat otomatis saat halaman plugin pertama kali dibuka.

Perilaku:
- Hanya paket tbl_plans.prepaid='yes'.
- Hanya tbl_user_recharges.status='on' dan expiration >= tanggal hari ini.
- POSTPAID, status off, dan expired tidak diproses.
- Tanggal expired masing-masing ditambah jumlah hari manual; jam tidak berubah.
- Tidak mengubah status layanan, tidak membuat transaksi, dan tidak memanggil aktivasi MikroTik.
- Notifikasi memakai Message::sendSMS, sendWhatsapp, sendEmail, addToInbox.

Catatan pengujian:
Paket ini dibuat berdasarkan source ZIP PHPNuxBill 2025.10.25 yang diberikan. Uji dahulu di staging/salinan database. Pastikan kolom tbl_user_recharges (recharged_on, expiration, time, status, namebp, username, customer_id, plan_id) cocok dengan database instalasi. Notifikasi dikirim saat batch berjalan; kegagalan kanal ditangkap dan dicatat pada status item jika pemanggilan fungsi mengembalikan error/false.
