<?php

namespace App\Invoices\Validation;

use App\Models\Simulation;

final class InvoiceProcessingFailureRecorder
{
    public const string VALIDATION_ERROR_CODE = 'INVOICE_EXTRACTION_INVALID';

    /**
     * @param  list<array{file: string, code: string, errors: array<string, string>}>  $fileErrors
     */
    public function record(Simulation $simulation, array $fileErrors): void
    {
        $simulation->forceFill([
            'status' => 'failed',
            'processing_errors' => $fileErrors,
        ])->save();
    }

    /**
     * @param  list<string>  $fileNames
     * @param  array<string, list<string>>  $errors
     */
    public function recordValidationErrors(Simulation $simulation, array $fileNames, array $errors): void
    {
        $errorsByFile = [];
        foreach ($errors as $path => $messages) {
            if (preg_match('/^invoices\.(\d+)\.(.+)$/', $path, $match) !== 1) {
                continue;
            }

            $index = (int) $match[1];
            if (! isset($fileNames[$index])) {
                continue;
            }

            $errorsByFile[$index][$match[2]] = implode(' ', $messages);
        }

        $fileErrors = [];
        foreach ($errorsByFile as $index => $fileErrorsForIndex) {
            $fileErrors[] = [
                'file' => $fileNames[$index],
                'code' => self::VALIDATION_ERROR_CODE,
                'errors' => $fileErrorsForIndex,
            ];
        }

        $this->record($simulation, $fileErrors);
    }
}
