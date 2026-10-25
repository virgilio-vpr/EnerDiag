<?php

return [
    'csv_url' => env('ANEEL_TARIFF_CSV_URL', 'https://dadosabertos.aneel.gov.br/dataset/5a583f3e-1646-4f67-bf0f-69db4203e89e/resource/fcf2906c-7c32-4b9b-a637-054e7a5234f4/download/tarifas-homologadas-distribuidoras-energia-eletrica.csv'),
    'max_download_bytes' => 256 * 1024 * 1024,
];
