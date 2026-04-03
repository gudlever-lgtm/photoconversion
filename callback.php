<?php
/**
 * callback.php — Google OAuth2 callback
 * Exchanges auth code for tokens, stores in session, redirects to migrate.php
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

// ── CSRF / state check ────────────────────────────────────────────────────────
$state_expected = cfg_get('oauth_state', '');
$state_received = $_GET['state'] ?? '';

if (!$state_expected || !hash_equals($state_expected, $state_received)) {
    render_error('Invalid OAuth state. Please <a href="index.php">start over</a>.');
}

// ── Google error response ─────────────────────────────────────────────────────
if (isset($_GET['error'])) {
    $msg = htmlspecialchars($_GET['error']);
    render_error("Google returned an error: <strong>{$msg}</strong>. Please <a href=\"index.php\">start over</a>.");
}

$code = trim($_GET['code'] ?? '');
if (!$code) {
    render_error('No authorisation code received. Please <a href="index.php">start over</a>.');
}

// ── Exchange code for tokens ───────────────────────────────────────────────────
cfg_require('client_id', 'client_secret', 'redirect_uri');

$post = [
    'code'          => $code,
    'client_id'     => cfg_get('client_id'),
    'client_secret' => cfg_get('client_secret'),
    'redirect_uri'  => cfg_get('redirect_uri'),
    'grant_type'    => 'authorization_code',
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($post),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 30,
]);

$body    = curl_exec($ch);
$http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    render_error('Network error while contacting Google: ' . htmlspecialchars($curl_err));
}

$resp = json_decode($body, true);

if ($http !== 200 || empty($resp['access_token'])) {
    $detail = $resp['error_description'] ?? ($resp['error'] ?? "HTTP $http");
    render_error('Token exchange failed: ' . htmlspecialchars($detail) . '. Please <a href="index.php">start over</a>.');
}

cfg_set('access_token',  $resp['access_token']);
cfg_set('refresh_token', $resp['refresh_token'] ?? '');
cfg_set('token_type',    $resp['token_type'] ?? 'Bearer');

// Clear the one-time state
cfg_set('oauth_state', null);

header('Location: migrate.php');
exit;

// ── Helper ────────────────────────────────────────────────────────────────────
function render_error(string $msg): never
{
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OAuth Error</title>
<style>
  body { background:#1a1a2e; color:#e0e0e0; font-family:system-ui,sans-serif;
         display:flex; align-items:center; justify-content:center; min-height:100vh; padding:1.5rem; }
  .card { background:#16213e; border:1px solid #a33; border-radius:10px;
          padding:2rem; max-width:480px; width:100%; }
  h2 { color:#f88; margin-bottom:1rem; }
  p  { line-height:1.6; }
  a  { color:#4ecca3; }
</style>
</head>
<body>
<div class="card">
  <h2>Authentication Error</h2>
  <p><?= $msg ?></p>
</div>
</body>
</html>
<?php
    exit;
}
