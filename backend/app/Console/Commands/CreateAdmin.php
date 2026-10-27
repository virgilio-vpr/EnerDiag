<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email : E-mail do novo administrador} {name : Nome do administrador}';

    protected $description = 'Cria um administrador com senha forte e autenticador TOTP confirmado';

    public function handle(TotpService $totp): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Execute este comando em um terminal interativo.');

            return self::FAILURE;
        }

        $identity = ['email' => $this->argument('email'), 'name' => $this->argument('name')];
        if (Validator::make($identity, [
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
        ])->fails()) {
            $this->error('Informe nome e e-mail válidos. O e-mail não pode pertencer a outra conta.');

            return self::FAILURE;
        }

        $password = $this->secret('Senha (mínimo 14 caracteres, maiúsculas, minúsculas, números e símbolos)');
        $confirmation = $this->secret('Confirme a senha');
        if (Validator::make([
            'password' => $password, 'password_confirmation' => $confirmation,
        ], ['password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()->uncompromised()]])->fails()) {
            $this->error('Senha recusada. Use uma senha forte, não comprometida e confirme-a corretamente.');

            return self::FAILURE;
        }

        $secret = $totp->generateSecret();
        $this->line('Adicione esta chave ao seu autenticador em um ambiente privado:');
        $this->line($secret);
        $this->line($totp->provisioningUri($identity['email'], $secret));
        $code = $this->secret('Código atual do autenticador (6 números)');
        $step = is_string($code) ? $totp->matchingStep($secret, $code, now()->timestamp) : null;
        if ($step === null) {
            $this->error('Código inválido. Nenhuma conta foi criada.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($identity, $password, $secret, $step): void {
            $admin = new User;
            $admin->forceFill($identity + [
                'password_hash' => $password,
                'role' => 'admin',
                'email_verified_at' => now(),
                'totp_secret' => $secret,
                'totp_last_used_step' => $step,
            ])->save();
        });
        $this->info('Administrador criado com TOTP ativo. Aguarde o próximo código para entrar.');

        return self::SUCCESS;
    }
}
