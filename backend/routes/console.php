<?php

use App\Console\Commands\PurgeExpiredSimulations;
use App\Invoices\Pdf\OrphanedInvoicePdfCleaner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('invoices:clean-temporary-files', function (OrphanedInvoicePdfCleaner $cleaner) {
    $this->info("Arquivos temporários órfãos removidos: {$cleaner->clean()}.");
})->purpose('Remove temporary invoice files older than one hour');

Schedule::command('invoices:clean-temporary-files')->daily();
Schedule::command(PurgeExpiredSimulations::class)->daily();
Schedule::command('tariffs:fetch-aneel')->dailyAt('04:00')->withoutOverlapping(10);
