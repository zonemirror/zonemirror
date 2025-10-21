<?php
require_once __DIR__ . '/../api/Auth.php';
require_once __DIR__ . '/../api/CloudflareClient.php';

$domain = $_GET['domain'] ?? ($_SERVER['HTTP_HOST'] ?? '');
$cfg = Auth::loadConfigForDomain($domain);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['token'] ?? '';
  $zone_id = $_POST['zone_id'] ?? '';
  $defaults_proxied = isset($_POST['defaults_proxied']);
  $enabled = isset($_POST['enabled']);
  Auth::saveConfigForDomain($domain, [
    'zone_id' => $zone_id,
    'defaults' => ['proxied' => $defaults_proxied],
    'enabled' => $enabled,
    'token' => $token,
  ]);
  $cfg = Auth::loadConfigForDomain($domain);
  $saved = true;
}

$zone_id_val = htmlspecialchars($cfg['zone_id'] ?? '', ENT_QUOTES, 'UTF-8');
$proxied_checked = !empty($cfg['defaults']['proxied']) ? 'checked' : '';
$enabled_checked = !empty($cfg['enabled']) ? 'checked' : '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Cloudflare DNS Sync</title>
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'">
</head>
<body>
  <h2>Cloudflare DNS Sync</h2>
  <?php if (!empty($saved)): ?>
    <div style="color: green;">Settings saved.</div>
  <?php endif; ?>
  <form method="post">
    <fieldset>
      <legend>Connection</legend>
      <label>API Token
        <input type="password" name="token" value="" autocomplete="off" />
      </label>
      <br />
      <label>Zone ID
        <input type="text" name="zone_id" value="<?php echo $zone_id_val; ?>" />
      </label>
      <br />
      <button type="submit" name="test" value="1">Test connection</button>
    </fieldset>

    <fieldset>
      <legend>Defaults</legend>
      <label>
        <input type="checkbox" name="defaults_proxied" <?php echo $proxied_checked; ?> /> Proxy A/AAAA/CNAME by default
      </label>
    </fieldset>

    <fieldset>
      <legend>Sync</legend>
      <label>
        <input type="checkbox" name="enabled" <?php echo $enabled_checked; ?> /> Enabled
      </label>
    </fieldset>

    <button type="submit">Save</button>
  </form>
</body>
</html>
