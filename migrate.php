<?php
/**
 * migrate.php — Migration UI + Engine
 *
 * GET  /migrate.php                  → show UI
 * POST /migrate.php?action=run       → start migration (AJAX, spawns background worker)
 * GET  /migrate.php?action=status    → return progress JSON (AJAX)
 * GET  /migrate.php?action=log       → download log as plain text
 *
 * CLI  php migrate.php --background <config.json>  → background worker (spawned internally)
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// ── Background CLI worker ──────────────────────────────────────────────────────
// Spawned by action=run so the migration runs outside the PHP-FPM request,
// avoiding session locks and FPM request timeouts entirely.
if (PHP_SAPI === 'cli') {
    $cfg_file = $argv[2] ?? '';
    if (($argv[1] ?? '') !== '--background' || !$cfg_file || !file_exists($cfg_file)) {
        exit(1);
    }
    $data = json_decode(file_get_contents($cfg_file), true);
    @unlink($cfg_file); // consume immediately

    // Populate session superglobal so cfg_get()/cfg_set() work without a real session
    $_SESSION['cfg'] = $data ?? [];

    $progress = [
        'total'   => 0,
        'done'    => 0,
        'skipped' => 0,
        'errors'  => 0,
        'log'     => [],
        'status'  => 'running',
    ];
    progress_write($progress);
    run_migration($progress);
    exit(0);
}

$action = $_GET['action'] ?? '';

// ── AJAX: tokeninfo diagnostic ────────────────────────────────────────────────
if ($action === 'tokeninfo') {
    $token = cfg_get('access_token', '');
    if (!$token) { echo json_encode(['error' => 'no token in session']); exit; }
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/tokeninfo?access_token=' . urlencode($token));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch); curl_close($ch);
    header('Content-Type: application/json');
    echo $body;
    exit;
}

// ── AJAX: status ──────────────────────────────────────────────────────────────
if ($action === 'status') {
    header('Content-Type: application/json');
    echo json_encode(progress_read());
    exit;
}

// ── AJAX: download log ────────────────────────────────────────────────────────
if ($action === 'log') {
    $data = progress_read();
    $lines = array_reverse($data['log'] ?? []);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="migration-log.txt"');
    echo implode("\n", $lines);
    exit;
}

// ── AJAX: run migration ───────────────────────────────────────────────────────
if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prevent running twice simultaneously
    $current = progress_read();
    if (($current['status'] ?? '') === 'running') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Migration already running']);
        exit;
    }

    cfg_require('access_token', 'client_id', 'client_secret', 'subdomain', 'api_key');

    // Write config to a temp file so the CLI worker can read it without a session
    $cfg_file = tmp_dir() . '/run_' . bin2hex(random_bytes(8)) . '.json';
    file_put_contents($cfg_file, json_encode($_SESSION['cfg'] ?? []));

    // Spawn background PHP CLI process — try multiple methods in order
    // PHP_BINARY under FPM is the FPM daemon binary, not the CLI binary.
    // Find the matching CLI binary by version instead.
    $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $php = is_executable("/usr/bin/php{$ver}") ? "/usr/bin/php{$ver}"
         : (is_executable('/usr/bin/php')      ? '/usr/bin/php'
         : PHP_BINARY);
    $self   = escapeshellarg(__FILE__);
    $arg    = escapeshellarg($cfg_file);
    $errlog = escapeshellarg(tmp_dir() . '/worker.log');
    $cmd    = "{$php} {$self} --background {$arg}";
    $spawn  = 'none';

    if (function_exists('proc_open')) {
        $desc = [['file','/dev/null','r'],['file','/dev/null','w'],['file', tmp_dir() . '/worker.log','w']];
        $p = proc_open("{$cmd} &", $desc, $pipes);
        if ($p !== false) { proc_close($p); $spawn = 'proc_open'; }
    }
    if ($spawn === 'none' && function_exists('popen')) {
        $p = popen("{$cmd} > /dev/null 2>{$errlog} &", 'r');
        if ($p !== false) { pclose($p); $spawn = 'popen'; }
    }
    if ($spawn === 'none' && function_exists('exec')) {
        exec("{$cmd} > /dev/null 2>{$errlog} &");
        $spawn = 'exec';
    }

    http_response_code($spawn !== 'none' ? 202 : 500);
    header('Content-Type: application/json');
    echo json_encode(['started' => $spawn !== 'none', 'spawn' => $spawn]);
    exit;
}

// ── Guard: must be authenticated ──────────────────────────────────────────────
if (!cfg_get('access_token')) {
    header('Location: index.php');
    exit;
}

// ── UI ────────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Photos Migration — Running</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: #1a1a2e;
    color: #e0e0e0;
    font-family: system-ui, sans-serif;
    min-height: 100vh;
    padding: 1.5rem;
  }
  .wrap { max-width: 860px; margin: 0 auto; }
  h1 { color: #4ecca3; font-size: 1.4rem; margin-bottom: 0.3rem; }
  .subtitle { color: #888; font-size: 0.85rem; margin-bottom: 1.8rem; }

  /* Stats row */
  .stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1.4rem;
  }
  .stat {
    background: #16213e;
    border: 1px solid #0f3460;
    border-radius: 8px;
    padding: 0.9rem 1rem;
    text-align: center;
  }
  .stat .val { font-size: 2rem; font-weight: 700; color: #4ecca3; }
  .stat .lbl { font-size: 0.72rem; color: #888; text-transform: uppercase; letter-spacing: 0.06em; margin-top: 0.2rem; }
  .stat.err .val { color: #f87; }
  .stat.skip .val { color: #ffc857; }

  /* Progress bar */
  .progress-wrap {
    background: #0f3460;
    border-radius: 6px;
    height: 14px;
    overflow: hidden;
    margin-bottom: 1.2rem;
  }
  .progress-bar {
    background: #4ecca3;
    height: 100%;
    width: 0%;
    transition: width 0.4s ease;
    border-radius: 6px;
  }

  /* Status badge */
  .status-badge {
    display: inline-block;
    border-radius: 4px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    padding: 0.25rem 0.65rem;
    text-transform: uppercase;
    margin-bottom: 1.2rem;
  }
  .status-idle    { background:#0f3460; color:#aaa; }
  .status-running { background:#1a4a3a; color:#4ecca3; animation: pulse 1.5s infinite; }
  .status-done    { background:#1a4a3a; color:#4ecca3; }
  .status-error   { background:#3d1515; color:#f88; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.6} }

  /* Buttons */
  .btn-row { display: flex; gap: 0.75rem; margin-bottom: 1.4rem; flex-wrap: wrap; }
  button {
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 700;
    padding: 0.6rem 1.4rem;
    transition: opacity 0.2s;
  }
  button:hover:not(:disabled) { opacity: 0.82; }
  button:disabled { opacity: 0.35; cursor: not-allowed; }
  #btn-start { background: #4ecca3; color: #1a1a2e; }
  #btn-stop  { background: #c0392b; color: #fff; }
  #btn-log   { background: #0f3460; color: #aaa; border: 1px solid #1a5276; }
  #btn-reset { background: #0f3460; color: #aaa; border: 1px solid #1a5276; }

  /* Log */
  .log-title {
    color: #4ecca3;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
  }
  #log-box {
    background: #0d1117;
    border: 1px solid #0f3460;
    border-radius: 8px;
    color: #b0c4de;
    font-family: 'Courier New', Courier, monospace;
    font-size: 0.78rem;
    height: 380px;
    overflow-y: auto;
    padding: 0.9rem 1rem;
    white-space: pre-wrap;
    word-break: break-all;
  }
  .log-err  { color: #f87; }
  .log-skip { color: #ffc857; }
  .log-ok   { color: #4ecca3; }

  /* Summary panel */
  #summary {
    display: none;
    background: #16213e;
    border: 1px solid #4ecca3;
    border-radius: 8px;
    padding: 1.2rem 1.4rem;
    margin-bottom: 1.4rem;
  }
  #summary h2 { color: #4ecca3; font-size: 1.1rem; margin-bottom: 0.8rem; }
  #summary ul { list-style: none; line-height: 2; }
  #summary li span { color: #4ecca3; font-weight: 700; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Google Photos &rarr; PixelUnion Migration</h1>
  <p class="subtitle">Instance: <strong><?= htmlspecialchars(cfg_get('subdomain', '')) ?>.pixelunion.eu</strong></p>

  <div id="status-badge" class="status-badge status-idle">Idle</div>

  <div class="stats">
    <div class="stat">
      <div class="val" id="stat-total">—</div>
      <div class="lbl">Total found</div>
    </div>
    <div class="stat">
      <div class="val" id="stat-done">0</div>
      <div class="lbl">Uploaded</div>
    </div>
    <div class="stat skip">
      <div class="val" id="stat-skip">0</div>
      <div class="lbl">Skipped</div>
    </div>
    <div class="stat err">
      <div class="val" id="stat-err">0</div>
      <div class="lbl">Errors</div>
    </div>
  </div>

  <div class="progress-wrap">
    <div class="progress-bar" id="progress-bar"></div>
  </div>

  <div id="summary">
    <h2>Migration Complete</h2>
    <ul>
      <li>Total photos found: <span id="sum-total">—</span></li>
      <li>Successfully uploaded: <span id="sum-done">—</span></li>
      <li>Skipped (duplicates): <span id="sum-skip">—</span></li>
      <li>Errors: <span id="sum-err">—</span></li>
    </ul>
  </div>

  <div class="btn-row">
    <button id="btn-start" onclick="startMigration()">&#9654; Start Migration</button>
    <button id="btn-stop" onclick="stopMigration()" disabled>&#9632; Stop</button>
    <button id="btn-log" onclick="downloadLog()" disabled>&#8595; Download Log</button>
    <button id="btn-reset" onclick="resetAndGoBack()">&#8592; New Migration</button>
  </div>

  <div class="log-title">Live Log</div>
  <div id="log-box">Waiting to start&hellip;</div>
</div>

<script>
'use strict';

let pollInterval = null;
let stopRequested = false;
let lastLogCount  = 0;

function basePath() {
  return window.location.pathname;
}

async function startMigration() {
  stopRequested = false;
  document.getElementById('btn-start').disabled = true;
  document.getElementById('btn-stop').disabled  = false;
  document.getElementById('btn-log').disabled   = true;
  document.getElementById('summary').style.display = 'none';
  setStatus('running');

  try {
    const r = await fetch(basePath() + '?action=run', { method: 'POST' });
    // response comes back quickly (engine runs synchronously server-side)
    // polling will pick up updates
  } catch (e) {
    logLine('[ERROR] Could not reach server: ' + e.message, 'err');
  }

  // Start polling regardless — the engine may already be running
  if (pollInterval) clearInterval(pollInterval);
  pollInterval = setInterval(pollStatus, 2000);
  pollStatus();
}

async function pollStatus() {
  try {
    const r = await fetch(basePath() + '?action=status');
    if (!r.ok) return;
    const d = await r.json();
    updateUI(d);

    if (d.status === 'done' || d.status === 'error') {
      clearInterval(pollInterval);
      pollInterval = null;
      document.getElementById('btn-start').disabled = false;
      document.getElementById('btn-stop').disabled  = true;
      document.getElementById('btn-log').disabled   = false;
      if (d.status === 'done') showSummary(d);
    }
  } catch (e) {
    // Network blip — keep polling
  }
}

function updateUI(d) {
  const total   = d.total   || 0;
  const done    = d.done    || 0;
  const skipped = d.skipped || 0;
  const errors  = d.errors  || 0;
  const status  = d.status  || 'idle';
  const log     = d.log     || [];

  document.getElementById('stat-total').textContent = total > 0 ? total : '—';
  document.getElementById('stat-done').textContent  = done;
  document.getElementById('stat-skip').textContent  = skipped;
  document.getElementById('stat-err').textContent   = errors;

  const pct = total > 0 ? Math.min(100, Math.round((done + skipped + errors) / total * 100)) : 0;
  document.getElementById('progress-bar').style.width = pct + '%';

  setStatus(status);
  renderLog(log);
}

function setStatus(status) {
  const el = document.getElementById('status-badge');
  el.className = 'status-badge status-' + status;
  const labels = { idle:'Idle', running:'Running…', done:'Complete', error:'Error' };
  el.textContent = labels[status] || status;
}

function renderLog(entries) {
  if (!entries || entries.length === lastLogCount) return;
  lastLogCount = entries.length;
  const box = document.getElementById('log-box');
  box.innerHTML = entries.map(line => {
    let cls = '';
    if (/ERROR|FAIL|error|fail/i.test(line)) cls = 'log-err';
    else if (/skip|duplicate|409/i.test(line)) cls = 'log-skip';
    else if (/OK|upload|done|complet/i.test(line)) cls = 'log-ok';
    const safe = line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return cls ? `<span class="${cls}">${safe}</span>` : safe;
  }).join('\n');
  box.scrollTop = 0; // newest first
}

function logLine(msg, cls) {
  const box = document.getElementById('log-box');
  const safe = msg.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  box.innerHTML = (cls ? `<span class="log-${cls}">${safe}</span>` : safe) + '\n' + box.innerHTML;
}

function showSummary(d) {
  document.getElementById('summary').style.display = 'block';
  document.getElementById('sum-total').textContent = d.total   || 0;
  document.getElementById('sum-done').textContent  = d.done    || 0;
  document.getElementById('sum-skip').textContent  = d.skipped || 0;
  document.getElementById('sum-err').textContent   = d.errors  || 0;
}

function stopMigration() {
  stopRequested = true;
  // Write a stop flag file; the PHP engine checks for it
  fetch(basePath() + '?action=stop', { method: 'POST' }).catch(() => {});
  document.getElementById('btn-stop').disabled = true;
  logLine('[INFO] Stop requested — finishing current photo…', '');
}

function downloadLog() {
  window.location.href = basePath() + '?action=log';
}

function resetAndGoBack() {
  if (!confirm('Return to setup? This will clear all session data.')) return;
  window.location.href = 'index.php?reset=1';
}

// Auto-resume polling if migration was already running when page loaded
window.addEventListener('DOMContentLoaded', async () => {
  const r = await fetch(basePath() + '?action=status').catch(() => null);
  if (!r || !r.ok) return;
  const d = await r.json();
  if (d.status === 'running') {
    document.getElementById('btn-start').disabled = true;
    document.getElementById('btn-stop').disabled  = false;
    pollInterval = setInterval(pollStatus, 2000);
    updateUI(d);
  } else if (d.status === 'done' || d.status === 'error') {
    updateUI(d);
    document.getElementById('btn-log').disabled = false;
    if (d.status === 'done') showSummary(d);
  }
});
</script>
</body>
</html>
<?php

// ── Stop action ───────────────────────────────────────────────────────────────
if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents(tmp_dir() . '/stop.flag', '1');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ── Reset / new migration ─────────────────────────────────────────────────────
if (isset($_GET['reset'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════════
// MIGRATION ENGINE
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Main migration runner — called inline after the AJAX action=run POST.
 * Writes progress to tmp/progress.json which is read by action=status.
 */
function run_migration(array &$progress): void
{
    // Raise limits for long-running migration
    set_time_limit(0);
    ignore_user_abort(true);

    $tmp      = tmp_dir();
    $stop_flag = $tmp . '/stop.flag';

    // Clear any leftover stop flag
    if (file_exists($stop_flag)) {
        unlink($stop_flag);
    }

    $access_token  = cfg_get('access_token');
    $refresh_token = cfg_get('refresh_token');
    $client_id     = cfg_get('client_id');
    $client_secret = cfg_get('client_secret');
    $subdomain     = cfg_get('subdomain');
    $api_key       = cfg_get('api_key');
    $base_url      = pixelunion_base($subdomain);

    progress_log($progress, "Migration started. Target: {$base_url}");
    progress_write($progress);

    $page_token   = '';
    $total_fetched = 0;

    // ── Paginate through Google Photos ────────────────────────────────────────
    do {
        if (file_exists($stop_flag)) {
            progress_log($progress, 'Stop flag detected — halting migration.');
            $progress['status'] = 'done';
            progress_write($progress);
            @unlink($stop_flag);
            return;
        }

        $url = 'https://photoslibrary.googleapis.com/v1/mediaItems?pageSize=100';
        if ($page_token) {
            $url .= '&pageToken=' . urlencode($page_token);
        }

        [$items_resp, $http] = google_get($url, $access_token);

        // Token expired — try refresh once
        if ($http === 401) {
            progress_log($progress, 'Access token expired, refreshing…');
            $new_token = refresh_access_token($refresh_token, $client_id, $client_secret);
            if (!$new_token) {
                progress_log($progress, 'ERROR: Could not refresh access token. Stopping.');
                $progress['status'] = 'error';
                progress_write($progress);
                return;
            }
            $access_token = $new_token;
            cfg_set('access_token', $new_token);
            [$items_resp, $http] = google_get($url, $access_token);
        }

        if ($http !== 200 || !is_array($items_resp)) {
            $detail = $items_resp['error']['message'] ?? $items_resp['error']['status'] ?? json_encode($items_resp);
            progress_log($progress, "ERROR: Google Photos API returned HTTP {$http}: {$detail}");
            $progress['status'] = 'error';
            progress_write($progress);
            return;
        }

        $items      = $items_resp['mediaItems'] ?? [];
        $page_token = $items_resp['nextPageToken'] ?? '';

        // Update total estimate — Google doesn't give a grand total, so we
        // accumulate as we go; it reads as "at least N" until last page.
        $total_fetched += count($items);
        $progress['total'] = $total_fetched + ($page_token ? 1 : 0); // +1 signals more coming
        progress_write($progress);

        // ── Process each item ─────────────────────────────────────────────────
        foreach ($items as $item) {
            if (file_exists($stop_flag)) {
                progress_log($progress, 'Stop flag detected — halting migration.');
                $progress['status'] = 'done';
                progress_write($progress);
                @unlink($stop_flag);
                return;
            }

            $id            = $item['id']         ?? '';
            $filename      = $item['filename']   ?? ($id ?: 'unknown');
            $creation_time = $item['mediaMetadata']['creationTime'] ?? date('c');
            $base_url_item = ($item['baseUrl'] ?? '') . '=d';
            $mime_type     = $item['mimeType'] ?? 'image/jpeg';
            $ext           = mime_to_ext($mime_type);

            if (!$id || !$item['baseUrl']) {
                progress_log($progress, "SKIP: item missing id or baseUrl (filename: {$filename})");
                $progress['skipped']++;
                progress_write($progress);
                continue;
            }

            // ── Download from Google ──────────────────────────────────────────
            $tmp_file = $tmp . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id) . $ext;
            [$dl_ok, $dl_http] = download_file($base_url_item, $tmp_file, $access_token);

            if (!$dl_ok) {
                // May need token refresh for media download too
                if ($dl_http === 401) {
                    $new_token = refresh_access_token($refresh_token, $client_id, $client_secret);
                    if ($new_token) {
                        $access_token = $new_token;
                        cfg_set('access_token', $new_token);
                        [$dl_ok, $dl_http] = download_file($base_url_item, $tmp_file, $access_token);
                    }
                }
                if (!$dl_ok) {
                    progress_log($progress, "ERROR: Download failed (HTTP {$dl_http}) for {$filename}");
                    $progress['errors']++;
                    progress_write($progress);
                    if (file_exists($tmp_file)) @unlink($tmp_file);
                    continue;
                }
            }

            // ── Upload to PixelUnion ──────────────────────────────────────────
            $checksum = sha1_file($tmp_file);
            [$up_ok, $up_http, $up_body] = upload_to_immich(
                $base_url, $api_key, $tmp_file, $id, $filename, $creation_time, $ext, $checksum
            );

            @unlink($tmp_file); // Always clean up tmp file

            if ($up_http === 200 || $up_http === 201) {
                progress_log($progress, "OK: Uploaded {$filename}");
                $progress['done']++;
            } elseif ($up_http === 409) {
                progress_log($progress, "SKIP (duplicate): {$filename}");
                $progress['skipped']++;
            } elseif ($up_http === 404) {
                progress_log($progress, "ERROR 404 on upload endpoint {$base_url}/api/assets — check subdomain. Stopping.");
                $progress['errors']++;
                $progress['status'] = 'error';
                progress_write($progress);
                return;
            } else {
                progress_log($progress, "ERROR: Upload failed (HTTP {$up_http}) for {$filename}. Response: " . substr((string)$up_body, 0, 200));
                $progress['errors']++;
            }

            progress_write($progress);
        }

    } while ($page_token);

    // Correct final total now that we've seen everything
    $progress['total']  = $total_fetched;
    $progress['status'] = 'done';
    progress_log($progress, sprintf(
        'Migration complete. Total: %d | Uploaded: %d | Skipped: %d | Errors: %d',
        $total_fetched,
        $progress['done'],
        $progress['skipped'],
        $progress['errors']
    ));
    progress_write($progress);

    // Clean up tmp directory (only migration temp files)
    foreach (glob($tmp . '/*') as $f) {
        if (basename($f) !== 'progress.json') {
            @unlink($f);
        }
    }
}

// ── cURL helpers ──────────────────────────────────────────────────────────────

/**
 * Make an authenticated GET request to Google APIs.
 * Returns [decoded_json_or_null, http_code].
 */
function google_get(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = ($body !== false) ? json_decode($body, true) : null;
    return [$decoded, $http];
}

/**
 * Download a file to disk.
 * Returns [success_bool, http_code].
 */
function download_file(string $url, string $dest, string $token): array
{
    $fh = fopen($dest, 'wb');
    if (!$fh) {
        return [false, 0];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 300, // up to 5 min per file
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($err || $http !== 200) {
        return [false, $http ?: 0];
    }
    return [true, 200];
}

/**
 * Upload a file to PixelUnion / Immich.
 * Returns [success_bool, http_code, response_body].
 */
function upload_to_immich(
    string $base_url,
    string $api_key,
    string $file_path,
    string $device_asset_id,
    string $filename,
    string $created_at,
    string $ext,
    string $checksum
): array {
    $clean_ext = ltrim($ext, '.');

    $post = [
        'assetData'     => new CURLFile($file_path, mime_from_ext($ext), $filename),
        'deviceAssetId' => $device_asset_id,
        'deviceId'      => 'google-photos-migration',
        'fileCreatedAt' => $created_at,
        'fileModifiedAt'=> $created_at,
        'fileExtension' => $clean_ext,
    ];

    $ch = curl_init($base_url . '/api/assets');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_HTTPHEADER     => [
            "x-api-key: {$api_key}",
            "x-immich-checksum: {$checksum}",
        ],
        CURLOPT_TIMEOUT        => 300,
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = in_array($http, [200, 201, 409], true);
    return [$ok, $http, $body];
}

/**
 * Refresh the Google access token.
 * Returns new access_token string or null on failure.
 */
function refresh_access_token(string $refresh_token, string $client_id, string $client_secret): ?string
{
    if (!$refresh_token) {
        return null;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        return null;
    }

    $resp = json_decode($body, true);
    return $resp['access_token'] ?? null;
}

// ── MIME helpers ──────────────────────────────────────────────────────────────

function mime_to_ext(string $mime): string
{
    $map = [
        'image/jpeg'      => '.jpg',
        'image/jpg'       => '.jpg',
        'image/png'       => '.png',
        'image/gif'       => '.gif',
        'image/webp'      => '.webp',
        'image/heic'      => '.heic',
        'image/heif'      => '.heif',
        'image/tiff'      => '.tiff',
        'image/bmp'       => '.bmp',
        'video/mp4'       => '.mp4',
        'video/quicktime' => '.mov',
        'video/x-msvideo' => '.avi',
        'video/webm'      => '.webm',
        'video/3gpp'      => '.3gp',
    ];
    return $map[strtolower($mime)] ?? '.jpg';
}

function mime_from_ext(string $ext): string
{
    $map = [
        '.jpg'  => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.png'  => 'image/png',
        '.gif'  => 'image/gif',
        '.webp' => 'image/webp',
        '.heic' => 'image/heic',
        '.heif' => 'image/heif',
        '.tiff' => 'image/tiff',
        '.bmp'  => 'image/bmp',
        '.mp4'  => 'video/mp4',
        '.mov'  => 'video/quicktime',
        '.avi'  => 'video/x-msvideo',
        '.webm' => 'video/webm',
        '.3gp'  => 'video/3gpp',
    ];
    return $map[strtolower($ext)] ?? 'image/jpeg';
}
