# EnerDiag — PROJECT_SPEC

Versão 1.5 · 24/10/2026 · Fonte da verdade do projeto (o docx v1.1 está obsoleto; em caso de divergência, vale este arquivo).

## 0. Como usar

- Trabalhe **uma fase por vez**, na ordem. Tarefa pendente: `- [ ]`; concluída: `- [x]`.
- Toda tarefa tem uma linha **Teste:** (o que verificar). Só marque `[x]` com esse teste passando.
- `Ref:` lista as seções de referência a ler (RN, RC, DB, API, FE, ORC). Não leia o resto.
- Etapa 2 (orçamento automático) está fora do escopo atual: ver Fase 10.
- Valores e artigos da REN 1000 conferidos na v1.5: ver §9 e o documento `docs/REN 1000.pdf`.
- A validação 3.13 depende das planilhas de referência; o leitor ENEL da tarefa 4.3 tem a fixture real conferida em §9 (`docs/Enel Hesap Agos.pdf`, fatura de agosto de 2025).

## 1. Visão e decisões fechadas

**Objetivo.** Sistema web guiado: o cliente (Grupo A) se cadastra, envia 6 a 12 faturas em PDF e recebe, de forma automática, relatório online e em PDF com o potencial de economia mensal e anual. O relatório leva ao contato com a consultoria de eficiência energética.

**Escopo.** Subgrupos A4 (2,3 a 25 kV) e AS (subterrâneo); modalidades Azul (demanda e consumo por posto) e Verde (demanda única); distribuidora ENEL, foco em SP; arquitetura pronta para outras distribuidoras.

**Stack.** Backend: PHP 8.5 sempre no patch de segurança mais recente (mínimo 8.5.12, que corrige falhas críticas de execução remota de código), Laravel 13, Sanctum, PostgreSQL, Pest/PHPUnit. Não usar versões anteriores do PHP e manter os patches de segurança atualizados, inclusive nas imagens do Codespaces e do Docker. Frontend: React, TypeScript, Vite, Tailwind, Recharts, Vitest + React Testing Library (Playwright opcional). Tudo open source.

**Ambiente.** Só o PostgreSQL roda em Docker no desenvolvimento. Laravel e React rodam sem container; a conteinerização completa só na Fase 11.

**Repositório (monorepo).** `backend/` (API Laravel), `frontend/` (SPA), `docs/` (`PROJECT_SPEC.md` e `AGENT.md`), `docker-compose.yml`.

**Convenções.** Pastas, arquivos, tabelas, métodos, variáveis e comentários em **inglês**. Interface, mensagens e relatórios em **português (BR)**. `.env` nunca vai ao repositório (só `.env.example`). Código limpo: controllers finos, Form Requests, services, módulo de cálculo isolado (sem HTTP).

### Decisões fechadas

| # | Decisão |
|---|---|
| D1 | Admin vê os **dados extraídos**, nunca os PDFs. PDFs são descartados logo após a extração. |
| D2 | Expurgo automático e definitivo das **simulações** (dados extraídos e resultados dos relatórios) após 180 dias, por rotina diária. O prazo atende à janela de conversão comercial; a conta do cliente permanece. Dados expirados não são reaproveitados em nova simulação. |
| D3 | Só o **e-mail** é verificado (código numérico). Telefone é só contato. |
| D4 | Processamento em fila (Laravel Queue, driver `database`) com polling de status no frontend; até três tentativas, backoff de 60/180 segundos e timeout de 300 segundos por job. O PDF permanece em disco temporário privado até o job terminar ou falhar de vez, inclusive durante as novas tentativas. |
| D5 | Projeção anual com **tarifas vigentes**; o histórico pago aparece como referência. |
| D6 | Com 6 a 11 faturas: média dos N meses × 12, com aviso. |
| D7 | Token Sanctum guardado **em memória** no React; recarregar a página exige novo login. Futuro: cookie httpOnly. |
| D8 | **Demanda ociosa:** a proporção `r` vem da própria fatura. Na ENEL SP, a Lei Estadual 16.886/18 isenta o ICMS sobre a parcela não utilizada da demanda: (1) pela tributação: `r` = 1 − alíquota de ICMS das linhas normais de demanda, quando a linha ociosa traz ICMS 0%; (2) pelo preço unitário, se a linha o trouxer (ociosa ÷ normal); (3) reserva de 18% (`r` = 0,82), com aviso. Meses com Dm < Dc sem linha ociosa indicam cobrança integral (`r` = 1). A associação das duas linhas de mesmo rótulo aos postos ponta e fora de ponta é feita por matching determinístico pelo valor esperado (RC-1a), neutralizando inversões da ordem física da fatura. A tolerância de 5% e o multiplicador 2× sobre o excedente da contratada são nacionais (REN 1000/2021, Art. 298 a 301). |
| D9 | Distribuidora sem leitor: aviso de indisponibilidade e registro do lead. |
| D10 | React + TypeScript + Vite. |
| D11 | A conta só é excluída a pedido do titular (LGPD). Contato comercial solicitado fica em `users.contact_requested_at` e não é apagado com a simulação. |
| D12 | O relatório sai automático. O admin pode editar textos explicativos e recomendações, sem alterar entradas nem resultados calculados; cada edição cria nova versão (`report_revisions`). Correção de cálculo exige **recálculo** a partir dos dados extraídos (`POST /admin/simulations/{id}/recalculate`); erro de extração exige novo envio dos PDFs pelo cliente. Reenvio ao cliente é manual. |
| D13 | Token com 2 horas fixas a partir do login, sem renovação por inatividade. |
| D14 | % de economia = economia mensal média ÷ valor médio total pago nas faturas × 100, rotulado no relatório como “% do valor médio pago no período”, com a observação de que a economia usa tarifas atuais e o valor pago usa as tarifas históricas. O valor total pago (`invoices.total_paid`) inclui o total geral do documento fiscal (com COSIP e outros encargos), refletindo o desembolso real do cliente. |
| D15 | Nome da empresa obrigatório; CNPJ opcional. |
| D16 | Tabela de tarifas inclui demanda e consumo (TE e TUSD), com a indicação da base tributária (`includes_taxes`), atualizada a cada reajuste (importação do CSV da ANEEL ou lançamento pelo admin). O motor trabalha na base “com tributos” (preço unitário da fatura); tarifas sem tributos são convertidas pelo fator de calibração (D19). Sem tarifa vigente: aviso e uso do histórico. |
| D17 | Unificada em D8 (sem flag por UF e sem tabela de ICMS por UF na versão 1). |
| D18 | DMCR é leitura técnica da memória do medidor, não item faturável. A fatura ENEL traz UFDR no quadro de faturamento como item de demanda reativa faturável; normalizar UFDR para “Demanda Reativa Excedente” e reconhecer também “DUR” e “Demanda Reativa Indutiva/Capacitiva”. UFER é consumo reativo excedente faturado pela tarifa TE correspondente. |
| D19 | **Tarifas da outra modalidade:** vêm da ANEEL (tarifas de aplicação, sem tributos), importadas por CSV no painel do admin (importação automática é opcional, tarefa 11.3). Não existe percentual fixo entre Azul e Verde. A TE é igual nas duas modalidades e vem da fatura; a TUSD de demanda e a TUSD de consumo (especialmente Consumo Ponta da Verde, substancialmente mais cara que na Azul) vêm obrigatoriamente da tabela da ANEEL, convertidas para “com tributos” pelo fator `k` lido da fatura do mês (preço com tributos ÷ tarifa sem tributos, RC-1c). É proibido reaproveitar a TUSD de Consumo Ponta da fatura Azul no cenário Verde. Sem tarifa da vigência para ambos os componentes (demanda e consumo ponta): migração indisponível, com aviso. |

