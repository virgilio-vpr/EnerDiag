<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class E2EEnvironmentSafetyTest extends TestCase
{
    public function test_e2e_bootstrap_refuses_missing_or_shared_directories_before_loading_the_database(): void
    {
        $backend = dirname(__DIR__, 2);
        foreach ([false, sys_get_temp_dir(), $backend] as $directory) {
            $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $backend.'/tests/E2E/console.php', 'prepare'], $backend, [
                'ENERDIAG_E2E_DIRECTORY' => $directory,
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'must-not-be-used-by-e2e',
            ]);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('E2E requires its own temporary directory.', $process->getErrorOutput());
        }
    }
}
