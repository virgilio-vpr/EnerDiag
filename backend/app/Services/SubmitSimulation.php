<?php

namespace App\Services;

use App\Invoices\Exceptions\UnsupportedDistributorException;
use App\Invoices\Exceptions\UnsupportedInvoiceReaderException;
use App\Invoices\Readers\InvoiceReaderResolver;
use App\Invoices\Validation\InvoiceUploadValidator;
use App\Jobs\ProcessSimulation;
use App\Models\CustomerProfile;
use App\Models\Distributor;
use App\Models\Lead;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SubmitSimulation
{
    public function __construct(
        private readonly InvoiceReaderResolver $readerResolver,
        private readonly InvoiceUploadValidator $uploadValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function handle(User $user, array $data, array $files): Simulation
    {
        $distributor = isset($data['distributor_id'])
            ? Distributor::query()->findOrFail($data['distributor_id'])
            : null;
        $distributorName = $distributor?->name ?? trim((string) ($data['distributor_name'] ?? ''));

        if ($distributor === null || ! $distributor->active) {
            $this->registerUnsupportedDistributorLead($user, $distributorName);
            throw new UnsupportedDistributorException($distributorName);
        }

        try {
            $this->readerResolver->resolve($distributor);
        } catch (UnsupportedInvoiceReaderException) {
            $this->registerUnsupportedDistributorLead($user, $distributorName);
            throw new UnsupportedDistributorException($distributorName);
        }

        $this->uploadValidator->validate($files);

        $directory = null;
        try {
            return DB::transaction(function () use ($user, $data, $files, $distributor, &$directory): Simulation {
                CustomerProfile::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_name' => $data['company_name'],
                        'cnpj' => $data['cnpj'] ?? null,
                        'distributor_id' => $distributor->id,
                        'transformer_power_kva' => $data['transformer_power_kva'] ?? null,
                        'address_details' => $data['address_details'],
                        'substation_same_address' => $data['substation_same_address'],
                        'substation_address' => $data['substation_same_address'] ? null : $data['substation_address'],
                    ],
                );

                $simulation = Simulation::query()->create([
                    'user_id' => $user->id,
                    'status' => 'queued',
                    'months_count' => 0,
                    'expires_at' => now()->addDays(180),
                ]);
                $directory = 'simulation-'.$simulation->id;

                foreach ($files as $index => $file) {
                    $originalName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                    $storedName = sprintf('%02d-%s-%s.pdf', $index + 1, Str::uuid(), $originalName !== '' ? $originalName : 'invoice');
                    $storedPath = $file->storeAs($directory, $storedName, 'invoice_temp');
                    if ($storedPath === false) {
                        throw new \RuntimeException('Não foi possível armazenar uma fatura temporariamente.');
                    }
                }

                ProcessSimulation::dispatch($simulation->id)->afterCommit();

                return $simulation;
            });
        } catch (Throwable $exception) {
            if ($directory !== null) {
                Storage::disk('invoice_temp')->deleteDirectory($directory);
            }

            throw $exception;
        }
    }

    private function registerUnsupportedDistributorLead(User $user, string $distributorName): void
    {
        Lead::query()->create([
            'email' => $user->email,
            'distributor_name' => $distributorName !== '' ? $distributorName : null,
            'reason' => 'unsupported_distributor',
        ]);
    }
}
