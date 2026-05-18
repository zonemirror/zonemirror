<?php

declare(strict_types=1);

namespace CfSync\Infrastructure\Logging;

/**
 * Append-only JSON-lines logger. Every record is funneled through
 * TokenRedactor before hitting disk so secrets do not leak into shared
 * /var/log paths. PSR-3 compatible in shape but no hard dependency.
 */
final class FileLogger
{
    public function __construct(
        private readonly string $path,
        private readonly LogLevel $minLevel = LogLevel::Info,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(LogLevel $level, string $message, array $context): void
    {
        if (!$this->shouldEmit($level)) {
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $entry = [
            'ts' => gmdate('c'),
            'level' => $level->value,
            'msg' => $message,
            'ctx' => $context,
        ];
        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        $line = TokenRedactor::redact($encoded) . "\n";
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private function shouldEmit(LogLevel $level): bool
    {
        $rank = [
            LogLevel::Debug->value => 0,
            LogLevel::Info->value => 1,
            LogLevel::Warning->value => 2,
            LogLevel::Error->value => 3,
        ];

        return $rank[$level->value] >= $rank[$this->minLevel->value];
    }
}
