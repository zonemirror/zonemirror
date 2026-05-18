<?php

declare(strict_types=1);

namespace CfSync\Infrastructure\Logging;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
