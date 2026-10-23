# EnerDiag — PROJECT_SPEC

Versão 1.1 · 22/10/2026 · Fonte da verdade do projeto (substitui o docx v1.1).

## 0. Como usar

- Trabalhe **uma fase por vez**, na ordem. Tarefa pendente: `- [ ]`; concluída: `- [x]`.
- Toda tarefa tem uma linha **Teste:** (o que verificar). Só marque `[x]` com esse teste passando.
- `Ref:` lista as seções de referência a ler (RN, RC, DB, API, FE, ORC). Não leia o resto.
- Etapa 2 (orçamento automático) está fora do escopo atual: ver Fase 10.
- Valores e artigos da REN 1000 ainda precisam de conferência: ver §9.
- Antes de fechar os cálculos da Fase 3, resolver as pendências regulatórias de §9 que afetam as fórmulas. A validação 3.8 depende das planilhas de referência; o leitor ENEL da tarefa 4.3 depende de faturas anonimizadas e conferidas manualmente.

## 1. Visão e decisões fechadas

**Objetivo.** Sistema web guiado: o cliente (Grupo A) se cadastra, envia 6 a 12 faturas em PDF e recebe, de forma automática, relatório online e em PDF com o potencial de economia mensal e anual. O relatório leva ao contato com a consultoria de eficiência energética.

**Escopo.** Subgrupos A4 (2,3 a 25 kV) e AS (subterrâneo); modalidades Azul (demanda e consumo por posto) e Verde (demanda única); distribuidora ENEL, foco em SP; arquitetura pronta para outras distribuidoras.

**Stack.** Backend: PHP 8.5.12, Laravel 13, Sanctum, PostgreSQL, Pest/PHPUnit. PHP 8.5.12 é a versão-base escolhida por incluir a correção da falha de segurança identificada; Laravel 13 é a versão mais recente definida para o projeto. Não usar versões anteriores e manter os patches de segurança atualizados. Frontend: React, TypeScript, Vite, Tailwind, Recharts, Vitest + React Testing Library (Playwright opcional). Tudo open source.

**Ambiente.** Só o PostgreSQL roda em Docker no desenvolvimento. Laravel e React rodam sem container; a conteinerização completa só na Fase 11.

**Repositório (monorepo).** `backend/` (API Laravel), `frontend/` (SPA), `docs/`, `docker-compose.yml`, `PROJECT_SPEC.md`, `CLAUDE.md`.

**Convenções.** Pastas, arquivos, tabelas, métodos, variáveis e comentários em **inglês**. Interface, mensagens e relatórios em **português (BR)**. `.env` nunca vai ao repositório (só `.env.example`). Código limpo: controllers finos, Form Requests, services, módulo de cálculo isolado (sem HTTP).

### Decisões fechadas

| # | Decisão |
|---|---|
| D1 | Admin vê os **dados extraídos**, nunca os PDFs. PDFs são descartados logo após a extração. |
| D2 | Expurgo automático e definitivo das **simulações** (dados extraídos e resultados dos relatórios) após 180 dias, por rotina diária. O prazo atende à janela de conversão comercial; a conta do cliente permanece. Dados expirados não são reaproveitados em nova simulação. |
| D3 | Só o **e-mail** é verificado (código numérico). Telefone é só contato. |
| D4 | Processamento em fila (Laravel Queue, driver `database`) com polling de status no frontend; até três tentativas, backoff de 60/180 segundos e timeout de 300 segundos por job. |
| D5 | Projeção anual com **tarifas vigentes**; o histórico pago aparece como referência. |
| D6 | Com 6 a 11 faturas: média dos N meses × 12, com aviso. |
| D7 | Token Sanctum guardado **em memória** no React; recarregar a página exige novo login. Futuro: cookie httpOnly. |
| D8 | A regra de demanda ociosa sem ICMS aplica-se em todos os estados. A tolerância de ultrapassagem de 5% e o multiplicador 2× são nacionais; o percentual de ICMS usado na demanda ociosa varia por UF e é configurado pelo admin com vigência. Sem percentual estadual cadastrado, usa-se 18% e o relatório avisa. |
| D9 | Distribuidora sem leitor: aviso de indisponibilidade e registro do lead. |
| D10 | React + TypeScript + Vite. |
| D11 | A conta só é excluída a pedido do titular (LGPD). Contato comercial solicitado fica em `users.contact_requested_at` e não é apagado com a simulação. |
| D12 | O relatório sai automático. O admin pode editar textos explicativos e recomendações, sem alterar entradas nem resultados calculados; cada edição cria nova versão (`report_revisions`). Correção de cálculo exige nova execução; reenvio ao cliente é manual. |
| D13 | Token com 2 horas fixas a partir do login, sem renovação por inatividade. |
| D14 | % de economia = economia mensal média ÷ média mensal paga nas faturas × 100. |
| D15 | Nome da empresa obrigatório; CNPJ opcional. |
| D16 | Tabela de tarifas inclui demanda e consumo (TE e TUSD), atualizada pelo admin a cada reajuste. Sem tarifa vigente: aviso e uso do histórico. |
| D17 | A regra de demanda ociosa aplica-se em todos os estados; não há flag para habilitá-la no frontend. A tolerância de 5% é nacional. O percentual de ICMS da demanda ociosa é parâmetro administrativo por UF, com vigência; sem cadastro estadual, usa-se o padrão nacional de 18% e o relatório apresenta aviso. |
| D18 | DMCR é leitura técnica da memória do medidor, não item faturável. A fatura ENEL anexada mostra UFDR no quadro de faturamento como item de demanda reativa faturável; normalizar UFDR para “Demanda Reativa Excedente” e reconhecer também “DUR” e “Demanda Reativa Indutiva/Capacitiva”. |

