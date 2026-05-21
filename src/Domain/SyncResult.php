<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

enum SyncResult: string
{
    case Applied = 'applied';
    case NoChange = 'no_change';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
