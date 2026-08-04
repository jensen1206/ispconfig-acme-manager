<?php

namespace App\Process;

use Symfony\Component\Process\Process;

final readonly class ProcessRunner
{
    /**
     * @param list<string> $command
     */
    public function run(
        array $command,
        ?int $timeout = null,
        bool $liveOutput = true,
    ): ProcessResult {
        $process = new Process($command);
        $process->setTimeout($timeout);

        $process->run(
            $liveOutput
                ? static function (string $type, string $buffer): void {
                    if (Process::ERR === $type) {
                        fwrite(\STDERR, $buffer);

                        return;
                    }

                    echo $buffer;
                }
            : null,
        );

        return new ProcessResult(
            exitCode: $process->getExitCode() ?? -1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        );
    }
}