## 2. Fases

### Fase 0 — Preparação do ambiente
Ref: §1

- [ ] 0.1 Criar a estrutura do monorepo e o `.gitignore` (`.env`, `vendor`, `node_modules`, arquivos temporários).
  - Teste: existem as pastas previstas em §1; `git check-ignore -v` confirma regras para `.env`, `backend/vendor`, `frontend/node_modules` e temporários; `git status --short` não mostra esses artefatos como arquivos rastreados ou não ignorados.
- [ ] 0.2 `docker-compose.yml` só com PostgreSQL (credenciais via `.env`, volume nomeado).
  - Teste: `docker compose up -d` sobe o banco e o Laravel conecta (`php artisan db:show`).
- [ ] 0.3 Projeto Laravel 13 em `backend/` (PHP 8.5.12), `.env.example` sem segredos, fuso `America/Sao_Paulo`, Sanctum instalado.
  - Teste: `php artisan test` roda e passa; `php artisan migrate` conecta ao PostgreSQL.
- [ ] 0.4 Projeto React + TypeScript + Vite + Tailwind em `frontend/`, com Vitest e React Testing Library.
  - Teste: `npm run build` e `npm run test` passam (teste de exemplo).
- [ ] 0.5 Qualidade: Laravel Pint, ESLint, `composer audit`, `npm audit`.
  - Teste: cada comando roda sem erro no projeto vazio.
- [ ] 0.6 `README.md` inicial: pré-requisitos, subir banco, API, frontend e testes.
  - Teste: seguir o README num clone limpo e chegar à API e ao frontend no ar.
- [ ] 0.7 Preencher no `CLAUDE.md` os comandos reais (seção Comandos).
  - Teste: cada comando listado executa.

### Fase 1 — Banco de dados, seeds e base da API
Ref: DB, RN-7, API (padrões)

- [ ] 1.1 Migrations na ordem das chaves estrangeiras (pais antes das filhas), incluindo as tabelas padrão do Laravel.
  - Teste: `migrate:fresh` sem erro; `migrate:rollback` e `migrate` de novo sem erro.
- [ ] 1.2 Models com relacionamentos, `$fillable` explícito e casts decimais nos valores monetários.
  - Teste: testes unitários dos relacionamentos e do cast decimal (sem ponto flutuante).
- [ ] 1.3 Seeders: `distributors` (ENEL), `regulatory_parameters`, `tariffs` de exemplo (valores fictícios de teste), `quotation_base`, admin e dois clientes fictícios.
  - Teste: `migrate:fresh --seed` passa; seeder de usuários de teste **não roda** em `production`.
- [ ] 1.4 Base da API em `/api/v1`: formato único de erro (`message`, `code`, `errors`), handler de exceções, CORS restrito ao frontend, cabeçalhos de segurança, rate limiters nomeados.
  - Teste: feature tests de 401, 404 e 422 no formato padrão; origem não permitida bloqueada pelo CORS.
- [ ] 1.5 Documentação da API gerada a partir do código (Scribe ou Scramble, após confirmar compatibilidade com Laravel 13).
  - Teste: a geração roda sem erro e um endpoint de exemplo aparece documentado com sucesso e erros.

### Fase 2 — Autenticação e usuários
Ref: RN-1, RN-2, RN-3, API (Auth, Me)

- [ ] 2.1 `POST /auth/register`: valida dados e senha (≥ 10, letras e números, confirmação, bloqueio de senhas comuns ou vazadas), grava consentimento (`consents`: versão, IP, data), cria o cliente com e-mail não verificado e envia código numérico.
  - Teste: sucesso; senha fraca; e-mail duplicado; sem aceite dos termos; e-mail com o código enviado (fake mail).
- [ ] 2.2 `POST /auth/verify-email` e `POST /auth/resend-code`: código com hash, expiração e limite de tentativas.
  - Teste: código correto, errado, expirado, limite de tentativas excedido; reenvio com throttle.
- [ ] 2.3 `POST /auth/login` e `/auth/logout` com Sanctum: token de 2 h fixas, login bloqueado sem e-mail verificado, logout revoga o token.
  - Teste: login ok; credenciais inválidas (401, sem revelar se o usuário existe); e-mail não verificado (403); token expira após 2 h (simular o tempo); token revogado no logout.
- [ ] 2.4 `POST /auth/forgot-password` e `/auth/reset-password`: link de uso único com validade limitada e resposta neutra.
  - Teste: fluxo completo; token reutilizado; token expirado; e-mail inexistente com a mesma resposta.
- [ ] 2.5 Autorização por perfil (admin e cliente) com policies.
  - Teste: cliente em rota admin → 403; cliente acessando recurso de outro cliente → 403 ou 404.
- [ ] 2.6 `GET/PUT /me` e `DELETE /me` (exclusão de dados a pedido do titular).
  - Teste: perfil atualizado; exclusão remove conta e dados relacionados; sem token → 401.
- [ ] 2.7 `POST /me/contact-request`: registra `contact_requested_at` (necessário para D11).
  - Teste: grava a data; sobrevive ao expurgo de simulações (ver 5.5).

### Fase 3 — Motor de cálculo (módulo isolado, sem HTTP)
Ref: RC, DB (tariffs, regulatory_parameters)