## 2. Fases

### Fase 0 — Preparação do ambiente
Ref: §1

- [x] 0.1 Criar a estrutura do monorepo e o `.gitignore` (`.env`, `vendor`, `node_modules`, arquivos temporários).
  - Teste: existem as pastas previstas em §1; `git check-ignore -v` confirma regras para `.env`, `backend/vendor`, `frontend/node_modules` e temporários; `git status --short` não mostra esses artefatos como arquivos rastreados ou não ignorados.
- [x] 0.2 `docker-compose.yml` só com PostgreSQL (credenciais via `.env`, volume nomeado).
  - Teste: `docker compose up -d` sobe o banco e o Laravel conecta (`php artisan db:show`).
- [x] 0.3 Projeto Laravel 13 em `backend/` (PHP 8.5, mínimo 8.5.12), `.env.example` sem segredos, fuso `America/Sao_Paulo`, Sanctum instalado.
  - Teste: `php -v` mostra 8.5.12 ou superior; `php artisan test` roda e passa; `php artisan migrate` conecta ao PostgreSQL.
- [x] 0.4 Projeto React + TypeScript + Vite + Tailwind em `frontend/`, com Vitest e React Testing Library.
  - Teste: `npm run build` e `npm run test` passam (teste de exemplo).
- [x] 0.5 Qualidade: Laravel Pint, ESLint, `composer audit`, `npm audit`.
  - Teste: cada comando roda sem erro no projeto vazio.
- [x] 0.6 `README.md` inicial: pré-requisitos, subir banco, API, frontend e testes.
  - Teste: seguir o README num clone limpo e chegar à API e ao frontend no ar.
- [x] 0.7 Preencher em `docs/AGENT.md` os comandos reais (seção Comandos).
  - Teste: cada comando listado executa.
- [x] 0.8 Limites de upload no PHP e no servidor web: `upload_max_filesize` ≥ 20 MB, `post_max_size` ≥ 128 MB, `max_file_uploads` ≥ 12, limite do servidor ou proxy compatível (por exemplo `client_max_body_size`) e timeouts adequados.
  - Teste: requisição com 12 PDFs somando cerca de 100 MB é aceita pelo servidor; acima do limite retorna 413.

### Fase 1 — Banco de dados, seeds e base da API
Ref: DB, RN-7, API (padrões)

- [x] 1.1 Migrations na ordem das chaves estrangeiras (pais antes das filhas), incluindo as tabelas padrão do Laravel.
  - Teste: `migrate:fresh` sem erro; `migrate:rollback` e `migrate` de novo sem erro.
- [x] 1.2 Models com relacionamentos, `$fillable` explícito e casts decimais nos valores monetários.
  - Teste: testes unitários dos relacionamentos e do cast decimal (sem ponto flutuante).
- [x] 1.3 Seeders: `distributors` (ENEL), `regulatory_parameters` (`overrun_tolerance`, `overrun_multiplier`, `reference_power_factor`, `idle_demand_discount_fallback`), `tariffs` de exemplo (valores fictícios de teste, com `includes_taxes` `true` e `false`), `quotation_base`, admin e dois clientes fictícios.
  - Teste: `migrate:fresh --seed` passa; seeder de usuários de teste **não roda** em `production`.
- [x] 1.4 Base da API em `/api/v1`: formato único de erro (`message`, `code`, `errors`), handler de exceções, CORS restrito ao frontend, cabeçalhos de segurança, rate limiters nomeados.
  - Teste: feature tests de 401, 404 e 422 no formato padrão; origem não permitida bloqueada pelo CORS.
- [x] 1.5 Documentação da API gerada a partir do código (Scribe ou Scramble, após confirmar compatibilidade com Laravel 13).
  - Teste: a geração roda sem erro e um endpoint de exemplo aparece documentado com sucesso e erros.

### Fase 2 — Autenticação e usuários
Ref: RN-1, RN-2, RN-3, API (Auth, Me)

- [x] 2.1 `POST /auth/register`: valida dados e senha (≥ 10, letras e números, confirmação, bloqueio de senhas comuns ou vazadas), grava consentimento (`consents`: versão, IP, data), cria o cliente com e-mail não verificado e envia código numérico.
  - Teste: sucesso; senha fraca; e-mail duplicado; sem aceite dos termos; e-mail com o código enviado (fake mail).
- [x] 2.2 `POST /auth/verify-email` e `POST /auth/resend-code`: código com hash, expiração e limite de tentativas.
  - Teste: código correto, errado, expirado, limite de tentativas excedido; reenvio com throttle.
- [x] 2.3 `POST /auth/login` e `/auth/logout` com Sanctum: token de 2 h fixas, login bloqueado sem e-mail verificado, logout revoga o token.
  - Teste: login ok; credenciais inválidas (401, sem revelar se o usuário existe); e-mail não verificado (403); token expira após 2 h (simular o tempo); token revogado no logout.
- [x] 2.4 `POST /auth/forgot-password` e `/auth/reset-password`: link de uso único com validade limitada e resposta neutra.
  - Teste: fluxo completo; token reutilizado; token expirado; e-mail inexistente com a mesma resposta.
- [x] 2.5 Autorização por perfil (admin e cliente) com policies.
  - Teste: cliente em rota admin → 403; cliente acessando recurso de outro cliente → 403 ou 404.
- [x] 2.6 `GET/PUT /me` e `DELETE /me` (exclusão de dados a pedido do titular).
  - Teste: perfil atualizado; exclusão remove conta e dados relacionados; sem token → 401.
- [x] 2.7 `POST /me/contact-request`: registra `contact_requested_at` (necessário para D11).
  - Teste: grava a data; sobrevive ao expurgo de simulações (ver 5.5).

### Fase 3 — Motor de cálculo (módulo isolado, sem HTTP)
Ref: RC, RN-5, DB (tariffs, regulatory_parameters, invoices, invoice_items)

- [x] 3.1 Contrato de dados da extração: estrutura que o motor consome, alinhada a `invoices` e `invoice_items` (por item: quantidade, preço unitário com tributos, tarifa sem tributos, valor, PIS/COFINS, base e alíquota de ICMS e ICMS; a demanda ociosa pode ter quantidade e preço unitário nulos).
  - Teste: campos obrigatórios validados; itens ausentes = 0; demanda ociosa sem quantidade e sem preço unitário é aceita; casas decimais conforme RC-0.
- [x] 3.2 Serviços de parâmetros e de tarifas (parâmetros nacionais por vigência; tarifas por vigência, distribuidora, modalidade e posto, com a indicação da base tributária). Valores em decimal.
  - Teste: tarifa vigente por data; parâmetro nacional resolvido por vigência; tarifas com `includes_taxes` `true` e `false` retornadas com a indicação; ausência de tarifa vigente sinaliza o aviso do relatório.
