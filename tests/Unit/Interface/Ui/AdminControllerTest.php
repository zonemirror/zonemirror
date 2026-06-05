<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Ui;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Mapping\EmailDnsPolicyComposer;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;
use ZoneMirror\Interface\Ui\AdminController;
use ZoneMirror\Interface\Ui\Csrf;

/**
 * Covers the WHM admin view-model controller. Each test runs in its own
 * process so the per-session CSRF state (held in $_SESSION) and the env
 * overrides for Paths can't leak between tests, and so the controller
 * always sees a fresh system tree on disk.
 */
final class AdminControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-admin-ctl-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->tmpDir);
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
        $this->rmrf($this->tmpDir);
    }

    #[RunInSeparateProcess]
    public function testHandleGetReturnsDefaultsForEmptySystemTree(): void
    {
        $vm = $this->makeController()->handle('GET', []);

        self::assertFalse($vm['saved']);
        self::assertSame([], $vm['errors']);
        self::assertNotSame('', $vm['csrf']);
        // Fresh-install defaults: not proxied, TTL 300, auto-TTL on, dry-run on,
        // empty allowlist (mode 'list' because allowed_users is []), rps 5.
        self::assertFalse($vm['defaults_proxied']);
        self::assertSame(300, $vm['default_ttl']);
        self::assertTrue($vm['auto_ttl']);
        self::assertSame('list', $vm['allowed_users_mode']);
        self::assertSame('', $vm['allowed_users_list']);
        self::assertSame(5, $vm['rate_limit_rps']);
        self::assertTrue($vm['dry_run']);
        self::assertSame('', $vm['dmarc_template']);
        self::assertSame('', $vm['spf_extras']);
        self::assertFalse($vm['dmarc']['enabled']);
        self::assertSame('none', $vm['dmarc']['policy']);
        self::assertSame([], $vm['spf_presets']);
        self::assertSame('', $vm['spf_custom']);
        self::assertSame([], $vm['enrolled']);
        self::assertNotSame('', $vm['installed_version']);
    }

    #[RunInSeparateProcess]
    public function testHandleGetExposesEveryPresetOptionWithLabelAndMechanism(): void
    {
        $vm = $this->makeController()->handle('GET', []);

        self::assertCount(count(EmailDnsPolicyComposer::SPF_PRESETS), $vm['spf_preset_options']);
        foreach (EmailDnsPolicyComposer::SPF_PRESETS as $slug => $mechanism) {
            self::assertArrayHasKey($slug, $vm['spf_preset_options']);
            self::assertSame($mechanism, $vm['spf_preset_options'][$slug]['mechanism']);
            self::assertSame(
                EmailDnsPolicyComposer::PRESET_LABELS[$slug],
                $vm['spf_preset_options'][$slug]['label'],
            );
        }
    }

    #[RunInSeparateProcess]
    public function testHandlePostWithMissingCsrfFlagsErrorAndDoesNotSave(): void
    {
        $ctl = $this->makeController();
        $vm = $ctl->handle('POST', ['default_ttl' => '999', 'dry_run' => '1']);

        self::assertFalse($vm['saved']);
        self::assertContains('Invalid CSRF token.', $vm['errors']);
        // Untouched: defaults stand because save() was never called.
        self::assertSame(300, $vm['default_ttl']);
        self::assertTrue($vm['dry_run']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostWithInvalidCsrfFlagsErrorAndDoesNotSave(): void
    {
        // Prime a token so verify() has something to compare against.
        Csrf::token();
        $ctl = $this->makeController();
        $vm = $ctl->handle('POST', ['csrf' => 'not-the-real-token', 'default_ttl' => '999']);

        self::assertFalse($vm['saved']);
        self::assertContains('Invalid CSRF token.', $vm['errors']);
        self::assertSame(300, $vm['default_ttl']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostWithValidCsrfSavesAndReloadsFreshState(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'defaults_proxied' => '1',
            'default_ttl' => '450',
            'auto_ttl' => '1',
            'allowed_users_mode' => 'list',
            'allowed_users_list' => "alice\nbob",
            'rate_limit_rps' => '7',
            'dry_run' => '',
            // No dmarc / spf fields → builder stays disabled, template empty.
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame([], $vm['errors']);
        self::assertTrue($vm['defaults_proxied']);
        self::assertSame(450, $vm['default_ttl']);
        self::assertTrue($vm['auto_ttl']);
        self::assertSame('list', $vm['allowed_users_mode']);
        self::assertSame("alice\nbob", $vm['allowed_users_list']);
        self::assertSame(7, $vm['rate_limit_rps']);
        self::assertFalse($vm['dry_run']);
        self::assertSame('', $vm['dmarc_template']);

        // The new CSRF token returned for the next request must differ from
        // the one we just spent — Csrf::verify rotates on success.
        self::assertNotSame($token, $vm['csrf']);
        self::assertNotSame('', $vm['csrf']);

        // The save was persisted: a fresh storage instance must see it.
        $reloaded = (new SystemConfigStorage())->load();
        self::assertSame(['alice', 'bob'], $reloaded['allowed_users']);
        self::assertSame(450, $reloaded['defaults']['ttl']);
        self::assertSame(7, $reloaded['rate_limit_rps']);
        self::assertFalse($reloaded['dry_run']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostAllowedUsersAllStoresLiteralSentinel(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'allowed_users_mode' => 'all',
            // List should be ignored when mode=all.
            'allowed_users_list' => "alice\nbob",
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame('all', $vm['allowed_users_mode']);
        // When mode is 'all', the rendered list field is blank.
        self::assertSame('', $vm['allowed_users_list']);
        self::assertSame('all', (new SystemConfigStorage())->load()['allowed_users']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostNormalisesAllowedUsersAndDropsBlanksAndPunctuation(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'allowed_users_mode' => 'list',
            // Mixed whitespace, blanks, uppercase, illegal chars, dupes are
            // kept as-typed (the controller doesn't dedupe), but invalid
            // chars are stripped, case lowered, and blank lines removed.
            'allowed_users_list' => " Alice \n\n  BOB!!! \n\nc.h_a-r_l-i.e \n   \n",
        ]);

        self::assertTrue($vm['saved']);
        $reloaded = (new SystemConfigStorage())->load();
        self::assertSame(['alice', 'bob', 'ch_a-r_l-ie'], $reloaded['allowed_users']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostClampsTtlAndRateLimitToTheirAllowedRanges(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'default_ttl' => '5',           // below floor of 60
            'rate_limit_rps' => '99999',    // above ceiling of 50
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame(60, $vm['default_ttl']);
        self::assertSame(50, $vm['rate_limit_rps']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostClampsRateLimitFloorWhenZero(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'rate_limit_rps' => '0',
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame(1, $vm['rate_limit_rps']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostBuildsDmarcTemplateFromBuilderFields(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'dmarc_enable' => '1',
            'dmarc_policy' => 'quarantine',
            'dmarc_email' => '  ops@example.com  ',
            'dmarc_rua' => '1',
            // dmarc_ruf intentionally absent → false
            'dmarc_sp' => 'reject',
            'dmarc_pct' => '50',
        ]);

        self::assertTrue($vm['saved']);
        self::assertTrue($vm['dmarc']['enabled']);
        self::assertSame('quarantine', $vm['dmarc']['policy']);
        self::assertSame('ops@example.com', $vm['dmarc']['email']);
        self::assertTrue($vm['dmarc']['rua']);
        self::assertFalse($vm['dmarc']['ruf']);
        self::assertSame('reject', $vm['dmarc']['sp']);
        self::assertSame(50, $vm['dmarc']['pct']);
        self::assertSame(
            'v=DMARC1; p=quarantine; rua=mailto:ops@example.com; sp=reject; pct=50',
            $vm['dmarc_template'],
        );
    }

    #[RunInSeparateProcess]
    public function testHandlePostCustomDmarcTakesPrecedenceOverBuilderFields(): void
    {
        $token = Csrf::token();

        $custom = 'v=DMARC1; p=reject; rua=mailto:soc@example.com; adkim=s; aspf=s';
        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'dmarc_enable' => '1',
            'dmarc_policy' => 'none',
            'dmarc_custom' => $custom,
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame($custom, $vm['dmarc_template']);
        self::assertSame($custom, $vm['dmarc']['custom']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostDisabledDmarcReturnsEmptyTemplate(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            // dmarc_enable absent → builder disabled regardless of other fields.
            'dmarc_policy' => 'reject',
            'dmarc_email' => 'ops@example.com',
        ]);

        self::assertTrue($vm['saved']);
        self::assertFalse($vm['dmarc']['enabled']);
        self::assertSame('', $vm['dmarc_template']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostDmarcPctOutOfRangeBecomesNull(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'dmarc_enable' => '1',
            'dmarc_policy' => 'none',
            'dmarc_pct' => '150',
        ]);

        self::assertTrue($vm['saved']);
        self::assertNull($vm['dmarc']['pct']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostDmarcPctEmptyStringStaysNull(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'dmarc_enable' => '1',
            'dmarc_pct' => '',
        ]);

        self::assertTrue($vm['saved']);
        self::assertNull($vm['dmarc']['pct']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostSpfPresetsCombineWithCustomLinesAndDedupe(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'spf_preset' => ['google', 'sendgrid', 'this-is-not-a-real-preset'],
            // Custom list duplicates google's expansion and adds a fresh one.
            'spf_custom' => "+include:_spf.google.com\n+include:mail.example.com\n",
        ]);

        self::assertTrue($vm['saved']);
        // Preset slugs round-trip back unchanged (unknown slug is preserved
        // in the form state — only the composed expansion drops it).
        self::assertContains('google', $vm['spf_presets']);
        self::assertContains('sendgrid', $vm['spf_presets']);
        self::assertSame("+include:_spf.google.com\n+include:mail.example.com\n", $vm['spf_custom']);
        // Expanded extras: presets first, then custom, dedup case-insensitive.
        $lines = explode("\n", $vm['spf_extras']);
        self::assertContains('+include:_spf.google.com', $lines);
        self::assertContains('+include:sendgrid.net', $lines);
        self::assertContains('+include:mail.example.com', $lines);
        // Dedupe: google appears exactly once even though custom repeated it.
        self::assertCount(
            1,
            array_filter($lines, static fn (string $l): bool => $l === '+include:_spf.google.com'),
        );
    }

    #[RunInSeparateProcess]
    public function testHandlePostSpfPresetWithNonStringEntriesIsIgnored(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'spf_preset' => ['google', '', 0, null, 'mailgun'],
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame(['google', 'mailgun'], $vm['spf_presets']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostSpfPresetNotAnArrayIsIgnoredGracefully(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            // Scalar where array is expected — controller must not crash.
            'spf_preset' => 'google',
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame([], $vm['spf_presets']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostPersistsLocalRewriteUntouchedFromExistingState(): void
    {
        // Pre-seed system.json with a non-default local_rewrite block so we
        // can verify the controller round-trips it instead of resetting to
        // factory defaults (the form does not yet expose those knobs).
        $storage = new SystemConfigStorage();
        $existing = $storage->load();
        $existing['local_rewrite'] = [
            'enabled' => true,
            'exclude_zones' => ['skip-me.example'],
            'overwrite_custom_rua' => true,
            'respect_has_custom_dmarc' => false,
            'respect_user_locks' => false,
        ];
        $storage->save($existing);

        $token = Csrf::token();
        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'default_ttl' => '600',
        ]);

        self::assertTrue($vm['saved']);
        $reloaded = (new SystemConfigStorage())->load();
        self::assertTrue($reloaded['local_rewrite']['enabled']);
        self::assertSame(['skip-me.example'], $reloaded['local_rewrite']['exclude_zones']);
        self::assertTrue($reloaded['local_rewrite']['overwrite_custom_rua']);
        self::assertFalse($reloaded['local_rewrite']['respect_has_custom_dmarc']);
        self::assertFalse($reloaded['local_rewrite']['respect_user_locks']);
        self::assertSame(600, $reloaded['defaults']['ttl']);
    }

    #[RunInSeparateProcess]
    public function testHandleReportsEnrolledUsersFromTheSharedSystemTree(): void
    {
        file_put_contents($this->tmpDir . '/enrolled-users', "alice\ncarol\n");

        $vm = $this->makeController()->handle('GET', []);

        self::assertSame(['alice', 'carol'], $vm['enrolled']);
    }

    #[RunInSeparateProcess]
    public function testHandleSurfacesStorageFailureAsHumanReadableError(): void
    {
        $token = Csrf::token();

        // Force save() to fail by pointing the system dir at a path the
        // process can neither create nor write to.
        putenv(Paths::ENV_SYSTEM_DIR . '=/proc/zonemirror-does-not-exist');

        // Storage is constructed per-call via load(), but the AdminController
        // holds its own injected SystemConfigStorage; build a fresh one so it
        // observes the new env override.
        $ctl = new AdminController(new SystemConfigStorage());
        $vm = $ctl->handle('POST', [
            'csrf' => $token,
            'default_ttl' => '500',
        ]);

        self::assertFalse($vm['saved']);
        self::assertNotSame([], $vm['errors']);
        self::assertStringStartsWith('Could not save: ', $vm['errors'][0]);
    }

    #[RunInSeparateProcess]
    public function testHandlePostAutoTtlDefaultsToFalseWhenCheckboxAbsent(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            // auto_ttl missing → unchecked → false.
        ]);

        self::assertTrue($vm['saved']);
        self::assertFalse($vm['auto_ttl']);
    }

    #[RunInSeparateProcess]
    public function testHandlePostAllowedUsersModeUnknownFallsBackToList(): void
    {
        $token = Csrf::token();

        $vm = $this->makeController()->handle('POST', [
            'csrf' => $token,
            'allowed_users_mode' => 'something-weird',
            'allowed_users_list' => 'dave',
        ]);

        self::assertTrue($vm['saved']);
        // Anything other than the literal 'all' is treated as 'list'.
        self::assertSame('list', $vm['allowed_users_mode']);
        self::assertSame(['dave'], (new SystemConfigStorage())->load()['allowed_users']);
    }

    #[RunInSeparateProcess]
    public function testHandleNonPostMethodSkipsValidationEvenWithoutCsrf(): void
    {
        // PUT/DELETE/anything that isn't 'POST' takes the read-only path.
        $vm = $this->makeController()->handle('PUT', ['default_ttl' => '999']);

        self::assertFalse($vm['saved']);
        self::assertSame([], $vm['errors']);
        self::assertSame(300, $vm['default_ttl']);
    }

    #[RunInSeparateProcess]
    public function testConstructorAcceptsNullStorageAndStillReturnsViewModel(): void
    {
        // The default-construct path exercises the `?? new SystemConfigStorage()`
        // branch. We just need it to not blow up and to return a well-shaped vm.
        $vm = (new AdminController())->handle('GET', []);

        self::assertArrayHasKey('csrf', $vm);
        self::assertArrayHasKey('defaults_proxied', $vm);
    }

    private function makeController(): AdminController
    {
        return new AdminController(new SystemConfigStorage());
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $e);
        }
        @rmdir($path);
    }
}
