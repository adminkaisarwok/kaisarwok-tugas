<?php
/* ============================================================
   KAISAR WOK — Contoh Konfigurasi
   SALIN file ini menjadi "config.php" lalu isi data aslinya.
   (config.php TIDAK ikut ke GitHub demi keamanan.)
   ============================================================ */

// --- Database MySQL (lihat di hPanel > Databases > MySQL Databases) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'NAMA_DATABASE');
define('DB_USER', 'USER_DATABASE');
define('DB_PASS', 'PASSWORD_DATABASE');

// --- Akun login (ganti username & password sesuai keinginan) ---
function kw_users() {
  return [
    ['username' => 'karyawan', 'password' => 'GANTI_PASSWORD_KARYAWAN', 'role' => 'karyawan', 'name' => 'Karyawan Kaisar Wok'],
    ['username' => 'petinggi', 'password' => 'GANTI_PASSWORD_PETINGGI', 'role' => 'petinggi', 'name' => 'Petinggi Kaisar Wok'],
  ];
}
