<?php
/**
 * index.php — Main UI + Google OAuth2 start
 *
 * lighttpd alias config needed:
 * alias.url += ("/migration" => "/var/www/migration")
 * $HTTP["url"] =~ "^/migration/tmp/" { url.access-deny = ("") }
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id     = trim($_POST['client_id']     ?? '');
    $client_secret = trim($_POST['client_secret'] ?? '');
    $subdomain     = strtolower(trim($_POST['subdomain'] ?? ''));
    $api_key       = trim($_POST['api_key']       ?? '');

    if (!$client_id || !$client_secret || !$subdomain || !$api_key) {
        $error = 'All fields are required.';
    } elseif (!subdomain_valid($subdomain)) {
        $error = 'PixelUnion subdomain may only contain lowercase letters, digits, and hyphens.';
    } else {
        cfg_set('client_id',     $client_id);
        cfg_set('client_secret', $client_secret);
        cfg_set('subdomain',     $subdomain);
        cfg_set('api_key',       $api_key);

        // Build redirect_uri pointing to callback.php in the same directory
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                    ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'];
        $base     = dirname($_SERVER['SCRIPT_NAME']);
        $base     = rtrim($base, '/');
        $callback = $scheme . '://' . $host . $base . '/callback.php';
        cfg_set('redirect_uri', $callback);

        $state = bin2hex(random_bytes(16));
        cfg_set('oauth_state', $state);

        $params = http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => $callback,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/photoslibrary.readonly',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);

        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
        exit;
    }
}

$prefill = [
    'client_id'  => htmlspecialchars(cfg_get('client_id', '')),
    'subdomain'  => htmlspecialchars(cfg_get('subdomain', '')),
];

// Compute the exact callback URL this server will send to Google
$_scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
             ? 'https' : 'http';
$_host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_base     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$_callback_url = htmlspecialchars($_scheme . '://' . $_host . $_base . '/callback.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Photos Migration — Setup</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: #1a1a2e;
    color: #e0e0e0;
    font-family: system-ui, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
  }
  .card {
    background: #16213e;
    border: 1px solid #0f3460;
    border-radius: 10px;
    padding: 2.5rem 2rem;
    width: 100%;
    max-width: 520px;
  }
  h1 {
    color: #4ecca3;
    font-size: 1.5rem;
    margin-bottom: 0.4rem;
  }
  .subtitle {
    color: #888;
    font-size: 0.875rem;
    margin-bottom: 2rem;
  }
  label {
    display: block;
    font-size: 0.8rem;
    color: #aaa;
    margin-bottom: 0.3rem;
    margin-top: 1.1rem;
  }
  input[type=text], input[type=password] {
    width: 100%;
    background: #0f3460;
    border: 1px solid #1a5276;
    border-radius: 6px;
    color: #e0e0e0;
    font-size: 0.95rem;
    padding: 0.55rem 0.8rem;
    outline: none;
    transition: border-color 0.2s;
  }
  input:focus { border-color: #4ecca3; }
  .hint {
    font-size: 0.75rem;
    color: #667;
    margin-top: 0.25rem;
  }
  .error {
    background: #3d1515;
    border: 1px solid #a33;
    border-radius: 6px;
    color: #f88;
    font-size: 0.875rem;
    padding: 0.7rem 1rem;
    margin-top: 1.2rem;
  }
  button[type=submit] {
    margin-top: 1.8rem;
    width: 100%;
    background: #4ecca3;
    border: none;
    border-radius: 6px;
    color: #1a1a2e;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 700;
    padding: 0.75rem;
    transition: opacity 0.2s;
  }
  button[type=submit]:hover { opacity: 0.85; }
  .section-title {
    color: #4ecca3;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-top: 1.8rem;
    margin-bottom: -0.4rem;
    border-bottom: 1px solid #0f3460;
    padding-bottom: 0.4rem;
  }
  .info-box {
    background: #0d2137;
    border-left: 3px solid #4ecca3;
    border-radius: 4px;
    color: #aac;
    font-size: 0.78rem;
    padding: 0.7rem 0.9rem;
    margin-top: 1.5rem;
    line-height: 1.6;
  }
  .info-box code {
    color: #4ecca3;
    font-family: monospace;
  }
</style>
</head>
<body>
<div class="card">
  <h1>Google Photos → PixelUnion</h1>
  <p class="subtitle">Migration Bridge &mdash; enter your credentials to begin</p>

  <form method="POST" action="">

    <div class="section-title">Google OAuth2</div>

    <label for="client_id">Client ID</label>
    <input type="text" id="client_id" name="client_id"
           value="<?= $prefill['client_id'] ?>"
           placeholder="xxxxxxxxxxxx.apps.googleusercontent.com" required>

    <label for="client_secret">Client Secret</label>
    <input type="password" id="client_secret" name="client_secret"
           placeholder="GOCSPX-…" required>
    <p class="hint">Create an OAuth 2.0 Client ID in Google Cloud Console with the Photos Library API enabled.</p>

    <div class="section-title">PixelUnion / Immich</div>

    <label for="subdomain">Subdomain</label>
    <input type="text" id="subdomain" name="subdomain"
           value="<?= $prefill['subdomain'] ?>"
           placeholder="lars" required pattern="[a-z0-9\-]+">
    <p class="hint">Your instance will be contacted at <code>https://&lt;subdomain&gt;.pixelunion.eu</code></p>

    <label for="api_key">API Key</label>
    <input type="password" id="api_key" name="api_key"
           placeholder="PixelUnion / Immich API key" required>

    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <button type="submit">Authorise with Google &amp; Start &rarr;</button>
  </form>

  <div class="info-box">
    <strong>Callback URL</strong> to register in Google Cloud Console:<br>
    <code><?= $_callback_url ?></code>
  </div>
</div>
</body>
</html>
