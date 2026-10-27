<?php

namespace App\Providers;

use App\Calculation\Contracts\ReportCalculationEngine;
use App\Calculation\Services\SimulationReportCalculationEngine;
use App\Invoices\Pdf\PdfPageCounter;
use App\Invoices\Pdf\PdfTextParser;
use App\Invoices\Pdf\SmalotPdfPageCounter;
use App\Invoices\Pdf\SmalotPdfTextParser;
use App\Invoices\Readers\EnelInvoiceReader;
use App\Invoices\Readers\InvoiceReaderResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InvoiceReaderResolver::class, function (): InvoiceReaderResolver {
            return new InvoiceReaderResolver([new EnelInvoiceReader]);
        });
        $this->app->bind(ReportCalculationEngine::class, SimulationReportCalculationEngine::class);
        $this->app->bind(PdfTextParser::class, SmalotPdfTextParser::class);
        $this->app->bind(PdfPageCounter::class, SmalotPdfPageCounter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('verification_codes', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('invoice_files', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('leads', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
