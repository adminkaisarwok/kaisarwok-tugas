<?php
/* Koneksi database + buat tabel otomatis (sekali jalan) */
require_once __DIR__ . '/config.php';

function db() {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER, DB_PASS,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
      id INT AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(300) NOT NULL,
      name VARCHAR(80) DEFAULT '',
      category VARCHAR(20) NOT NULL,
      date VARCHAR(10) DEFAULT '',
      status VARCHAR(20) NOT NULL DEFAULT 'todo',
      created BIGINT NOT NULL,
      approvedAt BIGINT NULL,
      photo VARCHAR(255) DEFAULT NULL,
      photoBy VARCHAR(80) DEFAULT NULL,
      photoAt BIGINT NULL,
      link VARCHAR(500) DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // tambah kolom 'link' kalau tabel sudah dibuat sebelumnya (tanpa kolom ini)
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN link VARCHAR(500) DEFAULT ''"); } catch (Exception $e) { /* kolom sudah ada */ }
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN gcal_id VARCHAR(128) DEFAULT NULL"); } catch (Exception $e) { /* sudah ada */ }

    // tabel reservasi tamu
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      date VARCHAR(10) NOT NULL,
      time VARCHAR(5) DEFAULT '',
      pax INT DEFAULT 0,
      phone VARCHAR(40) DEFAULT '',
      note VARCHAR(300) DEFAULT '',
      dp BIGINT DEFAULT 0,
      created BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // tambah kolom 'dp' kalau tabel reservasi sudah ada sebelumnya
    try { $pdo->exec("ALTER TABLE reservations ADD COLUMN dp BIGINT DEFAULT 0"); } catch (Exception $e) { /* sudah ada */ }
    try { $pdo->exec("ALTER TABLE reservations ADD COLUMN gcal_id VARCHAR(128) DEFAULT NULL"); } catch (Exception $e) { /* sudah ada */ }

    // penyimpanan umum (mis. catatan marketing yang bisa diedit)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
      k VARCHAR(64) PRIMARY KEY,
      v MEDIUMTEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  return $pdo;
}
