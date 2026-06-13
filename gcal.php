<?php
/* ============================================================
   KAISAR WOK — Integrasi Google Calendar (Service Account)
   OPSIONAL & aman-gagal: kalau kredensial belum dipasang,
   semua fungsi jadi no-op (aplikasi tetap jalan normal).

   Yang dibutuhkan di server (keduanya TIDAK ikut GitHub):
   1) File  google-credentials.json  (kunci service account) di folder ini
   2) define('GCAL_CALENDAR_ID', '....@group.calendar.google.com') di config.php
   ============================================================ */

function gcal_creds() {
  static $c = false;
  if ($c !== false) return $c;
  $c = null;
  $f = __DIR__ . '/google-credentials.json';
  if (is_file($f) && defined('GCAL_CALENDAR_ID') && GCAL_CALENDAR_ID) {
    $j = json_decode(file_get_contents($f), true);
    if (!empty($j['client_email']) && !empty($j['private_key'])) {
      $c = ['email' => $j['client_email'], 'key' => $j['private_key'], 'cal' => GCAL_CALENDAR_ID];
    }
  }
  return $c;
}
function gcal_enabled() { return gcal_creds() !== null; }

function gcal_http($method, $url, $token, $body = null, $ctype = 'application/json') {
  $ch = curl_init($url);
  $headers = [];
  if ($token) $headers[] = 'Authorization: Bearer ' . $token;
  if ($body !== null) $headers[] = 'Content-Type: ' . $ctype;
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $r = curl_exec($ch);
  curl_close($ch);
  return $r;
}

function gcal_token() {
  $c = gcal_creds(); if (!$c) return null;
  $pdo = db();
  // pakai token cache kalau masih berlaku
  try {
    $row = $pdo->query("SELECT v FROM settings WHERE k='gcal_token'")->fetch();
    if ($row && $row['v']) {
      $t = json_decode($row['v'], true);
      if (!empty($t['access_token']) && !empty($t['exp']) && $t['exp'] > time() + 60) return $t['access_token'];
    }
  } catch (Exception $e) {}

  $now = time();
  $b64 = function ($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
  $jwtHeader = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
  $jwtClaim = $b64(json_encode([
    'iss' => $c['email'],
    'scope' => 'https://www.googleapis.com/auth/calendar',
    'aud' => 'https://oauth2.googleapis.com/token',
    'iat' => $now, 'exp' => $now + 3600,
  ]));
  $unsigned = $jwtHeader . '.' . $jwtClaim;
  $sig = '';
  if (!openssl_sign($unsigned, $sig, $c['key'], OPENSSL_ALGO_SHA256)) return null;
  $jwt = $unsigned . '.' . $b64($sig);

  $resp = gcal_http('POST', 'https://oauth2.googleapis.com/token', null,
    http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
    'application/x-www-form-urlencoded');
  $t = json_decode($resp, true);
  if (empty($t['access_token'])) return null;

  try {
    $save = json_encode(['access_token' => $t['access_token'], 'exp' => $now + (int) ($t['expires_in'] ?? 3600)]);
    $pdo->prepare("DELETE FROM settings WHERE k='gcal_token'")->execute();
    $pdo->prepare("INSERT INTO settings (k,v) VALUES ('gcal_token', ?)")->execute([$save]);
  } catch (Exception $e) {}
  return $t['access_token'];
}

function gcal_event_body($summary, $desc, $date, $time = '', $durMin = 120) {
  $b = ['summary' => $summary, 'description' => $desc];
  if ($time && preg_match('/^\d{2}:\d{2}$/', $time)) {
    $b['start'] = ['dateTime' => $date . 'T' . $time . ':00', 'timeZone' => 'Asia/Jakarta'];
    $b['end'] = ['dateTime' => date('Y-m-d\TH:i:s', strtotime($date . ' ' . $time) + $durMin * 60), 'timeZone' => 'Asia/Jakarta'];
  } else {
    $b['start'] = ['date' => $date];
    $b['end'] = ['date' => date('Y-m-d', strtotime($date . ' +1 day'))];
  }
  return $b;
}

/* Buat / update event. Kembalikan eventId (atau eventId lama bila gagal). */
function gcal_upsert($eventId, $summary, $desc, $date, $time = '', $durMin = 120) {
  try {
    $c = gcal_creds(); if (!$c || !$date) return $eventId;
    $token = gcal_token(); if (!$token) return $eventId;
    $cal = rawurlencode($c['cal']);
    $payload = json_encode(gcal_event_body($summary, $desc, $date, $time, $durMin), JSON_UNESCAPED_UNICODE);
    if ($eventId) {
      $r = gcal_http('PATCH', "https://www.googleapis.com/calendar/v3/calendars/$cal/events/" . rawurlencode($eventId), $token, $payload);
      $j = json_decode($r, true);
      if (!empty($j['id'])) return $j['id'];
    }
    $r = gcal_http('POST', "https://www.googleapis.com/calendar/v3/calendars/$cal/events", $token, $payload);
    $j = json_decode($r, true);
    return !empty($j['id']) ? $j['id'] : $eventId;
  } catch (Exception $e) { return $eventId; }
}

function gcal_delete($eventId) {
  try {
    if (!$eventId) return;
    $c = gcal_creds(); if (!$c) return;
    $token = gcal_token(); if (!$token) return;
    $cal = rawurlencode($c['cal']);
    gcal_http('DELETE', "https://www.googleapis.com/calendar/v3/calendars/$cal/events/" . rawurlencode($eventId), $token);
  } catch (Exception $e) {}
}
