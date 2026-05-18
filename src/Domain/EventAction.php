<?php

declare(strict_types=1);

namespace CfSync\Domain;

enum EventAction: string
{
    case Upsert = 'UPSERT';
    case Delete = 'DELETE';
}
