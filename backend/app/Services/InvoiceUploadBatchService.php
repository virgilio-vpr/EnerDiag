<?php

namespace App\Services;

use App\Invoices\Validation\InvoiceUploadValidator;
use App\Models\InvoiceUploadBatch;
use App\Models\Simulation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class InvoiceUploadBatchService
{
    public function __construct(private InvoiceUploadValidator $validator, private SubmitSimulation $submitSimulation) {}

    public function store(InvoiceUploadBatch $batch, UploadedFile $file, string $clientId): array
    {
        $pages = $this->validator->validateSingle($file);
        $path = null;
        try {
            return DB::transaction(function () use ($batch, $file, $clientId, $pages, &$path): array {
                $locked = InvoiceUploadBatch::query()->lockForUpdate()->findOrFail($batch->id);
                $this->assertActive($locked);
                abort_if($locked->simulation_id !== null, 409, 'Este envio já foi concluído.');
                $files = $locked->files;
                $hash = hash_file('sha256', $file->getRealPath());
                foreach ($files as $existing) {
                    if ($existing['id'] === $clientId) {
                        if ($existing['sha256'] !== $hash) {
                            throw ValidationException::withMessages(['invoice' => 'Selecione novamente a fatura para substituir este arquivo.']);
                        }

                        return $existing;
                    }
                }
                if (count($files) >= InvoiceUploadValidator::MAX_FILES) {
                    throw ValidationException::withMessages(['invoice' => 'O envio aceita no máximo 12 faturas.']);
                }
                if (array_sum(array_column($files, 'size')) + $file->getSize() > InvoiceUploadValidator::MAX_TOTAL_BYTES) {
                    throw new HttpException(413, 'O conjunto das faturas pode ter no máximo 120 MB.');
                }
                if (array_sum(array_column($files, 'pages')) + $pages > InvoiceUploadValidator::MAX_PAGES) {
                    throw new HttpException(413, 'O conjunto das faturas pode ter no máximo 600 páginas.');
                }
                $path = $file->storeAs('upload-'.$locked->id, $clientId.'.pdf', 'invoice_temp');
                if ($path === false) {
                    throw new \RuntimeException('Não foi possível receber esta fatura.');
                }
                $entry = ['id' => $clientId, 'name' => $file->getClientOriginalName(), 'path' => $path,
                    'size' => $file->getSize(), 'pages' => $pages, 'sha256' => $hash];
                $files[] = $entry;
                $locked->update(['files' => $files]);

                return $entry;
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('invoice_temp')->delete($path);
            }
            throw $exception;
        }
    }

    public function complete(InvoiceUploadBatch $batch, User $user): Simulation
    {
        $simulation = DB::transaction(function () use ($batch, $user): Simulation {
            $locked = InvoiceUploadBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->simulation_id !== null) {
                return Simulation::query()->where('user_id', $user->id)->findOrFail($locked->simulation_id);
            }
            $this->assertActive($locked);
            $disk = Storage::disk('invoice_temp');
            $files = [];
            foreach ($locked->files as $entry) {
                if (! $disk->exists($entry['path'])) {
                    throw new HttpException(410, 'O envio expirou. Selecione e envie as faturas novamente.');
                }
                $files[] = new UploadedFile($disk->path($entry['path']), $entry['name'], 'application/pdf', null, true);
            }
            $created = $this->submitSimulation->handle($user, $locked->company_data, $files);
            $locked->update(['simulation_id' => $created->id, 'files' => [], 'company_data' => []]);

            return $created;
        });
        Storage::disk('invoice_temp')->deleteDirectory('upload-'.$batch->id);

        return $simulation;
    }

    public function discard(InvoiceUploadBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $locked = InvoiceUploadBatch::query()->lockForUpdate()->find($batch->id);
            if ($locked !== null) {
                Storage::disk('invoice_temp')->deleteDirectory('upload-'.$locked->id);
                $locked->delete();
            }
        });
    }

    public function purgeExpired(): void
    {
        InvoiceUploadBatch::query()->where('expires_at', '<=', now())->each(fn (InvoiceUploadBatch $batch) => $this->discard($batch));
    }

    private function assertActive(InvoiceUploadBatch $batch): void
    {
        if ($batch->expires_at->isPast()) {
            throw new HttpException(410, 'O envio expirou. Selecione e envie as faturas novamente.');
        }
    }
}