- [ ] 3.1 Serviços de parâmetros e de tarifas (parâmetros nacionais e parâmetros de ICMS por UF, todos por vigência; tarifas por vigência, distribuidora, modalidade e posto). Valores em decimal.
  - Teste: tarifa vigente por data; parâmetros nacionais e estaduais resolvidos conforme vigência; ausência de parâmetro estadual de ICMS usa o padrão nacional de 18% e sinaliza o aviso no relatório.
- [ ] 3.2 Faturamento de demanda por mês e posto (RC-1), com regra de demanda ociosa válida em todos os estados e ICMS configurado por UF.
  - Teste: com Dc = 100 kW e ICMS de 18%: Dm 80 → 96,4·T; Dm 105 → 105·T; Dm 120 → 140·T; limite exato Dm = 1,05·Dc; outro percentual estadual produz o resultado correspondente; sem cadastro estadual, aplica 18% e gera aviso.
- [ ] 3.3 Demanda ótima (RC-2).
  - Teste: série sintética com ótimo conhecido; Azul com ponta e fora de ponta separados; Verde com demanda única; empate escolhe a menor demanda; sem multa de ultrapassagem desnecessária.
- [ ] 3.4 Reativos UFER e demanda reativa excedente (RC-3).
  - Teste: série com cobrança (economia = 100% do pago) e sem cobrança (resultado zero, “sem ocorrência no período”).
- [ ] 3.5 Migração Azul ↔ Verde (RC-4).
  - Teste: um cenário em que a Verde vence, um em que a Azul vence e um sem ganho; sem tarifa da outra modalidade retorna “indisponível” com aviso.
- [ ] 3.6 Valoração e projeção anual (RC-5).
  - Teste: 12 meses; 6 meses (média × 12 com aviso); tarifa vencida usa o histórico com aviso.
- [ ] 3.7 Indicadores e séries dos gráficos (RC-6).
  - Teste: valores esperados de economia mensal, anual e %; divisão por zero protegida; séries por mês corretas.
- [ ] 3.8 Benchmark com as planilhas `Analise_Faturas_Energia_Preenchida_*` (quando anexadas).
  - Teste: o resultado do motor iguala a planilha, com tolerância de centavos. Depende de §9.

### Fase 4 — Leitura das faturas (leitor ENEL)
Ref: RN-3, RN-4, RN-5, RN-6

- [ ] 4.1 Contrato de dados e interface `InvoiceReader` por distribuidora, escolhida por `parser_key`.
  - Teste: a ENEL resolve o leitor correto; distribuidora sem leitor lança exceção específica.
- [ ] 4.2 Extração de texto do PDF (biblioteca a avaliar: smalot/pdfparser ou pdftotext) em arquivo temporário, apagado ao final inclusive em erro; rotina diária apaga temporários órfãos com mais de 1 hora.
  - Teste: PDF de exemplo gera texto; após sucesso, falha e limpeza de órfão, nenhum arquivo temporário permanece.
- [ ] 4.3 Leitor ENEL: lê o quadro de faturamento e devolve os campos de RN-5 com a nomenclatura de RN-6; itens ausentes = 0.
  - Teste: fixture anonimizada baseada na fatura anexada distingue as leituras DMCR da memória do medidor da cobrança UFDR no quadro de faturamento; valores esperados conferidos à mão. Anonimizar antes de adicionar a fixture ao repositório.
- [ ] 4.4 Validações pós-extração: campos obrigatórios, total pago presente, mês duplicado, mesma unidade consumidora e coerência de quantidades, tarifas e valores extraídos.
  - Teste: um teste por validação; não exigir que os itens analisados somem o total da fatura, pois impostos e cobranças fora do escopo não são extraídos; falha após enfileirar marca a simulação como `failed` e associa o erro ao arquivo para consulta em `/status`.
- [ ] 4.5 Upload seguro: assinatura real de PDF; 6 a 12 arquivos; limite de 20 MB por arquivo, 120 MB por simulação e 600 páginas no total.
  - Teste: executável renomeado para `.pdf` rejeitado (415); 5 e 13 arquivos rejeitados; limites individual, total e de páginas excedidos rejeitados (413).

### Fase 5 — API de simulação e relatório (cliente)
Ref: RN-3, RN-4, RC, DB, API (Cliente)

- [ ] 5.1 `POST /simulations` (multipart + dados da empresa): valida a requisição e cria a simulação em fila (`queued`); validações de entrada respondem 422 antes de enfileirar.
  - Teste: sucesso; validações; distribuidora sem leitor → 422 com código `DISTRIBUTOR_UNSUPPORTED` e lead registrado; sem token → 401.
- [ ] 5.2 Job de processamento: extrai, valida, grava `invoices` e `invoice_items`, calcula, gera `reports` e atualiza o status (`processing`, `completed` ou `failed`); descarta temporários.
  - Teste: job com fixtures completa; falha de leitura após enfileirar vira `failed` com erro associado ao arquivo; cliente consulta o erro em `/status`; retry é idempotente e não duplica dados; temporários apagados em sucesso, falha e timeout.
- [ ] 5.3 `GET /simulations`, `GET /simulations/{id}/status` e `GET /simulations/{id}/report`.
  - Teste: cada cliente vê só as próprias simulações; transições `queued` → `processing` → `completed` ou `failed`; status de falha inclui código e erros por arquivo; relatório com indicadores e séries.
- [ ] 5.4 PDF do relatório (`GET /simulations/{id}/pdf`) e e-mail ao concluir (biblioteca de PDF a avaliar).
  - Teste: PDF gerado não vazio e com os indicadores; e-mail enviado ao concluir (fake mail).
