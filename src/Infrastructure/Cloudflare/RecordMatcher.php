<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;

/**
 * Finds the existing Cloudflare record (if any) that corresponds to a local
 * cPanel record. Matching is by (type, name) plus a type-aware content rule:
 * - TXT: normalize surrounding quotes before comparing.
 * - SRV: compare the structured `data` block.
 * - MX:  match type+name+content; priority alone does not differentiate.
 * - others: type+name+content.
 */
final class RecordMatcher
{
    /**
     * @param list<array<string, mixed>> $existing
     * @return array<string, mixed>|null
     */
    public function findEquivalent(array $existing, DnsRecord $record): ?array
    {
        $name = strtolower($record->name);

        foreach ($existing as $candidate) {
            if (strtoupper((string) ($candidate['type'] ?? '')) !== $record->type->value) {
                continue;
            }
            if (strtolower((string) ($candidate['name'] ?? '')) !== $name) {
                continue;
            }
            if ($this->contentMatches($candidate, $record)) {
                return $candidate;
            }
        }

        // Fallback: same type+name but different content. Returned so the
        // caller can choose to update instead of duplicate.
        foreach ($existing as $candidate) {
            if (strtoupper((string) ($candidate['type'] ?? '')) === $record->type->value
                && strtolower((string) ($candidate['name'] ?? '')) === $name
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function contentMatches(array $candidate, DnsRecord $record): bool
    {
        if ($record->type === RecordType::SRV || $record->type === RecordType::CAA) {
            return $this->dataMatches(
                is_array($candidate['data'] ?? null) ? $candidate['data'] : [],
                $record->data,
            );
        }
        $candidateContent = isset($candidate['content']) ? (string) $candidate['content'] : '';
        $localContent = $record->content ?? '';
        if ($record->type === RecordType::TXT) {
            return $this->normalizeTxt($candidateContent) === $this->normalizeTxt($localContent);
        }

        return $candidateContent === $localContent;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function dataMatches(array $a, array $b): bool
    {
        foreach ($b as $key => $value) {
            if ((string) ($a[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function normalizeTxt(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
