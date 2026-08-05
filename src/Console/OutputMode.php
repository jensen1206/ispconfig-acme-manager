<?php

declare(strict_types=1);

namespace App\Console;

enum OutputMode
{
    case Interactive;

    case Cron;
}