- [ ] 5.5 Expurgo: comando diário agendado remove simulação e todos os dados relacionados (faturas, itens, relatórios e revisões) após 180 dias.
  - Teste: simulação com 181 dias e seus dados relacionados removidos; com 179 dias mantida; conta e `contact_requested_at` preservados.
- [ ] 5.6 `POST /leads` (interesse em distribuidora não atendida).
  - Teste: grava o lead; validação de e-mail; limite de requisições.

### Fase 6 — API do admin
Ref: RN-1, DB, API (Admin)

- [ ] 6.1 Clientes e simulações: `GET /admin/customers`, `/customers/{id}`, `/simulations`, `/simulations/{id}` (dados extraídos, nunca PDFs), com paginação.
  - Teste: admin ok; cliente 403; paginação; nenhum campo de arquivo PDF exposto.
- [ ] 6.2 Relatórios: `PUT /admin/reports/{id}` edita textos explicativos e recomendações e cria nova versão em `report_revisions` (autor e data); resultados calculados são somente leitura. `POST /admin/reports/{id}/resend` reenvia manualmente.
  - Teste: edição textual gera revisão com autor sem alterar resultados; tentativa de alterar resultado é rejeitada; reenvio dispara e-mail; cliente 403.
- [ ] 6.3 Tarifas: `GET/POST/PUT /admin/tariffs`, sem sobreposição de vigência; tarifa vigente ou histórica não pode ser sobrescrita, alterações criam nova versão com nova vigência.
  - Teste: cria tarifa; vigência sobreposta rejeitada (422); versão histórica permanece inalterada após reajuste; cliente 403.
- [ ] 6.4 Parâmetros: `GET/PUT /admin/parameters`; alteração cria nova versão com vigência, preservando valores usados por relatórios existentes.
  - Teste: admin edita parâmetros nacionais e percentuais de ICMS por UF com vigência; versão anterior permanece inalterada; cliente 403.
- [ ] 6.5 `GET /admin/leads` (leads de distribuidora não atendida e pedidos de contato).
  - Teste: lista paginada; cliente 403.
- [ ] 6.6 Fechar a documentação da API: todos os endpoints com exemplos e erros, e coleção Postman/OpenAPI.
  - Teste: a geração roda; conferir que cada endpoint de §6 está documentado.

### Fase 7 — Frontend: base
Ref: FE, RN-7

- [ ] 7.1 Estrutura de pastas (`features`, `components`, `pages`, `services/api`, `hooks`, `routes`, `types`) e configuração do Tailwind.
  - Teste: `npm run build` e `npm run test` passam.
- [ ] 7.2 Tema claro e escuro com botão, escuro confortável (nunca preto absoluto), preferência salva e contraste WCAG AA.
  - Teste: alternar o tema persiste a preferência; verificação de contraste das paletas.
- [ ] 7.3 Cliente HTTP com token em memória; erro 401 leva ao login; erros HTTP mapeados para as páginas de erro.
  - Teste: mock de 401 redireciona; mock de 422 e 429 exibe a mensagem correta.
- [ ] 7.4 Rotas protegidas por perfil (cliente e admin).
  - Teste: sem login vai ao login; cliente em rota admin vê 403.
- [ ] 7.5 Páginas de erro (400, 401, 403, 404, 413, 415, 422, 429, 500) e de manutenção (503) com previsão de retorno.
  - Teste: cada código renderiza a mensagem amigável; a 503 mostra a previsão.
- [ ] 7.6 Layout responsivo base (desktop, tablet e mobile).
  - Teste: renderização nos três breakpoints; checagem manual no celular.

### Fase 8 — Frontend: fluxo do cliente
Ref: FE, RN-2, RN-4, RC-6

- [ ] 8.1 Landing page (proposta de valor, como funciona, benefícios, segurança dos dados, chamada para o cadastro).
  - Teste: seções renderizam; o botão leva ao cadastro; responsiva.
- [ ] 8.2 Cadastro (nome, e-mail, telefone, senha e confirmação, indicador de força, informativo de segurança e aceite) e verificação por código com reenvio.
  - Teste: validações de formulário; envio sem aceite bloqueado; fluxo de código com erro e reenvio.
- [ ] 8.3 Login e recuperação de senha (“Esqueci minha senha”, redefinição).
  - Teste: login ok e inválido; fluxo de redefinição.
- [ ] 8.4 Envio das faturas: dados da empresa, subestação (mesmo endereço ou alternativo), instrução 12 ideal e 6 mínimo, upload múltiplo com erro por arquivo e limites de tamanho/páginas, distribuidora em lista, botão “Simular”.
  - Teste: bloqueia fora de 6 a 12 e arquivo que não seja PDF; distribuidora sem leitor mostra aviso e registra o lead.
- [ ] 8.5 Acompanhamento do processamento por polling e tratamento de falhas assíncronas por arquivo.
  - Teste: API mockada em `queued`, `processing`, `completed` e `failed`; erros de validação síncronos e de leitura assíncronos mostram mensagens por arquivo.
- [ ] 8.6 Relatório online: indicadores, gráficos (demanda registrada × contratada × ótima, reativos, Azul × Verde), avisos (menos de 12 faturas, tarifa desatualizada, “sem ganho”), botão de PDF e botão de contato (WhatsApp ou e-mail) que chama `contact-request`.
  - Teste: renderiza com dados de exemplo; avisos aparecem nas condições certas; o botão de contato registra o pedido.
- [ ] 8.7 Perfil e exclusão de dados (LGPD).
  - Teste: atualiza o perfil; exclusão pede confirmação e desloga.

