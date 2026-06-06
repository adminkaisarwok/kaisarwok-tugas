<?php
/* ============================================================
   KAISAR WOK — API (PHP + MySQL)
   Semua aksi lewat: api.php?action=...
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';

function body() { $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : []; }
function out($d) { echo json_encode($d); exit; }
function ms() { return (int) round(microtime(true) * 1000); }
function currentUser() { return $_SESSION['user'] ?? null; }
function requireLogin() { if (empty($_SESSION['user'])) { http_response_code(401); out(['error' => 'Silakan login dulu']); } }
function requirePetinggi() { requireLogin(); if ($_SESSION['user']['role'] !== 'petinggi') { http_response_code(403); out(['error' => 'Khusus petinggi']); } }

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
    out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
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
    $q = $pdo->prepare("SELECT photo FROM tasks WHERE id=?"); $q->execute([$id]); $t = $q->fetch();
    if ($t && $t['photo']) { @unlink(__DIR__ . '/' . ltrim($t['photo'], '/')); }
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
    out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
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
    $q = $pdo->prepare("SELECT id FROM reservations WHERE id=?"); $q->execute([$id]);
    if (!$q->fetch()) { http_response_code(404); out(['error' => 'Reservasi tidak ditemukan']); }
    $pdo->prepare("UPDATE reservations SET name=?,date=?,time=?,pax=?,phone=?,note=?,dp=? WHERE id=?")
        ->execute([$name, $date, $time, $pax, $phone, $note, $dp, $id]);
    out(['ok' => true]);
  }

  /* ---- hapus reservasi ---- */
  if ($action === 'resvDelete' && $method === 'POST') {
    $b = body(); $id = (int) ($b['id'] ?? 0);
    $pdo->prepare("DELETE FROM reservations WHERE id=?")->execute([$id]);
    out(['ok' => true]);
  }

  http_response_code(404);
  out(['error' => 'Aksi tidak dikenal']);

} catch (Exception $e) {
  http_response_code(500);
  out(['error' => 'Server error: ' . $e->getMessage()]);
}
