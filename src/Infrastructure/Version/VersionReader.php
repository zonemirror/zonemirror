<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Version;

/**
 * Reads the plugin's installed semantic version from the VERSION file at the
 * repository / installation root. The file is a single line, no leading 'v'.
 * Used by the WHM admin UI to display "installed" vs "latest" and by tests.
 */
final class VersionReader
{
    public static function installed(?string $root = null): string
    {
        $candidates = [];
        if ($root !== null) {
            $candidates[] = $root . '/VERSION';
        }
        $candidates[] = '/usr/local/cpanel/3rdparty/zonemirror/VERSION';
        $candidates[] = dirname(__DIR__, 3) . '/VERSION';

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $raw = file_get_contents($path);
                if ($raw !== false) {
                    $clean = trim($raw);
                    if ($clean !== '') {
                        return $clean;
                    }
                }
            }
        }

        return 'unknown';
    }
}