### Fase 9 — Frontend: área do admin
Ref: FE, API (Admin)

- [ ] 9.1 Acesso administrativo e painel.
  - Teste: só o perfil admin entra; demais veem 403.
- [ ] 9.2 Clientes e contatos; simulações e dados extraídos (nunca PDFs).
  - Teste: listas paginadas; detalhe exibe os dados extraídos.
- [ ] 9.3 Relatórios: visualizar, editar textos explicativos/recomendações, reenviar; histórico de versões; resultados calculados somente leitura.
  - Teste: edição cria versão sem alterar resultados; reenvio chama a API.
- [ ] 9.4 Tabela de tarifas e parâmetros regulatórios.
  - Teste: criar e editar tarifa; vigência sobreposta exibe o erro da API.
- [ ] 9.5 Leads e pedidos de contato.
  - Teste: listagem e filtros básicos.

### Fase 10 — Orçamento
Ref: ORC

- [ ] 10.1 **Etapa 1 (agora):** endpoints admin para a tabela `quotation_base` (hora de consultoria, diária do analisador, hora de projeto de banco de capacitores).
  - Teste: CRUD restrito ao admin; cliente 403.
- [ ] 10.2 **Etapa 1 (agora):** área do admin no frontend (estrutura preparatória, sem regra ativa).
  - Teste: visível só ao admin; edita os valores da tabela.
- [ ] 10.3 **Etapa 2 (não iniciar sem autorização):** orçamento em PDF A4, enviado ao cliente após liberação do admin.
  - Teste: definido quando a etapa 2 começar.

### Fase 11 — Segurança, revisão final e operação
Ref: RN-7, §9

- [ ] 11.1 Checklist de segurança de RN-7 (SQL Injection, XSS e CSP, CORS, rate limiting, acesso a dados de terceiros, upload, logs, dependências).
  - Teste: feature tests cobrem entradas contra SQL Injection e acesso entre usuários; teste de saída confirma escape contra XSS; testes de configuração verificam CSP, CORS e rate limits; testes de upload cobrem tipo, tamanho e quantidade; teste de logs confirma ausência de dados pessoais; `composer audit` e `npm audit` sem vulnerabilidades críticas ou altas sem plano de correção.
- [ ] 11.2 Teste de ponta a ponta do fluxo completo (Playwright, opcional).
  - Teste: cadastro → verificação → envio → relatório → PDF por e-mail.
- [ ] 11.3 Conteinerização completa (API e frontend), HTTPS, worker de fila com até três tentativas (backoff de 60/180 segundos, timeout de 300 segundos e `retry_after` maior que o timeout), agendador (expurgo e limpeza de temporários órfãos) em produção; admin criado por comando próprio.
  - Teste: `docker compose` de produção sobe tudo; worker processa e recupera jobs conforme configuração; agendador executa expurgo e limpeza; seeder de teste não roda em produção.
- [ ] 11.4 README de implantação e documentação final.
  - Teste: seguir o README num ambiente limpo.
- [ ] 11.5 Fechar as pendências de §9 (REN 1000 e planilhas).
  - Teste: cada item de §9 resolvido ou registrado como decisão.

## 3. Regras de negócio (RN)

**RN-1 Perfis.** Cliente: só o próprio cadastro, envio de faturas, simulações e relatórios. Admin: acesso total (usuários, dados extraídos, tarifas, parâmetros, edição de relatórios, estrutura de orçamento). O admin nunca vê PDFs.

**RN-2 Autenticação.** E-mail confirmado por código numérico antes do primeiro login. Senha: mínimo 10 caracteres, letras e números, confirmação, bloqueio de senhas comuns ou vazadas; hash Bcrypt. Sanctum Bearer, 2 h fixas; logout revoga. Recuperação por link de uso único. Reenvio de código com expiração e limite de tentativas. Rate limiting em login, códigos e upload.

**RN-3 Privacidade (LGPD).** Aceite explícito dos termos, gravando data, hora, IP e versão em `consents`. Política de Privacidade e Termos de Uso publicados (texto validado por assessoria jurídica). Exclusão de dados a pedido. PDFs ficam só em temporário e são apagados logo após a extração, também em falha. Simulações expiram em 180 dias; a conta permanece.

**RN-4 Entrada de faturas.** Somente PDF, de 6 a 12 por simulação (ideal 12), até 20 MB por arquivo, 120 MB por simulação e 600 páginas no total. Campos: nome da empresa (obrigatório), CNPJ (opcional), distribuidora, potência do transformador em kVA (opcional), endereço da empresa e checkbox “endereço da subestação é o mesmo da fatura” (se desmarcado, informar o alternativo).

