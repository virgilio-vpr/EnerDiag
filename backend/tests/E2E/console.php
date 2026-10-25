<?php

use Database\Seeders\DistributorSeeder;
use Database\Seeders\RegulatoryParameterSeeder;
use Database\Seeders\TariffSeeder;
use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Kernel::class);

if (($argv[1] ?? '') === 'prepare') {
    if ($kernel->call('migrate', ['--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Could not migrate the isolated E2E database.');
    }
    foreach ([DistributorSeeder::class, RegulatoryParameterSeeder::class, TariffSeeder::class] as $seeder) {
        $app->make($seeder)->run();
    }
} elseif (($argv[1] ?? '') === 'worker') {
    exit($kernel->call('queue:work', ['--sleep' => 1, '--tries' => 3, '--timeout' => 300, '--no-interaction' => true]));
} else {
    throw new InvalidArgumentException('Unsupported E2E command.');
}
