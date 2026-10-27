import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { invoiceFixtures } from './invoice-fixtures.ts'

// Only disposable Compose resources are used; the development .env is never loaded.
const root = fileURLToPath(new URL('../../', import.meta.url))
const directory = mkdtempSync('/tmp/enerdiag-production-')
const project = `enerdiag-production-test-${process.pid}`
const envFile = resolve(directory, 'env')
const baseUrl = 'https://localhost:19443'
const composeArgs = ['compose', '-p', project, '-f', resolve(root, 'docker-compose.production.yml'), '--env-file', envFile]
function compose(args, input) {
  try {
    return execFileSync('docker', [...composeArgs, ...args], {
      cwd: root, input, encoding: 'utf8', maxBuffer: 100 * 1024 * 1024, stdio: ['pipe', 'pipe', 'pipe'],
    }).trim()
  } catch (error) {
    throw new Error(`Docker ${args.join(' ')} failed:\n${error.stdout ?? ''}\n${error.stderr ?? ''}`, { cause: error })
  }
}
function php(service, body) {
  return compose(['exec', '-T', service, 'php'], `<?php
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    ${body}
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}`)
}
async function poll(check, message) {
  for (let count = 0; count < 60; count++) {
    if (await check()) return
    await new Promise((done) => setTimeout(done, 1000))
  }
  throw new Error(message)
}
function request(path, args = []) {
  return execFileSync('curl', ['--silent', '--show-error', '--insecure', '--max-time', '30', ...args, `${baseUrl}${path}`], { encoding: 'utf8' })
}
let started = false
try {
  writeFileSync(envFile, [
    `PRODUCTION_ENV_FILE=${envFile}`, `APP_KEY=base64:${randomBytes(32).toString('base64')}`,
    `APP_URL=${baseUrl}`, `FRONTEND_URL=${baseUrl}`, `CORS_ALLOWED_ORIGINS=${baseUrl}`,
    'DB_DATABASE=enerdiag', 'DB_USERNAME=enerdiag', `DB_PASSWORD=${randomBytes(24).toString('hex')}`,
    `TLS_CERTIFICATE=${directory}/cert.pem`, `TLS_PRIVATE_KEY=${directory}/key.pem`,
    'HTTP_BIND=127.0.0.1', 'HTTP_PORT=19080', 'HTTPS_PORT=19443', `IMAGE_TAG=production-test-${process.pid}`, 'MAIL_MAILER=log',
  ].join('\n') + '\n', { mode: 0o600 })
  execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', `${directory}/key.pem`, '-out', `${directory}/cert.pem`, '-days', '1', '-subj', '/CN=localhost'], { stdio: 'ignore' })
  console.log('Construindo e iniciando o Compose de produção isolado...')
  started = true
  compose(['up', '-d', '--build', '--wait', '--wait-timeout', '180'])
  assert.match(request('/up'), /Application up/)
  assert.match(request('/register'), /<html/)
  assert.match(request('/', ['-I']), /strict-transport-security:/i)
  const redirect = execFileSync('curl', ['-sI', 'http://127.0.0.1:19080/'], { encoding: 'utf8' })
  assert.match(redirect, /308/)
  assert.match(redirect, /Location: https:/i)
  assert.equal(request('/api/v1/simulations', ['-o', '/dev/null', '-w', '%{http_code}']), '401')
  assert.equal(request('/.env', ['-o', '/dev/null', '-w', '%{http_code}']), '403')
  assert.equal(request('/index.php', ['-o', '/dev/null', '-w', '%{http_code}']), '404')
  // Expect rejection before transmitting an oversized request body.
  assert.equal(request('/api/v1/simulations', ['-X', 'POST', '-H', 'Content-Length: 128974849', '-H', 'Expect: 100-continue', '-o', '/dev/null', '-w', '%{http_code}']), '413')
  php('api', `
    if (file_exists('database/database.sqlite') || file_exists('.env')) { throw new RuntimeException('Development data leaked into image'); }
    if (!app()->environment('production') || config('app.debug')) { throw new RuntimeException('Unsafe environment'); }
    if (App\\Models\\User::count() !== 0) { throw new RuntimeException('Unexpected seeded users'); }
    app(Database\\Seeders\\DatabaseSeeder::class)->run();
    if (App\\Models\\User::count() !== 0) { throw new RuntimeException('Test seeder ran in production'); }
    if ((int) ini_get('max_file_uploads') < 13) { throw new RuntimeException('PHP truncates excess uploads'); }
    $job = new App\\Jobs\\ProcessSimulation(1);
    if ($job->tries !== 3 || $job->timeout !== 300 || $job->backoff !== [60, 180] || config('queue.connections.database.retry_after') <= $job->timeout) { throw new RuntimeException('Unsafe queue configuration'); }
  `)
  console.log('HTTPS, SPA, limites do proxy e seeder de produção verificados.')
  const identity = JSON.parse(php('api', `
    $user = App\\Models\\User::create(['name'=>'Production test','email'=>'production@example.test','password_hash'=>'SyntheticPassword2026!','role'=>'customer','email_verified_at'=>now()]);
    echo json_encode(['token'=>$user->createToken('production-test')->plainTextToken,'distributor'=>App\\Models\\Distributor::where('parser_key','enel')->value('id')]);
  `))
  assert.equal(typeof identity.distributor, 'number', `Invalid distributor fixture: ${JSON.stringify(identity.distributor)}`)
  compose(['stop', 'worker'])
  const files = invoiceFixtures().map((invoice) => {
    const path = resolve(directory, invoice.name)
    writeFileSync(path, invoice.buffer)
    return ['-F', `invoices[]=@${path};type=application/pdf`]
  }).flat()
  const submissionArgs = [
    '-H', `Authorization: Bearer ${identity.token}`, '-H', 'Accept: application/json',
    '-F', 'company_name=Production test', '-F', 'address_details=Synthetic address',
    '-F', 'substation_same_address=1', '-F', `distributor_id=${identity.distributor}`,
  ]
  const tooManyFiles = JSON.parse(request('/api/v1/simulations', [
    ...submissionArgs, ...Array.from({ length: 13 }, () => files.slice(0, 2)).flat(),
  ]))
  assert.ok(tooManyFiles.errors?.invoices, `PHP must preserve excess files so the API can reject the count: ${JSON.stringify(tooManyFiles)}`)
  const submitted = JSON.parse(request('/api/v1/simulations', [...submissionArgs, ...files]))
  assert.ok(submitted.data?.id, JSON.stringify(submitted))
  const id = Number(submitted.data.id)
  php('api', `
    $disk = Illuminate\\Support\\Facades\\Storage::disk('invoice_temp');
    if (count($disk->files('simulation-${id}')) !== 6) { throw new RuntimeException('API did not save six PDFs'); }
    rename($disk->path('simulation-${id}'), $disk->path('held-${id}'));
  `)
  compose(['start', 'worker'])
  for (const [attempt, backoff] of [[1, 60], [2, 180]]) {
    await poll(() => php('api', `
      $job = Illuminate\\Support\\Facades\\DB::table('jobs')->first();
      echo $job && $job->attempts === ${attempt} && $job->reserved_at === null && $job->available_at > time() ? 'released' : 'waiting';
    `) === 'released', `Attempt ${attempt} was not released`)
    php('api', `
      $job = Illuminate\\Support\\Facades\\DB::table('jobs')->first();
      if (abs(($job->available_at - time()) - ${backoff}) > 10) { throw new RuntimeException('Wrong backoff'); }
      if (count(Illuminate\\Support\\Facades\\Storage::disk('invoice_temp')->files('held-${id}')) !== 6) { throw new RuntimeException('PDFs lost during retry'); }
    `)
    compose(['stop', 'worker'])
    if (attempt === 1) {
      php('api', `Illuminate\\Support\\Facades\\DB::table('jobs')->update(['available_at'=>time()-1]);`)
      compose(['start', 'worker'])
    }
  }
  php('api', `
    $disk = Illuminate\\Support\\Facades\\Storage::disk('invoice_temp');
    rename($disk->path('held-${id}'), $disk->path('simulation-${id}'));
    Illuminate\\Support\\Facades\\DB::table('jobs')->update(['reserved_at'=>time()-361,'available_at'=>time()-361]);
  `)
  compose(['start', 'worker'])
  await poll(() => JSON.parse(request(`/api/v1/simulations/${id}/status`, ['-H', `Authorization: Bearer ${identity.token}`])).data.status === 'completed', 'Worker did not recover reserved job and process PDFs')
  php('worker', `
    if (Illuminate\\Support\\Facades\\DB::table('jobs')->count() || Illuminate\\Support\\Facades\\DB::table('failed_jobs')->count()) { throw new RuntimeException('Queue not clean'); }
    if (count(Illuminate\\Support\\Facades\\Storage::disk('invoice_temp')->allFiles())) { throw new RuntimeException('PDFs not removed'); }
    if (App\\Models\\Invoice::count() !== 6 || App\\Models\\Report::count() !== 1) { throw new RuntimeException('Missing report'); }
  `)
  console.log('API → volume privado → worker: PDFs processados; três tentativas, backoffs 60/180 e recuperação de reserva verificados.')
  php('scheduler', `
    $expired = App\\Models\\Simulation::create(['user_id'=>App\\Models\\User::first()->id,'status'=>'completed']);
    $expired->forceFill(['created_at'=>now()->subDays(181)])->save();
    $disk = Illuminate\\Support\\Facades\\Storage::disk('invoice_temp');
    $disk->put('orphan.pdf','synthetic');
    touch($disk->path('orphan.pdf'), time()-3700);
    Illuminate\\Support\\Carbon::setTestNow(now()->startOfDay());
    Illuminate\\Support\\Facades\\Artisan::call('schedule:run');
    if ($expired->fresh() !== null || $disk->exists('orphan.pdf')) { throw new RuntimeException('Scheduled maintenance did not run'); }
    $commands = array_map(fn($event) => $event->command, app(Illuminate\\Console\\Scheduling\\Schedule::class)->events());
    if (!str_contains(implode(' ', $commands), 'tariffs:fetch-aneel')) { throw new RuntimeException('Missing tariff schedule'); }
  `)
  console.log('Agendador: expurgo e limpeza executados; importação de tarifas registrada. Teste de produção aprovado.')
} catch (error) {
  if (started) {
    try { console.error(compose(['logs', '--tail', '40', 'api', 'worker', 'scheduler', 'frontend', 'migrate'])) } catch { /* Preserve the original error. */ }
  }
  throw error
} finally {
  if (started) compose(['down', '--volumes', '--remove-orphans'])
  rmSync(directory, { recursive: true, force: true })
}
