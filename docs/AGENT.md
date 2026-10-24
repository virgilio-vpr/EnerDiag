# EnerDiag

Sistema web que lê faturas de energia do Grupo A (ENEL) e simula a economia. API Laravel 13 + SPA React/TypeScript.

## Spec
- `PROJECT_SPEC.md` é a fonte da verdade (fases, tarefas, regras). Não duplique seu conteúdo aqui.
- Ao receber "faça a Fase N": leia só a seção 0, a Fase N e as seções citadas em `Ref:`. Não leia o arquivo inteiro.
- Se este arquivo e o spec divergirem, vale o spec.

## Fluxo
- Uma tarefa por vez, na ordem. Em tarefas com vários arquivos, use plan mode e espere aprovação.
- Marque `[x]` no `PROJECT_SPEC.md` só depois de implementar **e** o teste da tarefa passar.
- Faça commit ao concluir cada tarefa.
- No fim de cada fase, resuma o que foi feito e sugira `/clear` antes da próxima.

## Testes
O "como testar" de cada tarefa está na linha `Teste:` do spec. Regras gerais:
- Toda tarefa tem teste automatizado. Endpoint: sucesso e erros (validação 422, sem token 401, sem permissão 403, entrada inválida).
- Motor de cálculo: testes unitários em decimal, com os casos do spec e dados sintéticos.
- Antes de marcar `[x]`: rode o teste da tarefa e a suíte completa; ambos devem passar.
- Se o teste falhar ou não puder rodar, não marque `[x]` e diga o motivo.

## Comandos (confirmar na tarefa 0.7)
- No Codespace atual, o PHP 8.5 está em `$HOME/.config/herd-lite/bin`; se `php -v` mostrar PHP 8.4, execute `export PATH="$HOME/.config/herd-lite/bin:$PATH"` e confira `php -v` antes dos comandos PHP/Composer.
- Banco (na raiz): `docker compose up -d`; verificar: `docker compose ps` e `docker compose exec -T postgres pg_isready -U enerdiag -d enerdiag`
- API (em `backend/`): `php artisan scribe:generate` gera a documentação em `/docs`; `php artisan serve --host=0.0.0.0` inicia a API.
- Testes da API (em `backend/`): `php artisan test` (um teste: `--filter=Nome`); formatar: `./vendor/bin/pint`
- Reset destrutivo do banco (em `backend/`; apaga todas as tabelas e dados): `php artisan migrate:fresh --seed`. Só executar com autorização explícita e após confirmar que não há dados a preservar.
- Front: `cd frontend && npm run dev -- --host 0.0.0.0`
- Testes do front (em `frontend/`): `npm run test` (um arquivo: `npx vitest run caminho`); lint: `npm run lint`; build: `npm run build`
- Auditoria: `cd backend && composer audit`; `cd frontend && npm audit`

## Regras fixas
- Código, pastas, tabelas, métodos e comentários em inglês. Interface, mensagens e relatórios em português (BR). Responda ao usuário em português.
- Dinheiro sempre em decimal, nunca float. Parâmetros regulatórios vêm da tabela `regulatory_parameters`, nunca do código.
- PDFs de faturas nunca são salvos: ficam só em temporário e são apagados logo após a extração, também em erro.
- SQL só com bindings; validação em Form Requests; autorização por policies; nunca commitar `.env`.
- Laravel e React rodam sem container no desenvolvimento; só o PostgreSQL usa Docker.
- Orçamento etapa 2 (tarefa 10.3): não iniciar sem autorização.
- Não altere as regras de cálculo (RC) nem as decisões D1 a D19 sem confirmar com o usuário.
- Tarifas da ANEEL são sem tributos: converter para a base da fatura só pelo fator `k` (RC-1c). Nunca usar percentual fixo entre Azul e Verde.
