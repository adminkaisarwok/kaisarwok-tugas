<?php
/* ============================================================
   KAISAR WOK — API (PHP + MySQL)
   Semua aksi lewat: api.php?action=...
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gcal.php';

function body() { $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : []; }
function out($d) { echo json_encode($d); exit; }
function ms() { return (int) round(microtime(true) * 1000); }
function currentUser() { return $_SESSION['user'] ?? null; }
function requireLogin() { if (empty($_SESSION['user'])) { http_response_code(401); out(['error' => 'Silakan login dulu']); } }
function requirePetinggi() { requireLogin(); if ($_SESSION['user']['role'] !== 'petinggi') { http_response_code(403); out(['error' => 'Khusus petinggi']); } }

// daftar tabel editable yang diizinkan + isi default-nya
function notesDefault($key) {
  if ($key === 'marketing_ukuran') {
    return ['columns' => ['Jenis Materi', 'Ukuran', 'Keterangan'], 'rows' => [
      ['Banner Canopy', '750 cm × 5 m', 'Yang biasa dipasang'],
      ['Standing Banner', '180 × 80 cm', 'Menu best seller · menu rekomendasi · promo mingguan / 3 harian'],
      ['Banner Samping / Dekat Blower', '1 m × 5 m', 'Seluruh foto menu TANPA harga — cukup nama + foto'],
      ['Kartu Apresiasi', '1536 × 1024 px', '—'],
      ['Stiker Hampers', '5 × 8 cm', 'Sudut membulat'],
      ['Stiker Hampers', '10,5 × 4 cm', 'Tanpa sudut'],
      ['Stiker Take Away', 'D3 · D4 · D5', 'Ukuran take away'],
      ['Voucher', '15 × 6,1 cm', '1 sisi, potong + cacah'],
    ]];
  }
  return ['columns' => ['Catatan', 'Keterangan'], 'rows' => [
    ['Sebelum cetak', 'Cek ejaan, harga, dan logo Kaisar Wok sudah benar'],
    ['File desain', 'Pakai CMYK & resolusi tinggi; simpan master untuk event'],
    ['Banner dekat blower', 'Foto menu saja, TANPA harga'],
    ['Saat ada event', 'Siapkan standing banner promo & voucher lebih awal'],
  ]];
}
function notesAllowed($key) { return in_array($key, ['marketing_notes', 'marketing_ukuran'], true); }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$CATEGORIES = ['marketing', 'staff'];
$STATUSES = ['todo', 'pending', 'approved'];

try {
  $pdo = db();

  /* ---- LOGIN / SESSION ---- */
  if ($action === 'login' && $method === 'POST') {
    $b = body();
    $username = trim((string) ($b['username'] ?? ''));
    $password = (string) ($b['password'] ?? '');
    foreach (kw_users() as $u) {
      if ($u['username'] === $username && $u['password'] === $password) {
        $_SESSION['user'] = ['username' => $u['username'], 'role' => $u['role'], 'name' => $u['name']];
        out(['ok' => true, 'role' => $u['role'], 'name' => $u['name']]);
      }
    }
    http_response_code(401);
    out(['ok' => false, 'error' => 'Username atau password salah']);
  }
  if ($action === 'me') {
    $u = currentUser();
    out($u ? ['loggedIn' => true, 'username' => $u['username'], 'role' => $u['role'], 'name' => $u['name']] : ['loggedIn' => false]);
  }
  if ($action === 'logout') {
    $_SESSION = []; session_destroy();
    out(['ok' => true]);
  }

  /* ---- mulai sini wajib login ---- */
  requireLogin();

  /* ---- daftar semua tugas ---- */
  if ($action === 'list') {
    $rows = $pdo->query("SELECT * FROM tasks ORDER BY id ASC")->fetchAll();
    foreach ($rows as &$r) {
      $r['id'] = (int) $r['id'];
      $r['created'] = (int) $r['created'];
      $r['approvedAt'] = $r['approvedAt'] !== null ? (int) $r['approvedAt'] : null;
      $r['photoAt'] = $r['photoAt'] !== null ? (int) $r['photoAt'] : null;
    }
    out($rows);
  }

  /* ---- tambah tugas ---- */
  if ($action === 'add' && $method === 'POST') {
    $b = body();
    $title = trim(mb_substr((string) ($b['title'] ?? ''), 0, 300));
    $name = trim(mb_substr((string) ($b['name'] ?? ''), 0, 80));
    $category = (string) ($b['category'] ?? '');
    $date = trim((string) ($b['date'] ?? ''));
    $link = trim(mb_substr((string) ($b['link'] ?? ''), 0, 500));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
    if ($title === '') { http_response_code(400); out(['error' => 'Judul tugas kosong']); }
    if (!in_array($category, $CATEGORIES)) { http_response_code(400); out(['error' => 'Kategori tidak valid']); }
    $stmt = $pdo->prepare("INSERT INTO tasks (title,name,category,date,status,created,link) VALUES (?,?,?,?,'todo',?,?)");
    $stmt->execute([$title, $name, $category, $date, ms(), $link]);
    $newId = (int) $pdo->lastInsertId();
    if ($date && gcal_enabled()) {
      $catLabel = $category === 'marketing' ? 'Marketing' : 'Staff';
      $desc = 'Kategori: ' . $catLabel . ($name ? ' • Oleh: ' . $name : '') . ($link ? ' • Link: ' . $link : '');
      $ev = gcal_upsert(null, $title, $desc, $date, '');
      if ($ev) $pdo->prepare("UPDATE tasks SET gcal_id=? WHERE id=?")->execute([$ev, $newId]);
    }
    out(['ok' => true, 'id' => $newId]);
  }

  /* ---- ubah status (approve perlu password petinggi) ---- */
  if ($action === 'setStatus' && $method === 'POST') {
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    $status = (string) ($b['status'] ?? '');
    if (!in_array($status, $STATUSES)) { http_response_code(400); out(['error' => 'Status tidak valid']); }
    $q = $pdo->prepare("SELECT status FROM tasks WHERE id=?"); $q->execute([$id]); $t = $q->fetch();
    if (!$t) { http_response_code(404); out(['error' => 'Tugas tidak ditemukan']); }
    $adminAction = ($status === 'approved' || $t['status'] === 'approved');
    if ($adminAction) { requirePetinggi(); }  // approve / batalkan approval hanya petinggi
    if ($status === 'approved') {
      $pdo->prepare("UPDATE tasks SET status=?, approvedAt=? WHERE id=?")->execute([$status, ms(), $id]);
    } else {
      $pdo->prepare("UPDATE tasks SET status=?, approvedAt=NULL WHERE id=?")->execute([$status, $id]);
    }
    out(['ok' => true]);
  }

  /* ---- hapus tugas (+ fotonya) ---- */
  if ($action === 'delete' && $method === 'POST') {
    $b = body(); $id = (int) ($b['id'] ?? 0);
    $q = $pdo->prepare("SELECT photo, gcal_id FROM tasks WHERE id=?"); $q->execute([$id]); $t = $q->fetch();
    if ($t && $t['photo']) { @unlink(__DIR__ . '/' . ltrim($t['photo'], '/')); }
    if ($t && !empty($t['gcal_id'])) gcal_delete($t['gcal_id']);
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
    out(['ok' => true]);
  }

  /* ---- upload foto bukti ---- */
  if ($action === 'photo' && $method === 'POST') {
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    $by = trim(mb_substr((string) ($b['by'] ?? ''), 0, 80));
    $image = (string) ($b['image'] ?? '');
    if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,(.+)$/', $image, $m)) { http_response_code(400); out(['error' => 'Format gambar tidak valid']); }
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $data = base64_decode($m[2]);
    if ($data === false || strlen($data) > 10 * 1024 * 1024) { http_response_code(413); out(['error' => 'Gambar tidak valid / terlalu besar']); }
    $q = $pdo->prepare("SELECT photo FROM tasks WHERE id=?"); $q->execute([$id]); $t = $q->fetch();
    if (!$t) { http_response_code(404); out(['error' => 'Tugas tidak ditemukan']); }
    if (!is_dir(__DIR__ . '/uploads')) @mkdir(__DIR__ . '/uploads', 0755, true);
    if ($t['photo']) { @unlink(__DIR__ . '/' . ltrim($t['photo'], '/')); }
    $fname = 'uploads/task-' . $id . '-' . ms() . '.' . $ext;
    file_put_contents(__DIR__ . '/' . $fname, $data);
    $pdo->prepare("UPDATE tasks SET photo=?, photoBy=?, photoAt=? WHERE id=?")->execute([$fname, $by, ms(), $id]);
    out(['ok' => true, 'photo' => $fname]);
  }

  /* ---- hapus foto bukti ---- */
  if ($action === 'deletePhoto' && $method === 'POST') {
    $b = body(); $id = (int) ($b['id'] ?? 0);
    $q = $pdo->prepare("SELECT photo FROM tasks WHERE id=?"); $q->execute([$id]); $t = $q->fetch();
    if ($t && $t['photo']) { @unlink(__DIR__ . '/' . ltrim($t['photo'], '/')); }
    $pdo->prepare("UPDATE tasks SET photo=NULL, photoBy=NULL, photoAt=NULL WHERE id=?")->execute([$id]);
    out(['ok' => true]);
  }

  /* ---- ubah / tambah link video pada tugas ---- */
  if ($action === 'link' && $method === 'POST') {
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    $link = trim(mb_substr((string) ($b['link'] ?? ''), 0, 500));
    $q = $pdo->prepare("SELECT id FROM tasks WHERE id=?"); $q->execute([$id]);
    if (!$q->fetch()) { http_response_code(404); out(['error' => 'Tugas tidak ditemukan']); }
    $pdo->prepare("UPDATE tasks SET link=? WHERE id=?")->execute([$link, $id]);
    out(['ok' => true]);
  }

  /* ---- daftar reservasi ---- */
  if ($action === 'resvList') {
    $rows = $pdo->query("SELECT * FROM reservations ORDER BY date ASC, time ASC, id ASC")->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['pax'] = (int) $r['pax']; $r['dp'] = (int) ($r['dp'] ?? 0); $r['created'] = (int) $r['created']; }
    out($rows);
  }

  /* ---- tambah reservasi ---- */
  if ($action === 'resvAdd' && $method === 'POST') {
    $b = body();
    $name = trim(mb_substr((string) ($b['name'] ?? ''), 0, 120));
    $date = trim((string) ($b['date'] ?? ''));
    $time = trim((string) ($b['time'] ?? ''));
    $pax  = (int) ($b['pax'] ?? 0);
    $phone = trim(mb_substr((string) ($b['phone'] ?? ''), 0, 40));
    $note = trim(mb_substr((string) ($b['note'] ?? ''), 0, 300));
    $dp = (int) ($b['dp'] ?? 0); if ($dp < 0) $dp = 0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); out(['error' => 'Tanggal tidak valid']); }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';
    if ($name === '') { http_response_code(400); out(['error' => 'Nama tamu kosong']); }
    if ($pax < 0) $pax = 0;
    $stmt = $pdo->prepare("INSERT INTO reservations (name,date,time,pax,phone,note,dp,created) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$name, $date, $time, $pax, $phone, $note, $dp, ms()]);
    $rid = (int) $pdo->lastInsertId();
    if (gcal_enabled()) {
      $sum = 'Reservasi: ' . $name . ($pax ? " ($pax org)" : '');
      $d = []; if ($phone) $d[] = 'HP: ' . $phone; if ($dp) $d[] = 'DP: Rp' . number_format($dp, 0, ',', '.'); if ($note) $d[] = $note;
      $ev = gcal_upsert(null, $sum, implode(' • ', $d), $date, $time, 120);
      if ($ev) $pdo->prepare("UPDATE reservations SET gcal_id=? WHERE id=?")->execute([$ev, $rid]);
    }
    out(['ok' => true, 'id' => $rid]);
  }

  /* ---- ubah reservasi ---- */
  if ($action === 'resvUpdate' && $method === 'POST') {
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    $name = trim(mb_substr((string) ($b['name'] ?? ''), 0, 120));
    $date = trim((string) ($b['date'] ?? ''));
    $time = trim((string) ($b['time'] ?? ''));
    $pax  = (int) ($b['pax'] ?? 0);
    $phone = trim(mb_substr((string) ($b['phone'] ?? ''), 0, 40));
    $note = trim(mb_substr((string) ($b['note'] ?? ''), 0, 300));
    $dp = (int) ($b['dp'] ?? 0); if ($dp < 0) $dp = 0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); out(['error' => 'Tanggal tidak valid']); }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';
    if ($name === '') { http_response_code(400); out(['error' => 'Nama tamu kosong']); }
    if ($pax < 0) $pax = 0;
    $q = $pdo->prepare("SELECT gcal_id FROM reservations WHERE id=?"); $q->execute([$id]); $cur = $q->fetch();
    if (!$cur) { http_response_code(404); out(['error' => 'Reservasi tidak ditemukan']); }
    $pdo->prepare("UPDATE reservations SET name=?,date=?,time=?,pax=?,phone=?,note=?,dp=? WHERE id=?")
        ->execute([$name, $date, $time, $pax, $phone, $note, $dp, $id]);
    if (gcal_enabled()) {
      $sum = 'Reservasi: ' . $name . ($pax ? " ($pax org)" : '');
      $d = []; if ($phone) $d[] = 'HP: ' . $phone; if ($dp) $d[] = 'DP: Rp' . number_format($dp, 0, ',', '.'); if ($note) $d[] = $note;
      $ev = gcal_upsert($cur['gcal_id'] ?? null, $sum, implode(' • ', $d), $date, $time, 120);
      if ($ev && $ev !== ($cur['gcal_id'] ?? null)) $pdo->prepare("UPDATE reservations SET gcal_id=? WHERE id=?")->execute([$ev, $id]);
    }
    out(['ok' => true]);
  }

  /* ---- hapus reservasi ---- */
  if ($action === 'resvDelete' && $method === 'POST') {
    $b = body(); $id = (int) ($b['id'] ?? 0);
    $q = $pdo->prepare("SELECT gcal_id FROM reservations WHERE id=?"); $q->execute([$id]); $cur = $q->fetch();
    if ($cur && !empty($cur['gcal_id'])) gcal_delete($cur['gcal_id']);
    $pdo->prepare("DELETE FROM reservations WHERE id=?")->execute([$id]);
    out(['ok' => true]);
  }

  /* ---- Tabel Marketing yang bisa diedit (ukuran & catatan) ---- */
  if ($action === 'notesGet') {
    $key = (string) ($_GET['key'] ?? 'marketing_notes');
    if (!notesAllowed($key)) { http_response_code(400); out(['error' => 'key tidak valid']); }
    $q = $pdo->prepare("SELECT v FROM settings WHERE k=?"); $q->execute([$key]);
    $row = $q->fetch();
    $data = ($row && $row['v']) ? json_decode($row['v'], true) : null;
    if (empty($data) || empty($data['columns'])) { $data = notesDefault($key); }
    out($data);
  }
  if ($action === 'notesSave' && $method === 'POST') {
    $b = body();
    $key = (string) ($b['key'] ?? 'marketing_notes');
    if (!notesAllowed($key)) { http_response_code(400); out(['error' => 'key tidak valid']); }
    $cols = $b['columns'] ?? [];
    $rows = $b['rows'] ?? [];
    if (!is_array($cols) || !is_array($rows) || count($cols) < 1) { http_response_code(400); out(['error' => 'Data tidak valid']); }
    $cols = array_slice(array_values(array_map(function ($c) { return mb_substr(trim((string) $c), 0, 60); }, $cols)), 0, 12);
    $nc = count($cols);
    $clean = [];
    foreach (array_slice($rows, 0, 300) as $r) {
      if (!is_array($r)) continue;
      $cells = [];
      for ($i = 0; $i < $nc; $i++) { $cells[] = mb_substr((string) ($r[$i] ?? ''), 0, 1000); }
      $clean[] = $cells;
    }
    $v = json_encode(['columns' => $cols, 'rows' => $clean], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("DELETE FROM settings WHERE k=?")->execute([$key]);
    $pdo->prepare("INSERT INTO settings (k,v) VALUES (?, ?)")->execute([$key, $v]);
    out(['ok' => true]);
  }

  /* ---- Catatan teks bebas (kotak tulis) ---- */
  if ($action === 'textGet') {
    $key = (string) ($_GET['key'] ?? '');
    if ($key !== 'marketing_catatan') { http_response_code(400); out(['error' => 'key tidak valid']); }
    $q = $pdo->prepare("SELECT v FROM settings WHERE k=?"); $q->execute([$key]); $row = $q->fetch();
    $text = ($row && $row['v'] !== null) ? $row['v']
      : "• Sebelum cetak: cek ejaan, harga, dan logo Kaisar Wok sudah benar\n• File desain: pakai CMYK & resolusi tinggi; simpan master untuk event\n• Banner dekat blower: foto menu saja, TANPA harga\n• Saat ada event: siapkan standing banner promo & voucher lebih awal";
    out(['text' => $text]);
  }
  if ($action === 'textSave' && $method === 'POST') {
    $b = body();
    $key = (string) ($b['key'] ?? '');
    if ($key !== 'marketing_catatan') { http_response_code(400); out(['error' => 'key tidak valid']); }
    $text = mb_substr((string) ($b['text'] ?? ''), 0, 20000);
    $pdo->prepare("DELETE FROM settings WHERE k=?")->execute([$key]);
    $pdo->prepare("INSERT INTO settings (k,v) VALUES (?, ?)")->execute([$key, $text]);
    out(['ok' => true]);
  }

  /* ---- Google Calendar: status & sinkron data lama ---- */
  if ($action === 'gcalStatus') {
    out(['enabled' => gcal_enabled()]);
  }
  if ($action === 'gcalSync' && $method === 'POST') {
    requirePetinggi();
    if (!gcal_enabled()) { out(['ok' => false, 'error' => 'Google Calendar belum diaktifkan di server']); }
    $n = 0;
    foreach ($pdo->query("SELECT * FROM tasks WHERE date <> '' AND (gcal_id IS NULL OR gcal_id='')")->fetchAll() as $t) {
      $catLabel = $t['category'] === 'marketing' ? 'Marketing' : 'Staff';
      $desc = 'Kategori: ' . $catLabel . ($t['name'] ? ' • Oleh: ' . $t['name'] : '') . (!empty($t['link']) ? ' • Link: ' . $t['link'] : '');
      $ev = gcal_upsert(null, $t['title'], $desc, $t['date'], '');
      if ($ev) { $pdo->prepare("UPDATE tasks SET gcal_id=? WHERE id=?")->execute([$ev, $t['id']]); $n++; }
    }
    foreach ($pdo->query("SELECT * FROM reservations WHERE (gcal_id IS NULL OR gcal_id='')")->fetchAll() as $r) {
      $sum = 'Reservasi: ' . $r['name'] . ($r['pax'] ? " ({$r['pax']} org)" : '');
      $d = []; if ($r['phone']) $d[] = 'HP: ' . $r['phone']; if (!empty($r['dp'])) $d[] = 'DP: Rp' . number_format($r['dp'], 0, ',', '.'); if ($r['note']) $d[] = $r['note'];
      $ev = gcal_upsert(null, $sum, implode(' • ', $d), $r['date'], $r['time'], 120);
      if ($ev) { $pdo->prepare("UPDATE reservations SET gcal_id=? WHERE id=?")->execute([$ev, $r['id']]); $n++; }
    }
    out(['ok' => true, 'synced' => $n]);
  }

  http_response_code(404);
  out(['error' => 'Aksi tidak dikenal']);

} catch (Exception $e) {
  http_response_code(500);
  out(['error' => 'Server error: ' . $e->getMessage()]);
}