- [x] 3.3 Proporção da demanda ociosa `r` (RC-1a).
  - Teste: fixture ENEL de referência → `r` = 0,82 pela tributação (alíquota 18% nas linhas normais e 0% nas ociosas), com a confirmação PIS/COFINS da linha ociosa ≈ 4,49% ÷ 0,82 ≈ 5,48%; alíquota de ICMS diferente (por exemplo 20%) → `r` = 0,80; linha ociosa com preço unitário usa a prioridade 2; meses com Dm < Dc sem linha ociosa (leitor que reconhece a linha) → `r` = 1; série sem mês revelador → reserva de 18% e aviso; leitor que não reconhece a linha → reserva e aviso; PIS/COFINS inconsistente na linha ociosa gera aviso; série com reajuste gera um `r` por período; associação das duas linhas ociosas a ponta e fora de ponta confirmada por matching determinístico com o valor esperado (neutralizando inversão da ordem física).
- [x] 3.4 Fator de calibração tributária `k` (RC-1c).
  - Teste: com a fixture ENEL de referência, `k` ≈ 1,2902 em todos os itens (por exemplo demanda ponta 37,80322 ÷ 29,30000; demanda fora de ponta 21,98516 ÷ 17,04000; TUSD 0,16134 ÷ 0,12505; TE 0,56657 ÷ 0,43913 e 0,35293 ÷ 0,27354), com diferença entre itens dentro do arredondamento de 5 casas, e igual a 1 ÷ (1 − 18% − PIS/COFINS%); `k` divergente entre itens acima da tolerância gera aviso; sem a coluna de tarifa sem tributos, `k` vem de 1 ÷ (1 − ICMS − PIS/COFINS%), e sem esses dados a migração fica indisponível; a tarifa sem tributos da fatura confere com a tarifa ANEEL da vigência (aviso se divergir); TE igual nas duas modalidades usa a TE da fatura; TE diferente na tabela recebe `k`; sem tarifa ANEEL da vigência do mês → migração indisponível com aviso.
- [x] 3.5 Faturamento de demanda por mês e posto (RC-1), usando `r`.
  - Teste: com Dc = 100 kW e `r` = 0,82: Dm 80 → 96,4·T; Dm 105 → 105·T; Dm 120 → 140·T; limite exato Dm = 1,05·Dc; com `r` = 1, Dm 80 → 100·T.
- [x] 3.6 Demanda ótima (RC-2).
  - Teste: série sintética com ótimo conhecido; Azul com ponta e fora de ponta separados; Verde com demanda única; empate escolhe a menor demanda; sem multa de ultrapassagem desnecessária.
- [x] 3.7 Reativos UFER e demanda reativa excedente (RC-3).
  - Teste: série com cobrança (economia = 100% do pago) e sem cobrança (resultado zero, “sem ocorrência no período”).
- [x] 3.8 Migração Azul ↔ Verde (RC-4).
  - Teste: um cenário em que a Verde vence, um em que a Azul vence e um sem ganho; tarifas da outra modalidade vindas da tabela ANEEL e convertidas por `k` (TUSD de demanda e TUSD de consumo ponta obrigatoriamente da tabela ANEEL; proibido reaproveitar a TUSD de consumo ponta da fatura Azul na Verde); `r` aplicada à tarifa de demanda da outra modalidade; sem tarifas necessárias da outra modalidade retorna “indisponível” com aviso.
- [x] 3.9 Valoração e projeção anual (RC-5).
  - Teste: 12 meses; 6 meses (média × 12 com aviso); tarifa vencida usa o histórico com aviso.
- [x] 3.10 Reconciliação com a fatura (RC-1b).
  - Teste: mês coerente com a fatura não gera aviso; mês divergente gera aviso e o custo atual daquele mês usa o valor faturado.
- [x] 3.11 Indicadores e séries dos gráficos (RC-6).
  - Teste: valores esperados de economia mensal, anual e %; rótulo do percentual; divisão por zero protegida; séries por mês corretas.
- [x] 3.12 Serviço de recálculo a partir dos dados extraídos: gera nova versão do relatório (`algorithm_version`, tarifas vigentes no momento) sem reler PDFs.
  - Teste: recalcular após atualização de tarifa muda o resultado; a versão anterior e o histórico de revisões permanecem.
- [x] 3.13 Benchmark com as planilhas `Analise_Faturas_Energia_Preenchida_*` (quando anexadas).
  - Teste: o resultado do motor iguala a planilha, com tolerância de centavos. Depende de §9.

### Fase 4 — Leitura das faturas (leitor ENEL)
Ref: RN-3, RN-4, RN-5, RN-6

- [x] 4.1 Interface `InvoiceReader` por distribuidora, escolhida por `parser_key`; cada leitor declara se reconhece a linha de demanda ociosa.
  - Teste: a ENEL resolve o leitor correto e declara que reconhece a linha; distribuidora sem leitor lança exceção específica.
- [x] 4.2 Extração de texto do PDF (biblioteca `smalot/pdfparser`) em arquivo temporário em disco privado (fora de `public`), apagado ao final inclusive em erro; rotina diária apaga temporários órfãos com mais de 1 hora (prazo maior que o tempo máximo de espera na fila).
  - Teste: PDF de exemplo gera texto; após sucesso, falha e limpeza de órfão, nenhum arquivo temporário permanece.
- [x] 4.3 Leitor ENEL: lê o quadro de faturamento e devolve os campos de RN-5 com a nomenclatura de RN-6 (por item: quantidade, preço unitário com tributos, tarifa unitária sem tributos, valor, PIS/COFINS, base e alíquota de ICMS e ICMS); a demanda ociosa aceita linhas sem quantidade e sem preço unitário, e as duas linhas de mesmo rótulo são associadas aos postos por matching biunívoco com o menor erro quadrático em relação a $(D_c - D_m) \times P_{trib} \times (1 - \text{ICMS})$, neutralizando inversões da ordem física da fatura; itens ausentes = 0.
  - Teste: fixtures anonimizadas, uma com o quadro de referência (Azul, com as duas linhas “DEMANDA LEI ESTADUAL 16.886/18” sem quantidade, onde a primeira linha física corresponde a Fora de Ponta R$ 1.845,32 e a segunda a Ponta R$ 3.603,28) e outra sem linha ociosa; valores do quadro conferidos à mão (por exemplo demanda ponta 221,760 kW × 37,80322 = 8.383,25; total 98.922,22; PIS/COFINS 4.452,11; ICMS 16.637,37; COSIP 1.043,66); distingue as leituras DMCR da memória do medidor da cobrança UFDR e das linhas de UFER. Anonimizar antes de adicionar a fixture ao repositório.
- [x] 4.4 Validações pós-extração: campos obrigatórios, total pago presente, mês duplicado, mesma unidade consumidora e coerência de quantidades, preços e valores extraídos (quantidade × preço unitário com tributos ≈ valor; base de ICMS × alíquota ≈ ICMS).
  - Teste: um teste por validação, incluindo quantidade × preço ≈ valor e base × alíquota ≈ ICMS com tolerância de centavos; não exigir que os itens analisados somem o total da fatura, pois impostos e cobranças fora do escopo não são extraídos; falha após enfileirar marca a simulação como `failed` e associa o erro ao arquivo para consulta em `/status`.
- [x] 4.5 Upload seguro: assinatura real de PDF; 6 a 12 arquivos; limite de 20 MB por arquivo, 120 MB por simulação e 600 páginas no total.
  - Teste: executável renomeado para `.pdf` rejeitado (415); 5 e 13 arquivos rejeitados; limites individual, total e de páginas excedidos rejeitados (413).

