/* ============================================================
   KAISAR WOK — Penjaga login (auth)
   Sertakan <script src="auth.js"></script> di <head> tiap halaman.
   Halaman khusus petinggi: set window.KW_REQUIRE='petinggi' sebelum script ini.
   ============================================================ */
(function(){ document.documentElement.style.visibility = 'hidden'; })();

async function kwLogout(){
  try { await fetch('api.php?action=logout'); } catch(e) {}
  location.replace('login.html');
}

document.addEventListener('DOMContentLoaded', async function(){
  let u;
  try {
    const r = await fetch('api.php?action=me');
    u = await r.json();
  } catch(e) { location.replace('login.html'); return; }

  if (!u || !u.loggedIn) { location.replace('login.html'); return; }

  // Halaman khusus role tertentu (mis. petinggi)
  if (window.KW_REQUIRE && u.role !== window.KW_REQUIRE) {
    document.documentElement.style.visibility = 'visible';
    document.body.innerHTML =
      '<div style="max-width:460px;margin:90px auto;text-align:center;font-family:system-ui;color:#1f2a44;background:#fff;border:1px solid #e3ebf8;border-radius:16px;padding:34px;box-shadow:0 10px 30px rgba(37,99,235,.1)">'
      + '<div style="font-size:42px">🔒</div>'
      + '<h2 style="margin:10px 0 6px;color:#2563eb">Akses Ditolak</h2>'
      + '<p style="color:#6b7890">Halaman ini khusus <b>petinggi</b>. Kamu login sebagai <b>' + u.role + '</b>.</p>'
      + '<a href="index.html" style="display:inline-block;margin-top:14px;background:linear-gradient(180deg,#4f93ff,#2563eb);color:#fff;text-decoration:none;padding:10px 20px;border-radius:10px;font-weight:700">Kembali ke Beranda</a> '
      + '<a href="#" onclick="kwLogout();return false" style="display:inline-block;margin-left:6px;color:#dc2626;text-decoration:underline;padding:10px">Keluar</a>'
      + '</div>';
    return;
  }

  window.CURRENT_USER = u;

  // Sembunyikan akses Petinggi untuk yang bukan petinggi
  if (u.role !== 'petinggi') {
    document.querySelectorAll('a[href="admin.html"]').forEach(a => a.remove());
  }

  // Chip nama + tombol Keluar
  const label = (u.name || (u.role === 'petinggi' ? 'Petinggi' : 'Karyawan'));
  const html = '👤 ' + label + ' <a href="#" onclick="kwLogout();return false">Keluar</a>';
  const tb = document.querySelector('.topbar');
  if (tb) {
    const chip = document.createElement('span');
    chip.className = 'kw-user';
    chip.innerHTML = html;
    tb.appendChild(chip);
  } else {
    const chip = document.createElement('div');
    chip.className = 'kw-user-fixed';
    chip.innerHTML = html;
    document.body.appendChild(chip);
  }

  document.documentElement.style.visibility = 'visible';
  document.dispatchEvent(new CustomEvent('kw-auth', { detail: u }));
});
