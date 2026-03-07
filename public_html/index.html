<?php
require_once __DIR__ . '/../inc/security.php';
require_admin();
$csrf = csrf_token();
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — posttension.eu</title>
<style>
  body{margin:0;font-family:system-ui;background:#0b0f14;color:#e9f0f7}
  .wrap{max-width:1200px;margin:0 auto;padding:18px}
  .card{border:1px solid rgba(255,255,255,.12);border-radius:16px;background:rgba(16,24,38,.72);padding:16px;margin:12px 0}
  .muted{color:#9db0c6}
  .btn{border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#e9f0f7;padding:8px 10px;cursor:pointer}
  .btn.primary{border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.12)}
  .btn.danger{border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.12)}
  table{width:100%;border-collapse:collapse}
  th,td{border-bottom:1px solid rgba(255,255,255,.12);padding:10px;text-align:left;font-size:13px;vertical-align:top}
  th{color:#9db0c6;font-weight:600}
  code{color:#cbd5e1}
</style>
</head><body>
<div class="wrap">
  <div class="card">
    <h1 style="margin:0 0 6px">Admin</h1>
    <div class="muted">Approve / reject account requests • View approved users • View waiting list</div>
    <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn" href="/admin/logout.php">Sign out</a>
      <span class="muted">CSRF: <code><?= htmlspecialchars($csrf) ?></code></span>
    </div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 10px">Pending requests</h2>
    <div id="pending"></div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 10px">Approved users</h2>
    <div id="approved"></div>
  </div>

  <div class="card">
    <h2 style="margin:0 0 10px">Waiting list</h2>
    <div id="waitlist"></div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

async function api(path, body){
  const res = await fetch(path, {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify(body)
  });
  return await res.json();
}

function esc(s){ return String(s ?? "").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;"); }

async function loadAll(){
  const data = await api("/api/admin/list.php", { csrf: CSRF });
  if(!data.ok){
    document.getElementById("pending").innerHTML = `<div class="muted">Error loading data.</div>`;
    return;
  }

  renderTable("pending", data.pending, true);
  renderTable("approved", data.approved, false);
  renderTable("waitlist", data.waitlist, false);
}

function renderTable(targetId, rows, withActions){
  if(!rows || !rows.length){
    document.getElementById(targetId).innerHTML = `<div class="muted">No entries.</div>`;
    return;
  }

  let html = `<table><thead><tr>`;
  const cols = Object.keys(rows[0]);
  cols.forEach(c => html += `<th>${esc(c)}</th>`);
  if(withActions) html += `<th>actions</th>`;
  html += `</tr></thead><tbody>`;

  rows.forEach(r => {
    html += `<tr>`;
    cols.forEach(c => html += `<td>${esc(r[c])}</td>`);
    if(withActions){
      html += `<td>
        <button class="btn primary" onclick="approve(${r.id})">Approve</button>
        <button class="btn danger" onclick="reject(${r.id})">Reject</button>
      </td>`;
    }
    html += `</tr>`;
  });

  html += `</tbody></table>`;
  document.getElementById(targetId).innerHTML = html;
}

async function approve(id){
  const out = await api("/api/admin/approve.php", { csrf: CSRF, id });
  alert(out.ok ? "Approved." : ("Error: " + out.error));
  loadAll();
}

async function reject(id){
  const note = prompt("Optional admin note (reason):") || "";
  const out = await api("/api/admin/reject.php", { csrf: CSRF, id, note });
  alert(out.ok ? "Rejected." : ("Error: " + out.error));
  loadAll();
}

loadAll();
</script>
</body></html>