### Fase 5 — API de simulação e relatório (cliente)
Ref: RN-3, RN-4, RC, DB, API (Cliente)

- [x] 5.1 `POST /simulations` (multipart + dados da empresa): valida a requisição e cria a simulação em fila (`queued`); validações de entrada respondem 422 antes de enfileirar.
  - Teste: sucesso; validações; distribuidora sem leitor → 422 com código `DISTRIBUTOR_UNSUPPORTED` e lead registrado; sem token → 401.
- [x] 5.2 Job de processamento: extrai, valida, grava `invoices` e `invoice_items`, calcula (com a reconciliação de RC-1b), gera `reports` e atualiza o status (`processing`, `completed` ou `failed`); descarta temporários. Partes dependentes das regras ainda abertas no §9 ficam indisponíveis com aviso.
  - Teste: job com fixtures completa; falha de leitura após enfileirar vira `failed` com erro associado ao arquivo; cliente consulta o erro em `/status`; retry é idempotente e não duplica dados; temporários apagados em sucesso, falha e timeout.
- [x] 5.3 `GET /simulations`, `GET /simulations/{id}/status` e `GET /simulations/{id}/report`.
  - Teste: cada cliente vê só as próprias simulações; transições `queued` → `processing` → `completed` ou `failed`; status de falha inclui código e erros por arquivo; relatório com indicadores, séries e avisos.
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
- [ ] 6.3 Recálculo: `POST /admin/simulations/{id}/recalculate` gera nova versão do relatório a partir dos dados extraídos (serviço 3.12).
  - Teste: admin ok; cliente 403; cria nova versão sem alterar os dados extraídos; simulação expirada não pode ser recalculada.
- [ ] 6.4 Tarifas: `GET/POST/PUT /admin/tariffs`, com `includes_taxes` obrigatório (`true` para preço com tributos; `false` para tarifa sem tributos, como as da ANEEL), sem sobreposição de vigência; tarifa vigente ou histórica não pode ser sobrescrita, alterações criam nova versão com nova vigência.
  - Teste: cria tarifa com `includes_taxes` `true` e `false`; `includes_taxes` ausente rejeitado (422); vigência sobreposta rejeitada (422); versão histórica permanece inalterada após reajuste; cliente 403.
- [ ] 6.5 Importação de tarifas da ANEEL: `POST /admin/tariffs/import` recebe o CSV oficial (tarifas de aplicação, sem tributos) e grava em `tariffs` com `includes_taxes = false`, `source = aneel_import` e a referência da resolução; registra a importação em `tariff_imports`. Aceita só Grupo A, subgrupos A4 e AS, modalidades Azul e Verde e distribuidoras ativas; ignora o resto; reimportar o mesmo arquivo não duplica; a vigência anterior é encerrada, nunca sobrescrita. O formato do CSV deve ser confirmado no portal de dados abertos da ANEEL (§9).
  - Teste: importa um arquivo de exemplo (fixture); reimportação não duplica; linhas fora do escopo são ignoradas e contadas; CSV inválido → 422; vigência anterior encerrada sem alterar seus valores; cliente 403.
- [ ] 6.6 Parâmetros: `GET /admin/parameters` e `POST /admin/parameters`; cada alteração cria nova versão com vigência, preservando os valores usados por relatórios existentes.
  - Teste: admin cria nova versão de um parâmetro nacional (por exemplo a reserva da demanda ociosa); versão anterior permanece inalterada; cliente 403.
- [ ] 6.7 `GET /admin/leads` (leads de distribuidora não atendida e pedidos de contato).
  - Teste: lista paginada; cliente 403.
- [ ] 6.8 Fechar a documentação da API: todos os endpoints com exemplos e erros, e coleção Postman/OpenAPI.
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
- [ ] 8.6 Relatório online: indicadores, gráficos (demanda registrada × contratada × ótima, reativos, Azul × Verde), avisos (menos de 12 faturas, tarifa desatualizada, proporção de reserva da demanda ociosa, divergência com a fatura, “sem ganho”, migração indisponível por falta de tarifa da outra modalidade), percentual com o rótulo de D14, botão de PDF e botão de contato (WhatsApp ou e-mail) que chama `contact-request`.
  - Teste: renderiza com dados de exemplo; avisos aparecem nas condições certas; o botão de contato registra o pedido.
- [ ] 8.7 Perfil e exclusão de dados (LGPD).
  - Teste: atualiza o perfil; exclusão pede confirmação e desloga.

### Fase 9 — Frontend: área do admin
Ref: FE, API (Admin)

- [ ] 9.1 Acesso administrativo e painel.
  - Teste: só o perfil admin entra; demais veem 403.
- [ ] 9.2 Clientes e contatos; simulações e dados extraídos (nunca PDFs).
  - Teste: listas paginadas; detalhe exibe os dados extraídos.
- [ ] 9.3 Relatórios: visualizar, editar textos explicativos/recomendações, reenviar, recalcular; histórico de versões; resultados calculados somente leitura.
  - Teste: edição cria versão sem alterar resultados; reenvio e recálculo chamam a API.
- [ ] 9.4 Tabela de tarifas (base tributária e origem), importação do CSV da ANEEL (com resumo de linhas aceitas e ignoradas) e parâmetros regulatórios.
  - Teste: criar e editar tarifa; importar arquivo de exemplo exibe o resumo; vigência sobreposta, base tributária ausente e CSV inválido exibem o erro da API.
- [ ] 9.5 Leads e pedidos de contato.
  - Teste: listagem e filtros básicos.

### Fase 10 — Orçamento
Ref: ORC

- [ ] 10.1 **Etapa 1 (agora):** endpoints admin `GET/POST/PUT /admin/quotation-items` para a tabela `quotation_base` (hora de consultoria, diária do analisador, hora de projeto de banco de capacitores).
  - Teste: CRUD restrito ao admin; cliente 403.
- [ ] 10.2 **Etapa 1 (agora):** área do admin no frontend (estrutura preparatória, sem regra ativa).
  - Teste: visível só ao admin; edita os valores da tabela.
- [ ] 10.3 **Etapa 2 (não iniciar sem autorização):** orçamento em PDF A4, enviado ao cliente após liberação do admin.
  - Teste: definido quando a etapa 2 começar.

### Fase 11 — Segurança, revisão final e operação
Ref: RN-7, §9

- [ ] 11.1 Checklist de segurança de RN-7 (SQL Injection, XSS e CSP, CORS, rate limiting, acesso a dados de terceiros, upload, logs, dependências).
  - Teste: feature tests cobrem entradas contra SQL Injection e acesso entre usuários; teste de saída confirma escape contra XSS; testes de configuração verificam CSP, CORS e rate limits; testes de upload cobrem tipo, tamanho e quantidade; teste de logs confirma ausência de dados pessoais; `composer audit` e `npm audit` sem vulnerabilidades críticas ou altas sem plano de correção.
- [ ] 11.2 Reforço da conta admin (opcional, a decidir em §9): senha mais forte e segundo fator por TOTP.
  - Teste: com o segundo fator ativo, o login do admin o exige; sem ele, o acesso é negado.
