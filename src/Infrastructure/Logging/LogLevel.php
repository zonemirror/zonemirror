<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Logging;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