**RN-5 Extração.** Um leitor por distribuidora (`parser_key`); hoje só ENEL; ler qualquer layout é meta futura. Técnica: texto do PDF, foco no quadro de faturamento; OCR só como contingência. Campos por fatura: mês de referência; unidade consumidora; subgrupo e modalidade; demanda contratada e medida (ponta e fora de ponta na Azul; única na Verde); consumo ativo em kWh (ponta e fora de ponta); tarifas-base de demanda e de consumo (TE e TUSD); quantidades e valores faturados de ultrapassagem e demanda ociosa; consumo reativo (UFER), discriminado em ponta indutivo, fora de ponta indutivo e madrugada/fim de semana capacitivo; item faturável de demanda reativa excedente, se houver, reconhecendo os rótulos “UFDR”, “Demanda Reativa Excedente”, “DUR” ou “Demanda Reativa Indutiva/Capacitiva”; valor total pago. DMCR é leitura técnica da memória do medidor, não é item faturável e não deve ser extraída para a simulação nem tratada como cobrança. As tarifas de ultrapassagem e da demanda ociosa são derivadas por cálculo e não precisam ser extraídas. A demanda reativa excedente usa a tarifa de demanda correspondente ao posto (ponta/fora de ponta ou única na Verde); o consumo reativo usa as tarifas de consumo ativo correspondentes aos postos ponta/fora de ponta. Consumo e tarifas TE/TUSD são usados na comparação de modalidade Azul × Verde, mas não representam economia por redução de consumo. Fora do escopo: bandeira tarifária, perdas, impostos e taxa municipal. Item ausente na fatura = 0 (“sem ocorrência no período”). Validações: presença do valor total pago e coerência de quantidades, tarifas e valores extraídos; campos obrigatórios; mês duplicado; mesma unidade consumidora; 6 a 12 arquivos. Erros de validação da requisição respondem 422 antes de enfileirar; falhas de extração após enfileirar atualizam a simulação para `failed` e disponibilizam erro por arquivo em `/status`. Logs sem dados pessoais; cliente pode reenviar.

**RN-6 Nomenclatura interna padrão.** Os leitores normalizam os rótulos específicos de cada distribuidora para os conceitos abaixo. Os nomes apresentados são rótulos ENEL ou variações identificadas; não se presume que outras distribuidoras usem os mesmos termos.

| Rótulo ENEL / variação reconhecida | Conceito interno | Uso |
|---|---|---|
| Demanda Contratada (Ponta e Fora de Ponta) | Demanda contratada (Dc) | kW. Azul: ponta e fora de ponta; Verde: única. |
| Demanda Registrada (Medida) | Demanda medida (Dm) | Maior potência ativa do ciclo (kW). |
| Demanda de Ultrapassagem | Ultrapassagem de demanda | Quantidade e valor faturado; tarifa derivada pelo cálculo. |
| Demanda ociosa (na fatura ENEL pode aparecer como DEMANDA LEI ESTADUAL 16.886/18) | Parcela não utilizada da demanda | Demanda contratada não consumida, considerada sem ICMS em todos os estados; tarifa derivada pelo cálculo. |
| UFER (Ponta Indutivo / Fora de Ponta Indutivo / Madrugada ou Fim de Semana Capacitivo) | Consumo reativo excedente | Valor/quantidade faturados; valoração usa as tarifas de consumo ativo correspondentes. |
| UFDR (normalizado como Demanda Reativa Excedente; também pode aparecer como DUR ou Demanda Reativa Indutiva/Capacitiva) | Demanda reativa excedente faturável | Se houver cobrança, valor faturado; valoração usa a tarifa de demanda correspondente. DMCR é leitura da memória do medidor, não um item faturável. |
| Consumo Ponta / Fora de Ponta (TE e TUSD) | Consumo ativo (Ca) | kWh valorados pelas tarifas TE e TUSD; usados na comparação Azul × Verde. |

**RN-7 Segurança (API e frontend).**
- SQL Injection: Eloquent ou Query Builder com bindings; nunca concatenar SQL; toda entrada validada por Form Requests.
- XSS: saídas escapadas, cabeçalhos de segurança e CSP; o frontend nunca renderiza HTML não confiável.
- CORS restrito ao domínio do frontend; HTTPS obrigatório em produção.
- Autorização por policies: cliente só acessa os próprios recursos (evitar acesso por troca de ID); rotas admin só para admin.
- Mass assignment: modelos com campos permitidos explícitos.
- Upload: validar tipo real (assinatura de PDF), até 20 MB por arquivo, 120 MB por simulação e 600 páginas no total; temporário fora de diretório público; nunca executar.
- Logs sem dados pessoais; segredos só no `.env`; `composer audit` e `npm audit`.
- Observar o OWASP Top 10 (CSRF com cookie, enumeração de usuários no login e na recuperação de senha).

## 4. Regras de cálculo (RC)

**RC-0 Princípios.** Cálculo no backend, determinístico, em decimal (nunca ponto flutuante). Tarifas mantêm 5 casas decimais; grandezas de demanda (kW), consumo (kWh) e energia reativa (kVArh) mantêm 3 casas decimais, conforme a fatura ENEL; valores monetários resultantes de multiplicações e somas são expressos com 2 casas decimais. Arredondar cada valor monetário calculado para centavos pelo método half-up antes de somar os itens; totais são a soma dos valores já arredondados. Parâmetros nacionais em `regulatory_parameters`, editáveis pelo admin, nunca fixos no código: tolerância 5%, multiplicador de ultrapassagem 2, fator de potência de referência 0,92 e percentual padrão de ICMS de 18%. O percentual de ICMS utilizado no cálculo da demanda ociosa pode ser configurado por UF, também pelo admin e versionado por vigência. A regra de demanda ociosa sem ICMS aplica-se em todos os estados, sem flag de habilitação; se faltar percentual para a UF, usa-se o padrão nacional de 18% e o relatório apresenta aviso. Reativos e ultrapassagem devem existir e ser testados com dados sintéticos, mesmo que as faturas reais não tenham essas cobranças.

**RC-1 Faturamento da demanda** (por mês e por posto; Azul: ponta e fora de ponta; Verde: única). T = tarifa de demanda do posto.

