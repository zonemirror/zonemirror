<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case TXT = 'TXT';
    case SRV = 'SRV';
    case CAA = 'CAA';
    case NS = 'NS';

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtoupper($value));
    }

    public function supportsProxy(): bool
    {
        return $this === self::A || $this === self::AAAA || $this === self::CNAME;
    }
}
