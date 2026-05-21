<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

enum EventAction: string
{
    case Upsert = 'UPSERT';
    case Delete = 'DELETE';
}
