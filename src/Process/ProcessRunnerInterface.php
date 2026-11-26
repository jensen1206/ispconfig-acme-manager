<?php

namespace App\Process;

interface ProcessRunnerInterface
{
    /** @param list<string> $command */
    public function run(array $command, ?int $timeout = null, bool $liveOutput = true): ProcessResult;
}