- [ ] 11.3 Importação automática das tarifas a partir do portal de dados abertos da ANEEL (opcional): rotina agendada que baixa o conjunto de dados, grava a importação como pendente de revisão e só ativa as tarifas após aprovação do admin.
  - Teste: com a fonte simulada, a rotina cria importação pendente; nada é ativado sem aprovação; falha de rede não altera as tarifas vigentes.
- [ ] 11.4 Teste de ponta a ponta do fluxo completo (Playwright, opcional).
  - Teste: cadastro → verificação → envio → relatório → PDF por e-mail.
- [ ] 11.5 Conteinerização completa (API e frontend), HTTPS, diretório temporário privado compartilhado entre API e worker (volume), limites de upload no proxy, worker de fila com até três tentativas (backoff de 60/180 segundos, timeout de 300 segundos e `retry_after` maior que o timeout), agendador (expurgo, limpeza de temporários órfãos e, se adotada a 11.3, importação de tarifas) em produção; admin criado por comando próprio.
  - Teste: `docker compose` de produção sobe tudo; o worker lê o PDF enviado pela API e processa; worker recupera jobs conforme configuração; agendador executa expurgo e limpeza; seeder de teste não roda em produção.
- [ ] 11.6 README de implantação e documentação final.
  - Teste: seguir o README num ambiente limpo.
- [ ] 11.7 Fechar as pendências de §9 (REN 1000 e planilhas).
  - Teste: cada item de §9 resolvido ou registrado como decisão.

## 3. Regras de negócio (RN)

**RN-1 Perfis.** Cliente: só o próprio cadastro, envio de faturas, simulações e relatórios. Admin: acesso total (usuários, dados extraídos, tarifas, parâmetros, edição de relatórios, estrutura de orçamento). O admin nunca vê PDFs.

**RN-2 Autenticação.** E-mail confirmado por código numérico antes do primeiro login. Senha: mínimo 10 caracteres, letras e números, confirmação, bloqueio de senhas comuns ou vazadas; hash Bcrypt. Sanctum Bearer, 2 h fixas; logout revoga. Recuperação por link de uso único. Reenvio de código com expiração e limite de tentativas. Rate limiting em login, códigos e upload.

**RN-3 Privacidade (LGPD).** Aceite explícito dos termos, gravando data, hora, IP e versão em `consents`. Política de Privacidade e Termos de Uso publicados (texto validado por assessoria jurídica). Exclusão de dados a pedido. PDFs ficam só em temporário, em disco privado compartilhado entre API e worker, e são apagados logo após a extração, também em falha. Simulações expiram em 180 dias; a conta permanece.

**RN-4 Entrada de faturas.** Somente PDF, de 6 a 12 por simulação (ideal 12), até 20 MB por arquivo, 120 MB por simulação e 600 páginas no total. Campos: nome da empresa (obrigatório), CNPJ (opcional), distribuidora, potência do transformador em kVA (opcional), endereço da empresa e checkbox “endereço da subestação é o mesmo da fatura” (se desmarcado, informar o alternativo).

**RN-5 Extração.** Um leitor por distribuidora (`parser_key`); hoje só ENEL; ler qualquer layout é meta futura. Técnica: texto do PDF, foco no quadro de faturamento; OCR só como contingência. Campos por fatura: mês de referência; unidade consumidora; subgrupo e modalidade; demanda contratada e medida (ponta e fora de ponta na Azul; única na Verde); consumo ativo em kWh (ponta e fora de ponta); para cada item de demanda e de consumo (TE e TUSD): quantidade, preço unitário com tributos, tarifa unitária sem tributos (coluna “Tarifa unit”), valor, PIS/COFINS, base de cálculo e alíquota do ICMS e valor do ICMS; quantidades e valores faturados de ultrapassagem; valor, PIS/COFINS, base e alíquota de ICMS da demanda ociosa, quando a linha existe (quantidade e preço unitário só se a linha os trouxer; na ENEL, as linhas “DEMANDA LEI ESTADUAL 16.886/18” vêm sem eles e a ordem física pode listar fora de ponta antes de ponta, sendo resolvidas por matching determinístico com o valor esperado conforme RC-1a); consumo reativo (UFER), discriminado em ponta indutivo, fora de ponta indutivo e madrugada/fim de semana capacitivo; item faturável de demanda reativa excedente, se houver, reconhecendo os rótulos “UFDR”, “Demanda Reativa Excedente”, “DUR” ou “Demanda Reativa Indutiva/Capacitiva”; valor total pago (incluindo COSIP e encargos municipais para refletir o boleto real). DMCR é leitura técnica da memória do medidor, não é item faturável e não deve ser extraída para a simulação nem tratada como cobrança. A proporção da demanda ociosa `r` é obtida pela tributação da linha ociosa, ou pelo preço unitário quando existir (RC-1a). A tarifa sem tributos e a tributação de cada item são a base do fator `k` (RC-1c). A tarifa de ultrapassagem é derivada (2× a tarifa de demanda); se a fatura a trouxer, serve de conferência. A demanda reativa excedente usa a tarifa de demanda correspondente ao posto (ponta/fora de ponta ou única na Verde); o consumo reativo usa as tarifas de consumo ativo correspondentes aos postos ponta/fora de ponta. Consumo e tarifas TE/TUSD são usados na comparação de modalidade Azul × Verde, mas não representam economia por redução de consumo. Fora do escopo: bandeira tarifária, perdas, impostos e taxa municipal. Item ausente na fatura = 0 (“sem ocorrência no período”). Validações: presença do valor total pago e coerência de quantidades, tarifas e valores extraídos; campos obrigatórios; mês duplicado; mesma unidade consumidora; 6 a 12 arquivos. Erros de validação da requisição respondem 422 antes de enfileirar; falhas de extração após enfileirar atualizam a simulação para `failed` e disponibilizam erro por arquivo em `/status`. Logs sem dados pessoais; cliente pode reenviar.

**RN-6 Nomenclatura interna padrão.** Os leitores normalizam os rótulos específicos de cada distribuidora para os conceitos abaixo. Os nomes apresentados são rótulos ENEL ou variações identificadas; não se presume que outras distribuidoras usem os mesmos termos.

| Rótulo ENEL / variação reconhecida | Conceito interno | Uso |
|---|---|---|
| Demanda Contratada (Ponta e Fora de Ponta) | Demanda contratada (Dc) | kW. Azul: ponta e fora de ponta; Verde: única. |
| Demanda Registrada (Medida) | Demanda medida (Dm) | Maior potência ativa do ciclo (kW). |
| Demanda de Ultrapassagem | Ultrapassagem de demanda | Quantidade e valor faturado; tarifa derivada pelo cálculo (2×); se extraída, usada na conferência. |
| Demanda ociosa (na fatura ENEL pode aparecer como DEMANDA LEI ESTADUAL 16.886/18) | Parcela não utilizada da demanda | Demanda contratada não consumida. Valor, PIS/COFINS e tributação (ICMS 0%) são extraídos; quantidade e preço unitário, só se a linha os trouxer. A tributação dá a proporção `r` (RC-1a). |
| UFER (Ponta Indutivo / Fora de Ponta Indutivo / Madrugada ou Fim de Semana Capacitivo) | Consumo reativo excedente | Valor/quantidade faturados; valoração usa as tarifas de consumo ativo correspondentes. |
| UFDR (normalizado como Demanda Reativa Excedente; também pode aparecer como DUR ou Demanda Reativa Indutiva/Capacitiva) | Demanda reativa excedente faturável | Se houver cobrança, valor faturado; valoração usa a tarifa de demanda correspondente. DMCR é leitura da memória do medidor, não um item faturável. |
| Consumo Ponta / Fora de Ponta (TE e TUSD) | Consumo ativo (Ca) | kWh valorados pelas tarifas TE e TUSD; usados na comparação Azul × Verde. |