| Condição | Custo |
|---|---|
| Dm ≤ Dc | `Dm·T + (Dc − Dm)·T·(1 − ICMS_aplicado)` em todos os estados; `ICMS_aplicado` é o percentual configurado para a UF e aplica-se somente à parcela ociosa. Sem configuração estadual vigente, `ICMS_aplicado = 18%` e o relatório recebe aviso. |
| Dc < Dm ≤ 1,05·Dc | `Dm·T` (tarifa normal, sem multa) |
| Dm > 1,05·Dc | `Dc·T + (Dm − Dc)·2·T` |

Exemplos (Dc = 100 kW): Dm 80 → 96,4·T; Dm 105 → 105·T; Dm 120 → 140·T.

**RC-2 Demanda ótima.** Testar candidatos de demanda com passo de 1 kW numa faixa ajustável (padrão e limites regulatórios a definir; ver §9). Para cada candidato, recalcular o custo de toda a série com RC-1. A ótima é a de menor custo total (empate: a menor demanda). Azul: ponta e fora de ponta otimizadas separadamente; Verde: demanda única. Economia = custo atual − custo com a ótima.

**RC-3 Reativos.** Somar os valores faturados de UFER e de demanda reativa excedente, quando houver (rótulos aceitos: “UFDR”, “DUR” e “Demanda Reativa Indutiva/Capacitiva”). DMCR é leitura técnica, não cobrança, e não entra na soma. Economia considerada = 100% das cobranças reativas faturadas (correção do fator de potência com banco de capacitores). Sem cobrança: zero.

**RC-4 Migração Azul ↔ Verde.** Comparar o custo anual otimizado nas duas modalidades com os mesmos dados medidos de demanda e consumo. Aplicar a cada modalidade suas tarifas de demanda e consumo (TE e TUSD) da tabela de tarifas; os kWh de ponta e fora de ponta permanecem iguais entre os cenários. A modalidade não vigente usa a tabela de tarifas. Saída: modalidade mais econômica com a diferença de custo, ou “Sem ganho financeiro na migração”. Sem tarifa necessária da outra modalidade: resultado indisponível com aviso.

**RC-5 Valoração e projeção.**
- A) Projeção anual: tarifas vigentes mais recentes (fatura mais recente completada pela tabela de tarifas) aplicadas a todo o histórico; economia média mensal × 12.
- B) Histórico de referência: valor efetivamente pago, com as tarifas de cada fatura.
- 6 a 11 faturas: média dos N meses × 12, com aviso explicativo.
- Sem tarifa vigente cadastrada: aviso e uso do histórico.

**RC-6 Indicadores.** O relatório apresenta a economia mensal média (R$) e a projeção anual (R$) do total e de cada componente: demanda, ultrapassagem, reativos e migração. A projeção anual de cada valor é sua média mensal multiplicada por 12; o total corresponde à soma dos componentes. O percentual total é calculado como economia mensal média total ÷ valor total médio pago nas faturas × 100. O valor total pago é usado como referência para o percentual. Consumo não gera economia por redução na versão 1, mas seu custo com tarifas TE/TUSD é considerado no componente de migração Azul × Verde. Séries dos gráficos por mês: demanda registrada × contratada atual × ótima; reativos; Azul × Verde.

## 5. Banco de dados (DB)

Tabelas em inglês. Tarifas com 5 casas decimais; grandezas de demanda, consumo e energia reativa com 3 casas decimais; valores monetários com 2 casas decimais. Usar tipos decimais, nunca ponto flutuante.

- `users` (id, name, email, phone, password_hash, role, email_verified_at, contact_requested_at)
- `consents` (id, user_id, terms_version, accepted_at, ip_address)
- `customer_profiles` (user_id, company_name, cnpj, distributor_id, transformer_power_kva, address_details, substation_same_address, substation_address)
- `distributors` (id, name, state, parser_key, active)
- `tariffs` (id, distributor_id, subgroup, tariff_modality, time_slot, component, rate, valid_from, valid_to); `component`: `demand`, `energy_te`, `energy_tusd`
- `regulatory_parameters` (id, key, value, state nullable, valid_from, valid_to); parâmetros nacionais usam `state = null`, incluindo o percentual padrão `idle_demand_icms_rate = 0.18`; uma configuração por UF substitui o padrão durante sua vigência. Sem sobreposição de vigências para a mesma chave e escopo.
- `simulations` (id, user_id, status, months_count, total_monthly_savings, total_yearly_savings, expires_at)
- `invoices` (id, simulation_id, reference_month, consumer_unit, subgroup, tariff_modality, total_paid `DECIMAL(14,2)`); unicidade de `(simulation_id, reference_month)`; a unidade consumidora deve ser a mesma em todas as faturas da simulação.
- `invoice_items` (id, invoice_id, item_type, tariff_component, time_slot, reactive_nature, quantity, unit, tariff_rate, amount, source_label); `quantity DECIMAL(14,3)`, `tariff_rate DECIMAL(14,5)` e `amount DECIMAL(14,2)`; `time_slot`: ponta, fora de ponta, única ou madrugada/fim de semana; campos de componente, posto e natureza reativa usam valor `not_applicable` quando não se aplicam, permitindo unicidade de `(invoice_id, item_type, tariff_component, time_slot, reactive_nature)`. Tipos representam demanda contratada/medida, consumo ativo, ultrapassagem, demanda ociosa, UFER e demanda reativa excedente. Tarifas de ultrapassagem e demanda ociosa são derivadas no cálculo, não extraídas como tarifas independentes.
- `reports` (resultados calculados persistidos: totais mensal/anual, valores por componente, percentual de economia, séries dos gráficos e avisos; `algorithm_version`, `tariff_version_ids` e `regulatory_parameter_version_ids` guardam a rastreabilidade sem duplicar valores das tabelas versionadas); `report_revisions` (histórico de textos explicativos/recomendações editados, autor e data; resultados calculados são somente leitura)
- `leads` (id, email, distributor_name, reason, created_at)
- `verification_codes` (id, user_id, code_hash, expires_at, attempts)
- `quotation_base` (tabela de custos preparatória)
- Padrão do Laravel: `password_reset_tokens`, `personal_access_tokens`, `jobs`, `failed_jobs`

