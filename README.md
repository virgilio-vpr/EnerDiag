# EnerDiag

Sistema web para análise de faturas de energia do Grupo A. O repositório contém a API Laravel em `backend/`, a SPA React/TypeScript em `frontend/`, e a especificação em `docs/PROJECT_SPEC.md`.

## Pré-requisitos

- PHP 8.5.12 ou superior, com `pdo_pgsql`
- Composer
- Node.js 22.12 ou superior e npm
- Docker com Docker Compose e acesso ao daemon

Confira as versões no terminal:

```bash
php -v
php -m | grep pdo_pgsql
composer --version
node --version
npm --version
docker compose version
```

No Codespace deste projeto, o PHP 8.5 fica em `$HOME/.config/herd-lite/bin`. Se `php -v` mostrar uma versão anterior, ative-o nesta sessão antes dos comandos PHP e Composer:

```bash
export PATH="$HOME/.config/herd-lite/bin:$PATH"
php -v
```

## Configuração local

Na raiz do repositório, crie os arquivos de ambiente a partir dos exemplos:

```bash
cp .env.example .env
cp backend/.env.example backend/.env
```

Defina uma senha local para PostgreSQL em `DB_PASSWORD` nos dois arquivos. Mantenha estes valores iguais:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=enerdiag
DB_USERNAME=enerdiag
DB_PASSWORD=sua_senha_local
```

O `.env` da raiz fornece as credenciais ao Docker Compose; `backend/.env` configura o Laravel. Não versione arquivos `.env`.

## Iniciar o banco

Na raiz do repositório:

```bash
docker compose up -d
docker compose ps
docker compose exec -T postgres pg_isready -U enerdiag -d enerdiag
```

## Instalar e iniciar a API

```bash
cd backend
composer install
php artisan key:generate
php artisan migrate
php artisan scribe:generate
php artisan serve --host=0.0.0.0
```

A API ficará disponível na porta 8000, com a documentação em `/docs` e o OpenAPI em `/docs.openapi`.

Em outro terminal, mantenha o processamento das simulações ativo:

```bash
cd backend
php artisan queue:work database --queue=default --tries=3 --timeout=300
```

As faturas já salvas são calculadas diretamente pela API ao clicar em Simular relatório. O trabalhador processa o envio do relatório por e-mail e as importações pelo fluxo antigo em lote. Após alterar código do backend, reinicie o trabalhador. O `DB_QUEUE_RETRY_AFTER` deve ser maior que o timeout de 300 segundos; o padrão é 360 segundos.

## Instalar e iniciar o frontend

Em outro terminal, na raiz do repositório:

```bash
cd frontend
npm install
npm run dev -- --host 0.0.0.0
```

O Vite ficará disponível na porta 5173.

## Verificações e auditorias

Na pasta `backend/`:

```bash
php artisan test
./vendor/bin/pint
composer audit
```

Na pasta `frontend/`:

```bash
npm run test
npm run lint
npm run build
npm audit
```