**RN-7 Segurança (API e frontend).**

- SQL Injection: Eloquent ou Query Builder com bindings; nunca concatenar SQL; toda entrada validada por Form Requests.
- XSS: saídas escapadas, cabeçalhos de segurança e CSP; o frontend nunca renderiza HTML não confiável.
- CORS restrito ao domínio do frontend; HTTPS obrigatório em produção.
- Autorização por policies: cliente só acessa os próprios recursos (evitar acesso por troca de ID); rotas admin só para admin.
- Mass assignment: modelos com campos permitidos explícitos.
- Upload: validar tipo real (assinatura de PDF), até 20 MB por arquivo, 120 MB por simulação e 600 páginas no total; temporário em disco privado, fora de diretório público; nunca executar.
- Logs sem dados pessoais; segredos só no `.env`; `composer audit` e `npm audit`.
- Observar o OWASP Top 10 (CSRF com cookie, enumeração de usuários no login e na recuperação de senha).

## 4. Regras de cálculo (RC)

**RC-0 Princípios.** Cálculo no backend, determinístico, em decimal (nunca ponto flutuante). Tarifas mantêm 5 casas decimais; grandezas de demanda (kW), consumo (kWh) e energia reativa (kVArh) mantêm 3 casas decimais, conforme a fatura ENEL; a proporção `r` mantém 6 casas; valores monetários resultantes de multiplicações e somas são expressos com 2 casas decimais. Arredondar cada valor monetário calculado para centavos pelo método half-up antes de somar os itens; totais são a soma dos valores já arredondados. O motor trabalha na base “com tributos” (preço unitário da fatura). `tariffs.includes_taxes` indica a base de cada tarifa: `true` para preço com tributos e `false` para tarifas importadas da ANEEL, que são convertidas pelo fator `k` (RC-1c). Parâmetros nacionais em `regulatory_parameters`, editáveis pelo admin, nunca fixos no código: tolerância 5%, multiplicador de ultrapassagem 2, fator de potência de referência 0,92 e reserva da demanda ociosa 18%. A proporção da demanda ociosa vem da fatura (RC-1a); não há flag nem tabela de ICMS por UF na versão 1. Reativos e ultrapassagem devem existir e ser testados com dados sintéticos, mesmo que as faturas reais não tenham essas cobranças.

**RC-1 Faturamento da demanda** (por mês e por posto; Azul: ponta e fora de ponta; Verde: única). T = tarifa de demanda do posto; `r` = proporção da demanda ociosa (RC-1a).

| Condição | Custo |
|---|---|
| Dm ≤ Dc | `Dm·T + (Dc − Dm)·T·r` |
| Dc < Dm ≤ 1,05·Dc | `Dm·T` (tarifa normal, sem multa) |
| Dm > 1,05·Dc | `Dc·T + (Dm − Dc)·2·T` |

Exemplos (Dc = 100 kW, `r` = 0,82): Dm 80 → 96,4·T; Dm 105 → 105·T; Dm 120 → 140·T. Com `r` = 1: Dm 80 → 100·T.

**RC-1a Proporção da demanda ociosa (`r`).** As linhas de demanda ociosa (“DEMANDA LEI ESTADUAL 16.886/18” na ENEL SP) podem vir sem quantidade e sem preço unitário, só com valor, PIS/COFINS e ICMS 0%. `r` é obtido, por mês e posto, na seguinte ordem:
1. **Pela tributação (fonte principal):** `r` = 1 − alíquota de ICMS das linhas normais de demanda do mesmo mês, quando a linha ociosa traz base de ICMS e alíquota 0%. Confirmação: o PIS/COFINS% da linha ociosa deve ser ≈ PIS/COFINS% das linhas normais ÷ (1 − ICMS) (fixture ENEL: 4,49% ÷ 0,82 ≈ 5,48%), com tolerância de 0,02 ponto percentual; sem confirmação dentro da tolerância, avisar.
2. **Pelo preço unitário, se a linha o trouxer:** `r` = preço unitário com tributos da demanda ociosa ÷ preço unitário com tributos da demanda normal do mesmo posto.
3. **Reserva:** `idle_demand_discount_fallback` (padrão 18%, `r` = 0,82), com aviso, quando nenhuma das duas formas revelar `r`.
- Meses sem a linha ociosa usam o `r` do mesmo período tarifário (uma mudança de tarifa, como o reajuste anual, inicia novo período); sem mês revelador no período, usa-se o `r` do período mais próximo no tempo.
- Se há meses com Dm < Dc e nenhuma linha ociosa na série, e o leitor reconhece essa linha para a distribuidora, a cobrança é integral: `r` = 1.
- Associação aos postos: as duas linhas ociosas da ENEL têm o mesmo rótulo e não indicam o posto, e sua ordem física no documento pode vir invertida (por exemplo, listando Fora de Ponta antes de Ponta). O leitor calcula o valor esperado de cada posto: `V_esperado = (Dc − Dm) × Preço_unit_normal_com_tributos × r`. O leitor realiza o matching determinístico biunívoco com os valores faturados das linhas ociosas, atribuindo cada linha ao posto que apresentar a menor discrepância (|V_faturado − V_esperado| ≤ 0,02); se não conseguir associar com segurança, o relatório avisa e a reconciliação (RC-1b) assume o valor faturado.
- O mesmo `r` é aplicado à tarifa de demanda da outra modalidade (RC-4).
- Os valores de `r` por período e a fonte usada (tributação, preço unitário ou reserva) ficam registrados no relatório.

**RC-1b Reconciliação com a fatura.** Para cada mês, recalcular a cobrança de demanda (RC-1) com as quantidades e tarifas lidas e comparar com os valores faturados nas linhas de demanda (normal, ociosa e ultrapassagem). Divergência acima da tolerância de **R$ 0,02** gera aviso no relatório, e o “custo atual” daquele mês passa a ser o valor faturado. Diferenças iguais ou inferiores à tolerância são consideradas coerentes.

**RC-1c Calibração tributária (`k`).** A fatura ENEL traz, por item, o preço unitário com tributos e a tarifa unitária sem tributos (coluna “Tarifa unit”). Para cada mês e item: `k` = preço unitário com tributos ÷ tarifa unitária sem tributos; equivale a 1 ÷ (1 − alíquota de ICMS − PIS/COFINS%), com PIS/COFINS% = PIS/COFINS ÷ valor do item (fixture ENEL: 1 ÷ (1 − 18% − 4,49%) ≈ 1,2902). O `k` do mês é o mesmo em todos os itens com ICMS normal (demanda, TUSD e TE); essa igualdade, dentro do arredondamento de 5 casas, é uma verificação automática, e divergência acima da tolerância (parâmetro, padrão a definir com faturas reais) gera aviso.
- Para a versão 1, o `k` mensal é a média aritmética dos fatores calculáveis por item, arredondada half-up a 6 casas. A tolerância absoluta de divergência entre os fatores é `0,00003`; acima dela, o relatório inclui aviso. Esse limite cobre o intervalo máximo observado nos cinco fatores da fatura de referência (aprox. `0,000028`).
- A tarifa “com tributos” da outra modalidade = tarifa ANEEL dela (sem tributos) × `k` do mesmo mês.
- A TE é igual nas duas modalidades: usa-se a TE da fatura. Se a tabela de tarifas mostrar TE diferente entre as modalidades, aplica-se `k` também à TE da outra modalidade.
- A “Tarifa unit” da fatura deve coincidir com a tarifa de aplicação da ANEEL da mesma vigência: serve de validação da importação (tarefa 6.5) e de aviso quando a tabela estiver desatualizada.
- Se a fatura não trouxer a tarifa sem tributos (outro layout), `k` = 1 ÷ (1 − alíquota de ICMS − PIS/COFINS%); sem esses dados, a migração fica indisponível com aviso. Sem tarifa ANEEL da vigência do mês na tabela, idem.
- `k` com 6 casas decimais, por mês; registrado no relatório.

