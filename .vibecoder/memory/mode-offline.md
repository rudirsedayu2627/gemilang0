# Mode offline (luring) — 5 Okt 2026

Permintaan pemilik: **"buat aplikasinya offline & online"**. Pilihan yang
ditetapkan pemilik saat ditanya:

1. yang harus bisa offline = **quiz/ujian bisa dikerjakan offline dan jawabannya
   terkirim otomatis saat online lagi**;
2. sasarannya **aplikasi Android (APK) saja**;
3. tampilannya: **pita "Kamu sedang offline" + halaman "Materi Offline" di menu,
   menyatu dengan tema situs**.

Hasilnya: pita status di semua halaman, halaman `siswa-offline.php` (menu siswa
"Materi Offline"), endpoint `api-offline.php` (siap/batal/status/ping), modul
`LMSLuring` di `assets/app.js` (antrean jawaban di localStorage + kirim otomatis),
penyimpanan & pengunduh offline `glc-android/.../Luring.java`, penyajian salinan
lewat `shouldInterceptRequest`, dan APK versi 3 / 1.2. Tanpa perubahan skema;
pengiriman jawaban tetap lewat `api-autosave.php` + `ujian.php` (bentuk field sama
dengan form ujian), jadi penilaian tidak berubah sedikit pun.

Fitur ini butuh APK **1.2** — versi lama (1.1) belum punya penyimpanan offline,
dan halaman "Materi Offline" memberi tahu itu + menyediakan tombol unduh APK.

Rincian teknis lengkap: `AGENTS.md` bagian "Mode offline (luring)"; panduan
aplikasi: `glc-android/README.md`. Uji: `_tests/offline-flow.sh` (78) dan
`_tests/offline-ui.js` (55).
