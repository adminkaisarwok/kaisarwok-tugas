<?php
/* ============================================================
   KAISAR WOK — Feed Kalender (iCal / ICS)
   Semua tugas yang punya tanggal jadi event kalender.
   Langganan di Google Calendar:
     Google Calendar > Other calendars > From URL >
     https://tugas.kaisarwok.com/ical.php
   ============================================================ */
require_once __DIR__ . '/db.php';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="kaisarwok.ics"');

// escape teks sesuai aturan iCal
function esc($s) {
  $s = (string) $s;
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace(';', '\\;', $s);
  $s = str_replace(',', '\\,', $s);
  $s = preg_replace("/\r\n|\n|\r/", '\\n', $s);
  return $s;
}

$CAT  = ['marketing' => 'Marketing', 'staff' => 'Staff'];
$STAT = ['todo' => 'Dikerjakan', 'pending' => 'Menunggu Approval', 'approved' => 'Selesai'];

$pdo  = db();
$rows = $pdo->query("SELECT * FROM tasks WHERE date <> '' ORDER BY date ASC")->fetchAll();

$stamp = gmdate('Ymd\THis\Z');
$out  = "BEGIN:VCALENDAR\r\n";
$out .= "VERSION:2.0\r\n";
$out .= "PRODID:-//Kaisar Wok//Daftar Kerjaan//ID\r\n";
$out .= "CALSCALE:GREGORIAN\r\n";
$out .= "METHOD:PUBLISH\r\n";
$out .= "X-WR-CALNAME:Kaisar Wok - Daftar Kerjaan\r\n";
$out .= "X-WR-TIMEZONE:Asia/Jakarta\r\n";

foreach ($rows as $t) {
  $start = str_replace('-', '', $t['date']);          // YYYY-MM-DD -> YYYYMMDD
  $end   = date('Ymd', strtotime($t['date'] . ' +1 day')); // event seharian (DTEND eksklusif)
  $summary = '[' . ($CAT[$t['category']] ?? $t['category']) . '] ' . $t['title'];
  $desc = 'Oleh: ' . ($t['name'] ?: '-') . "\nStatus: " . ($STAT[$t['status']] ?? $t['status']);
  if (!empty($t['link'])) $desc .= "\nLink: " . $t['link'];

  $out .= "BEGIN:VEVENT\r\n";
  $out .= "UID:task-" . $t['id'] . "@tugas.kaisarwok.com\r\n";
  $out .= "DTSTAMP:" . $stamp . "\r\n";
  $out .= "DTSTART;VALUE=DATE:" . $start . "\r\n";
  $out .= "DTEND;VALUE=DATE:" . $end . "\r\n";
  $out .= "SUMMARY:" . esc($summary) . "\r\n";
  $out .= "DESCRIPTION:" . esc($desc) . "\r\n";
  $out .= "STATUS:" . ($t['status'] === 'approved' ? 'CONFIRMED' : 'TENTATIVE') . "\r\n";
  $out .= "END:VEVENT\r\n";
}

// --- Reservasi tamu jadi event juga ---
$resv = $pdo->query("SELECT * FROM reservations ORDER BY date ASC")->fetchAll();
foreach ($resv as $r) {
  $summary = 'Reservasi: ' . $r['name'] . ($r['pax'] ? ' (' . $r['pax'] . ' org)' : '');
  $desc = '';
  if (!empty($r['phone'])) $desc .= 'HP: ' . $r['phone'] . "\n";
  if (!empty($r['dp']))    $desc .= 'DP: Rp' . number_format((int) $r['dp'], 0, ',', '.') . "\n";
  if (!empty($r['note']))  $desc .= 'Catatan: ' . $r['note'];

  $out .= "BEGIN:VEVENT\r\n";
  $out .= "UID:resv-" . $r['id'] . "@tugas.kaisarwok.com\r\n";
  $out .= "DTSTAMP:" . $stamp . "\r\n";
  if (!empty($r['time']) && preg_match('/^\d{2}:\d{2}$/', $r['time'])) {
    // event berwaktu (2 jam), zona Asia/Jakarta
    $start = str_replace('-', '', $r['date']) . 'T' . str_replace(':', '', $r['time']) . '00';
    $endTs = strtotime($r['date'] . ' ' . $r['time'] . ' +2 hours');
    $end = date('Ymd\THis', $endTs);
    $out .= "DTSTART;TZID=Asia/Jakarta:" . $start . "\r\n";
    $out .= "DTEND;TZID=Asia/Jakarta:" . $end . "\r\n";
  } else {
    $start = str_replace('-', '', $r['date']);
    $end = date('Ymd', strtotime($r['date'] . ' +1 day'));
    $out .= "DTSTART;VALUE=DATE:" . $start . "\r\n";
    $out .= "DTEND;VALUE=DATE:" . $end . "\r\n";
  }
  $out .= "SUMMARY:" . esc($summary) . "\r\n";
  if ($desc !== '') $out .= "DESCRIPTION:" . esc($desc) . "\r\n";
  $out .= "STATUS:CONFIRMED\r\n";
  $out .= "END:VEVENT\r\n";
}

$out .= "END:VCALENDAR\r\n";
echo $out;