Ordem das migrations: pais antes das filhas, para `migrate:fresh --seed` rodar sem erro.

## 6. API (`/api/v1`)

Padrões: JSON; erro em formato único (`message`, `code`, `errors`); códigos HTTP corretos; paginação nas listagens; cada endpoint documentado com exemplos e erros.

| Grupo | Endpoints |
|---|---|
| Auth (público) | `POST /auth/register`, `/auth/verify-email`, `/auth/resend-code`, `/auth/login`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password` |
| Cliente | `GET/PUT /me`, `DELETE /me`, `POST /me/contact-request`, `GET /simulations`, `POST /simulations`, `GET /simulations/{id}/status`, `/report`, `/pdf`, `POST /leads` |
| Admin | `GET /admin/customers`, `/customers/{id}`, `/simulations`, `/simulations/{id}`, `PUT /admin/reports/{id}`, `POST /admin/reports/{id}/resend`, `GET/POST/PUT /admin/tariffs`, `GET/PUT /admin/parameters`, `GET /admin/leads`, endpoints de `quotation_base` |

## 7. Frontend (FE)

**Páginas.** Públicas: landing, cadastro, verificação de e-mail, login, esqueci e redefinir senha, erros e manutenção. Cliente: dados e envio das faturas, acompanhamento, relatório online com PDF, perfil. Admin: acesso administrativo, painel, clientes e contatos, simulações e dados extraídos, relatórios, tarifas e parâmetros, leads, orçamento (estrutura).

**UX.** Responsivo (desktop, tablet, mobile). Tema claro e escuro com botão, escuro confortável (nunca preto absoluto), paletas consagradas, contraste WCAG AA, preferência salva. Erros HTTP amigáveis e sem detalhes técnicos: 400, 401 (login inválido ou sessão expirada), 403, 404, 413 e 415 (arquivo grande ou tipo não aceito), 422 para validação antes de enfileirar, 429, 500; manutenção (503) com previsão de retorno. Falhas de extração após o enfileiramento aparecem no estado `failed` com mensagem associada a cada arquivo, consultada por polling em `/status`.

**Envio das faturas.** Instrução: ideal 12 faturas, mínimo 6. Upload múltiplo com erro por arquivo (limites: 20 MB por arquivo, 120 MB por simulação, 600 páginas no total). Distribuidora em lista das atendidas (hoje ENEL); outra gera aviso e lead. “Simular” inicia a fila e mostra `queued`, `processing`, `completed` ou `failed` por polling. Falha posterior à resposta inicial é exibida no status, com detalhe por arquivo.

**Relatório.** Elegante e simples, fiel às faturas. Destaque para economia mensal e anual e %. Gráficos em Recharts. Avisos: menos de 12 faturas, tarifa desatualizada, “sem ganho na migração”. Botões: PDF e contato comercial (WhatsApp ou e-mail, registra `contact-request`).

**Segurança.** React escapa por padrão; proibido HTML bruto com conteúdo não confiável. Validação no front é só experiência; a segurança fica na API. Token só em memória (D7).

## 8. Orçamento (ORC)

**Etapa 1 (agora).** Tabela `quotation_base` com valor da hora técnica de consultoria, diária do analisador de qualidade de energia (24h/7d) e hora de projeto de banco de capacitores, atualizada manualmente pelo admin (futuro: por planilha). Área do admin preparada, sem regra ativa. Reservar campo para orçamento com materiais (banco de capacitores e outros), a detalhar na etapa 2.

**Etapa 2 (não iniciar sem autorização).** Orçamento em PDF A4 com cabeçalho e rodapé, dados fictícios da consultoria (CNPJ, endereço, e-mail, telefone). Itens com custo unitário e total, mais o total geral. Validade de 30 dias. Responsabilidade técnica de Engenheiro Eletricista registrado no CREA-SP, com emissão de ART. Enviado ao cliente por e-mail só após revisão e liberação do admin.

## 9. A conferir (REN 1000 e planilhas)

REN 1000 e planilhas ainda não foram cruzadas com este spec.

- [ ] Artigos citados (Art. 301 ultrapassagem; Art. 302 fator de potência 0,92), tolerância de 5% e multiplicador 2.
- [ ] Conferir a fundamentação regulatória/fiscal da regra de demanda ociosa sem ICMS e cadastrar os percentuais de ICMS por UF com suas vigências; o padrão nacional é 18% com aviso quando usado por falta de cadastro estadual.
- [ ] Faixa padrão da demanda ótima, limites e prazos para alterar a demanda contratada, demanda mínima contratável.
- [ ] Restrições regulatórias para troca de modalidade (RC-4).
- [ ] Benchmark: casos das planilhas como testes do motor (tarefa 3.8).
- [ ] Bibliotecas a escolher: leitura de PDF, geração de PDF, documentação da API (compatibilidade com Laravel 13).
- [ ] Textos jurídicos (Política de Privacidade e Termos) e canal de contato comercial (WhatsApp ou e-mail).
- [ ] Dados da empresa fictícia do orçamento (etapa 2).
