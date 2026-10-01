<?php

declare(strict_types=1);

namespace App;

final class ConsoleLogger
{
    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf('[%s] [%s] %s%s', date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
        $stream = $level === 'ERROR' ? STDERR : STDOUT;
        fwrite($stream, $line);
    }
}
