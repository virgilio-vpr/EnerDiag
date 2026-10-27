<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = getenv('ENERDIAG_E2E_DIRECTORY');
if (! is_string($directory) || ! preg_match('~\A/tmp/enerdiag-e2e-[a-zA-Z0-9]+\z~', $directory) || ! is_dir($directory)) {
    throw new RuntimeException('E2E requires its own temporary directory.');
}

// Never load the project .env or accept an inherited database/mail configuration.
$environment = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_TIMEZONE' => 'America/Sao_Paulo',
    'APP_URL' => 'http://127.0.0.1:18761', 'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $directory.'/database.sqlite', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'smtp', 'MAIL_URL' => 'smtp://127.0.0.1:18763', 'MAIL_SCHEME' => 'smtp',
    'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '18763', 'MAIL_USERNAME' => '(null)', 'MAIL_PASSWORD' => '(null)',
    'MAIL_FROM_ADDRESS' => 'enerdiag@example.test', 'MAIL_FROM_NAME' => 'EnerDiag E2E',
    'BCRYPT_ROUNDS' => '4', 'LOG_CHANNEL' => 'single',
    'APP_CONFIG_CACHE' => $directory.'/bootstrap/config.php',
    'APP_ROUTES_CACHE' => $directory.'/bootstrap/routes.php',
    'APP_EVENTS_CACHE' => $directory.'/bootstrap/events.php',
    'APP_SERVICES_CACHE' => $directory.'/bootstrap/services.php',
    'APP_PACKAGES_CACHE' => $directory.'/bootstrap/packages.php',
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

/** @var Application $app */
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->useStoragePath($directory.'/storage');
$app->make(Kernel::class)->bootstrap();

// Only the external password breach lookup is replaced; HTTP, SMTP and PDF parsing remain real.
Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('', 200)]);

return $app;
