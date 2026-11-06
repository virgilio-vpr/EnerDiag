<?php

namespace Tests\Unit;

use App\Console\Commands\ServeCommand;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServeCommandTest extends TestCase
{
    public function test_development_cache_keeps_application_sources_fresh_and_preserves_upload_limits(): void
    {
        if (! extension_loaded('Zend OPcache')) {
            $this->markTestSkipped('The development cache requires OPcache.');
        }
        $command = new class extends ServeCommand
        {
            public function inspectServerCommand(): array
            {
                return $this->serverCommand();
            }

            protected function host(): string
            {
                return '127.0.0.1';
            }

            protected function port(): int
            {
                return 8000;
            }
        };
        $arguments = $command->inspectServerCommand();
        $script = 'require '.var_export(base_path('vendor/autoload.php'), true).'; require '.var_export(app_path('Calculation/Services/DemandBillingService.php'), true).'; echo json_encode(['
            .'"application_cached"=>opcache_is_script_cached('.var_export(app_path('Calculation/Services/DemandBillingService.php'), true).'),'
            .'"vendor_cached"=>opcache_is_script_cached('.var_export(base_path('vendor/composer/ClassLoader.php'), true).'),'
            .'"upload_limit"=>ini_get("upload_max_filesize"),"post_limit"=>ini_get("post_max_size")]);';
        $process = new Process([...array_slice($arguments, 0, array_search('-S', $arguments, true)), '-r', $script], base_path());
        $process->setTimeout(60)->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['vendor_cached']);
        $this->assertFalse($result['application_cached']);
        $this->assertSame('20M', $result['upload_limit']);
        $this->assertSame('128M', $result['post_limit']);
    }
}
