<?php

declare(strict_types=1);

/**
 * Token paste-back form. Included from both the first-time hero
 * ("I already created a token") and the recurring "Connect another"
 * disclosure. Renders nothing on its own; expects $h() and $tokensVm
 * to be in scope from the parent index.live.php.
 *
 * @var callable(string):string $h
 * @var array<string, mixed>    $tokensVm
 */
?>
<ol class="step-list">
  <li>In the Cloudflare tab that opened, scroll to <strong>Create Token</strong> and click it. The permissions are pre-filled.</li>
  <li>Cloudflare will show the token on one screen only. Copy it.</li>
  <li>Paste it below and give this connection a friendly name.</li>
</ol>

<form method="post" autocomplete="off" style="margin-top: 1rem;">
  <input type="hidden" name="form" value="tokens">
  <input type="hidden" name="csrf" value="<?= $h($tokensVm['csrf']) ?>">
  <input type="hidden" name="action" value="add">

  <label style="margin-bottom: 0.75rem;">
    Friendly name
    <input type="text" name="name" placeholder="Main Cloudflare account" required>
  </label>

  <label style="margin-bottom: 0.75rem;">
    Cloudflare token
    <input type="password" name="token" placeholder="Paste the token Cloudflare just generated" required autocomplete="new-password">
  </label>

  <button type="submit" style="margin-top: 0.5rem;">Connect</button>
</form>
