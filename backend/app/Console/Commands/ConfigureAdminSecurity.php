<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ConfigureAdminSecurity extends Command
{
    protected $signature = 'admin:configure-security {email : E-mail do administrador existente}';

    protected $description = 'Reforça a senha e configura ou recupera o autenticador TOTP de um administrador';

    public function handle(TotpService $totp): int
    {
        $admin = User::query()->where('email', $this->argument('email'))->where('role', 'admin')->first();
        if ($admin === null) {
            $this->error('Administrador não encontrado.');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Execute este comando em um terminal interativo.');

            return self::FAILURE;
        }

        $password = $this->secret('Nova senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)');
        $confirmation = $this->secret('Confirme a nova senha');
        $validator = Validator::make([
            'password' => $password, 'password_confirmation' => $confirmation,
        ], ['password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()->uncompromised()]]);
        if ($validator->fails()) {
            $this->error('Senha recusada. Use uma senha forte, não comprometida e confirme-a corretamente.');

            return self::FAILURE;
        }

        $secret = $totp->generateSecret();
        $this->line('Adicione esta chave ao seu autenticador em um ambiente privado:');
        $this->line($secret);
        $this->line($totp->provisioningUri($admin->email, $secret));
        $code = $this->secret('Código atual do autenticador (6 números)');
        $step = is_string($code) ? $totp->matchingStep($secret, $code, now()->timestamp) : null;
        if ($step === null) {
            $this->error('Código inválido. Nenhuma alteração foi salva.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($admin, $password, $secret, $step): void {
            $locked = User::query()->lockForUpdate()->findOrFail($admin->id);
            $locked->forceFill([
                'password_hash' => $password,
                'totp_secret' => $secret,
                'totp_last_used_step' => $step,
            ])->save();
            $locked->tokens()->delete();
        });
        $this->info('Senha reforçada e TOTP ativado. Sessões anteriores foram revogadas. Aguarde o próximo código para entrar.');

        return self::SUCCESS;
    }
}
