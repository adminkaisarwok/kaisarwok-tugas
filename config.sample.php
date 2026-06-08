<?php
/* ============================================================
   KAISAR WOK — Contoh Konfigurasi
   SALIN file ini menjadi "config.php" lalu isi data aslinya.
   (config.php TIDAK ikut ke GitHub demi keamanan.)
   ============================================================ */

// --- Database MySQL ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'NAMA_DATABASE');
define('DB_USER', 'USER_DATABASE');
define('DB_PASS', 'PASSWORD_DATABASE');

// --- Akun login ---
// Semua KARYAWAN memakai password yang sama ($passKaryawan).
function kw_users() {
  $passKaryawan = 'GANTI_PASSWORD_KARYAWAN';
  $passPetinggi = 'GANTI_PASSWORD_PETINGGI';

  // username => Nama lengkap (untuk ditampilkan)
  $karyawan = [
    'namakaryawan1' => 'Nama Karyawan 1',
    'namakaryawan2' => 'Nama Karyawan 2',
    // ...tambah sesuai jumlah karyawan
  ];

  $users = [
    ['username' => 'petinggi', 'password' => $passPetinggi, 'role' => 'petinggi', 'name' => 'Petinggi Kaisar Wok'],
  ];
  foreach ($karyawan as $u => $nama) {
    $users[] = ['username' => $u, 'password' => $passKaryawan, 'role' => 'karyawan', 'name' => $nama];
  }
  return $users;
}