**RC-2 Demanda ótima.** Testar candidatos de demanda com passo de 1 kW numa faixa ajustável (padrão e limites regulatórios a definir; ver §9). Para cada candidato, recalcular o custo de toda a série com RC-1. A ótima é a de menor custo total (empate: a menor demanda). Azul: ponta e fora de ponta otimizadas separadamente; Verde: demanda única. Economia = custo atual − custo com a ótima.

**RC-3 Reativos.** Somar os valores faturados de UFER e de demanda reativa excedente, quando houver (rótulos aceitos: “UFDR”, “DUR” e “Demanda Reativa Indutiva/Capacitiva”). DMCR é leitura técnica, não cobrança, e não entra na soma. Economia considerada = 100% das cobranças reativas faturadas (correção do fator de potência com banco de capacitores). Sem cobrança: zero.

**RC-4 Migração Azul ↔ Verde.** Comparar o custo anual otimizado nas duas modalidades com os mesmos dados medidos de demanda e consumo. Cada modalidade usa suas tarifas de demanda e de consumo; os kWh de ponta e fora de ponta permanecem iguais entre os cenários. A modalidade vigente usa os preços da fatura. A outra modalidade usa a tabela de tarifas (ANEEL, sem tributos) convertida pelo fator `k` (RC-1c): TUSD de demanda e TUSD de consumo ponta e fora de ponta. **Atenção:** na Horária Verde, a TUSD de Consumo Ponta é estruturalmente muito mais elevada do que na Azul; é terminantemente proibido reaproveitar a TUSD de consumo ponta da fatura Azul no cenário Verde. A TE é igual nas duas modalidades e vem da fatura. Não existe percentual fixo entre modalidades. A tarifa da demanda ociosa da outra modalidade é a tarifa de demanda dela (já convertida) multiplicada por `r`. Saída: modalidade mais econômica com a diferença de custo, ou “Sem ganho financeiro na migração”. Sem tarifa homologada necessária da outra modalidade (demanda ou TUSD consumo ponta): resultado indisponível com aviso explicativo.

**RC-5 Valoração e projeção.**

- A) Projeção anual: tarifas vigentes mais recentes (fatura mais recente completada pela tabela de tarifas) aplicadas a todo o histórico; economia média mensal × 12. O recálculo do admin reaplica A com as tarifas vigentes no momento.
- B) Histórico de referência: valor efetivamente pago, com as tarifas de cada fatura.
- 6 a 11 faturas: média dos N meses × 12, com aviso explicativo.
- Sem tarifa vigente cadastrada: aviso e uso do histórico.

**RC-6 Indicadores.** O relatório apresenta a economia mensal média (R$) e a projeção anual (R$) do total e de cada componente: demanda, ultrapassagem, reativos e migração. A projeção anual de cada valor é sua média mensal multiplicada por 12; o total corresponde à soma dos componentes. O percentual total é calculado como economia mensal média total ÷ valor total médio pago nas faturas × 100 e rotulado “% do valor médio pago no período”; como a economia usa tarifas atuais e o valor pago usa tarifas históricas, o relatório exibe essa observação (D14). Consumo não gera economia por redução na versão 1, mas seu custo com tarifas TE/TUSD é considerado no componente de migração Azul × Verde. Séries dos gráficos por mês: demanda registrada × contratada atual × ótima; reativos; Azul × Verde.

## 5. Banco de dados (DB)

Tabelas em inglês. Tarifas com 5 casas decimais; grandezas de demanda, consumo e energia reativa com 3 casas decimais; valores monetários com 2 casas decimais. Usar tipos decimais, nunca ponto flutuante.

- `users` (id, name, email, phone, password_hash, role, email_verified_at, contact_requested_at)
- `consents` (id, user_id, terms_version, accepted_at, ip_address)
- `customer_profiles` (user_id, company_name, cnpj, distributor_id, transformer_power_kva, address_details, substation_same_address, substation_address)
- `distributors` (id, name, state, parser_key, active)
- `tariffs` (id, distributor_id, subgroup, tariff_modality, time_slot, component, rate, includes_taxes, source, source_reference, valid_from, valid_to); `component`: `demand`, `energy_te`, `energy_tusd`; `includes_taxes`: `true` para preço com tributos (como na fatura) e `false` para tarifas importadas da ANEEL (somente “tarifas de aplicação”, não a base econômica); `source`: `aneel_import` ou `manual`; `source_reference`: resolução homologatória de origem.
- `tariff_imports` (id, source_reference, file_name, imported_by, imported_at, rows_accepted, rows_ignored, status)
- `regulatory_parameters` (id, key, value, state nullable, valid_from, valid_to); na versão 1 só há parâmetros nacionais (`state = null`): `overrun_tolerance = 0.05`, `overrun_multiplier = 2`, `reference_power_factor = 0.92` e `idle_demand_discount_fallback = 0.18`. A coluna `state` fica reservada para evolução futura. Sem sobreposição de vigências para a mesma chave e escopo.
- `simulations` (id, user_id, status, `processing_errors` JSON por arquivo, months_count, total_monthly_savings, total_yearly_savings, expires_at)
- `invoices` (id, simulation_id, reference_month, consumer_unit, subgroup, tariff_modality, total_paid `DECIMAL(14,2)`); unicidade de `(simulation_id, reference_month)`; a unidade consumidora deve ser a mesma em todas as faturas da simulação.
- `invoice_items` (id, invoice_id, item_type, tariff_component, time_slot, reactive_nature, quantity, unit, unit_price_taxed, tariff_rate, amount, pis_cofins_amount, icms_base, icms_rate, icms_amount, source_label); `quantity DECIMAL(14,3)`; `unit_price_taxed DECIMAL(14,5)` (preço unitário com tributos) e `tariff_rate DECIMAL(14,5)` (“Tarifa unit”, sem tributos); `amount`, `pis_cofins_amount`, `icms_base` e `icms_amount` `DECIMAL(14,2)`; `icms_rate DECIMAL(6,4)`; `time_slot`: ponta, fora de ponta, única ou madrugada/fim de semana; campos de componente, posto e natureza reativa usam valor `not_applicable` quando não se aplicam, permitindo unicidade de `(invoice_id, item_type, tariff_component, time_slot, reactive_nature)`. Tipos representam demanda contratada/medida, consumo ativo, ultrapassagem, demanda ociosa, UFER e demanda reativa excedente. A demanda ociosa pode ter `quantity`, `unit_price_taxed` e `tariff_rate` nulos; a tarifa de ultrapassagem é derivada no cálculo.
- `reports` (resultados calculados persistidos: totais mensal/anual, valores por componente, percentual de economia, séries dos gráficos, avisos, textos explicativos e recomendações geradas, valores de `r` por período (com a fonte usada) e de `k` por mês; `algorithm_version`, `tariff_version_ids` e `regulatory_parameter_version_ids` guardam a rastreabilidade sem duplicar valores das tabelas versionadas); `report_revisions` (histórico de textos explicativos/recomendações editados, autor e data; resultados calculados são somente leitura)
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
| Admin | `GET /admin/customers`, `/customers/{id}`, `/simulations`, `/simulations/{id}`, `POST /admin/simulations/{id}/recalculate`, `PUT /admin/reports/{id}`, `POST /admin/reports/{id}/resend`, `GET/POST/PUT /admin/tariffs`, `POST /admin/tariffs/import`, `GET/POST /admin/parameters`, `GET /admin/leads`, `GET/POST/PUT /admin/quotation-items` |

## 7. Frontend (FE)

**Páginas.** Públicas: landing, cadastro, verificação de e-mail, login, esqueci e redefinir senha, erros e manutenção. Cliente: dados e envio das faturas, acompanhamento, relatório online com PDF, perfil. Admin: acesso administrativo, painel, clientes e contatos, simulações e dados extraídos, relatórios, tarifas (com importação do CSV da ANEEL) e parâmetros, leads, orçamento (estrutura).

**UX.** Responsivo (desktop, tablet, mobile). Tema claro e escuro com botão, escuro confortável (nunca preto absoluto), paletas consagradas, contraste WCAG AA, preferência salva. Erros HTTP amigáveis e sem detalhes técnicos: 400, 401 (login inválido ou sessão expirada), 403, 404, 413 e 415 (arquivo grande ou tipo não aceito), 422 para validação antes de enfileirar, 429, 500; manutenção (503) com previsão de retorno. Falhas de extração após o enfileiramento aparecem no estado `failed` com mensagem associada a cada arquivo, consultada por polling em `/status`.

**Envio das faturas.** Instrução: ideal 12 faturas, mínimo 6. Upload múltiplo com erro por arquivo (limites: 20 MB por arquivo, 120 MB por simulação, 600 páginas no total). Distribuidora em lista das atendidas (hoje ENEL); outra gera aviso e lead. “Simular” inicia a fila e mostra `queued`, `processing`, `completed` ou `failed` por polling. Falha posterior à resposta inicial é exibida no status, com detalhe por arquivo.

**Relatório.** Elegante e simples, fiel às faturas. Destaque para economia mensal e anual e % (com o rótulo de D14). Gráficos em Recharts. Avisos: menos de 12 faturas, tarifa desatualizada, proporção de reserva da demanda ociosa, divergência com a fatura, “sem ganho na migração”, migração indisponível (sem tarifa da outra modalidade). Botões: PDF e contato comercial (WhatsApp ou e-mail, registra `contact-request`).

**Segurança.** React escapa por padrão; proibido HTML bruto com conteúdo não confiável. Validação no front é só experiência; a segurança fica na API. Token só em memória (D7).

## 8. Orçamento (ORC)

**Etapa 1 (agora).** Tabela `quotation_base` com valor da hora técnica de consultoria, diária do analisador de qualidade de energia (24h/7d) e hora de projeto de banco de capacitores, atualizada manualmente pelo admin (futuro: por planilha). Área do admin preparada, sem regra ativa. Reservar campo para orçamento com materiais (banco de capacitores e outros), a detalhar na etapa 2.

**Etapa 2 (não iniciar sem autorização).** Orçamento em PDF A4 com cabeçalho e rodapé, dados fictícios da consultoria (CNPJ, endereço, e-mail, telefone). Itens com custo unitário e total, mais o total geral. Validade de 30 dias. Responsabilidade técnica de Engenheiro Eletricista registrado no CREA-SP, com emissão de ART. Enviado ao cliente por e-mail só após revisão e liberação do admin.

## 9. A conferir (REN 1000 e planilhas)

Itens regulatórios conferidos em `docs/REN 1000.pdf` e dados da fatura ENEL conferidos em `docs/Enel Hesap Agos.pdf` (agosto de 2025):

- [x] Artigos da REN 1000/2021 conferidos: Art. 298 e 299 (ultrapassagem de demanda: tolerância de 5% sobre a contratada como gatilho, cobrança sobre todo o excedente da contratada `(Dm − Dc) × 2 × T`); Art. 300 e 301 (reativos excedentes: fator de potência de referência 0,92 indutivo/capacitivo; UFER cobrado pela tarifa de consumo ativo TE e UFDR pela tarifa de demanda).
- [x] Fundamentação fiscal da demanda ociosa ENEL SP: Lei Estadual nº 16.886/2018 isenta o ICMS sobre `(Dc − Dm)`, resultando em `r = 1 − alíquota ICMS = 0,82` na fatura de referência (comprovada pelos valores R$ 1.845,32 Fora de Ponta e R$ 3.603,28 Ponta). O PIS/COFINS por kW permanece idêntico ao da demanda medida. O matching determinístico por menor erro quadrático em relação a `V_esperado` resolve a inversão da ordem física das linhas na fatura ENEL. Para outras distribuidoras, aplicar a regra específica declarada pelo leitor (fora de SP, cobrança costuma ser integral com `r = 1`).
- [x] Fator de calibração tributária `k` comprovado: `k = Preço com tributos ÷ Tarifa unit ≈ 1,29021` em todos os itens tributados da fatura de referência, equivalendo a `1 ÷ (1 − 18% − 4,4935%)`.
- [ ] Confirmar o formato do conjunto de dados abertos da ANEEL (nome, colunas, vigência) e o mapeamento de subgrupo, modalidade e posto para os do sistema; confirmar que a “Tarifa unit” da fatura ENEL (sem tributos) coincide com a tarifa de aplicação da ANEEL; confirmar que a TE é igual nas duas modalidades nas tabelas da ENEL SP; outros layouts de fatura podem não trazer a tarifa sem tributos nem o ICMS por item.
- [x] Tolerância de divergência entre os fatores `k` (RC-1c): `0,00003`; média mensal dos fatores válidos por item, half-up em 6 casas.
- [x] Tolerância da reconciliação (RC-1b): R$ 0,02, definida para a versão 1.
- [ ] Faixa padrão da demanda ótima, limites e prazos para alterar a demanda contratada, demanda mínima contratável.
- [ ] Restrições regulatórias para troca de modalidade (RC-4) e validação da obrigatoriedade da TUSD Consumo Ponta da Verde.
- [x] Benchmark: as planilhas Azul e Verde foram comparadas com os custos anuais de demanda e reativos; divergência máxima observada dentro de R$ 0,02 por causa do arredondamento monetário do RC-0.
- [ ] Bibliotecas a escolher: leitura de PDF (`smalot/pdfparser` ou `pdftotext`), geração de PDF, documentação da API (compatibilidade com PHP 8.5 e Laravel 13).
- [ ] Textos jurídicos (Política de Privacidade e Termos) e canal de contato comercial (WhatsApp ou e-mail).
- [ ] Segundo fator (TOTP) para o admin: adotar ou não (tarefa 11.2).
- [ ] Dados da empresa fictícia do orçamento (etapa 2).
