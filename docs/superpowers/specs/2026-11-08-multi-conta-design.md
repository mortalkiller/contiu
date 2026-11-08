# Contiu multi-conta (SaaS para freelancers) — design

Data: 2026-11-08 · Estado: rascunho para aprovação do Pedro

## Intenção

A Contiu deixa de ser a ferramenta pessoal do Pedro e passa a ser um produto vendido a outros
freelancers portugueses (ENI, regime simplificado). Cada freelancer regista-se sozinho, responde a
um assistente com as definições essenciais e começa logo a usar, com 14 dias de experiência. Depois
paga uma assinatura. Os dados de cada freelancer ficam isolados numa **conta**.

O Pedro continua a usar a app como hoje: os dados atuais passam a ser a primeira conta, a dele.

A app vai evoluir para ajudar na declaração trimestral (Segurança Social), por isso o NIF passa a
fazer parte da conta.

## Objetivos

1. **Contas isoladas** (multi-tenancy do Filament): cada registo de negócio pertence a uma conta, e
   um utilizador só vê as contas a que pertence.
2. **Registo self-service com assistente (wizard) em passos** que pede tudo o que é essencial antes
   de criar a conta.
3. **Assinatura paga** com período de experiência: 5 €/mês ou 54 €/ano (10 % de desconto).
4. **Super admins** (só eles) veem e entram em todas as contas, para dar apoio.
5. Tirar da app o que hoje está feito à medida do Pedro (ver "Funcionalidades atuais que mudam").

## Não-objetivos (primeira versão)

- Convidar outras pessoas para uma conta (ex.: contabilista). O modelo de dados permite vários
  utilizadores por conta (tabela pivot), mas não há ecrã de convites.
- Emitir a fatura da assinatura automaticamente num programa certificado. A Stripe cobra; o Pedro
  emite a fatura no programa dele (InvoiceXpress, Moloni ou Portal das Finanças).
- Declaração trimestral, NISS, vários planos, cupões, eliminar conta pelo próprio utilizador
  (na v1, pedido por email e um super admin elimina).

## Anti-objetivos (falha mesmo que "funcione")

- **Dados de uma conta visíveis noutra**, por qualquer caminho: ecrã, pesquisa global, selects,
  widgets, exportações, emails, notificações, comandos agendados, validação `exists`/`unique`.
  Isto é o pior erro possível do produto.
- Um assistente de registo longo, com perguntas que a app não usa.
- Partir os dados ou os fluxos atuais do Pedro (reconciliação, fecho do mês, previsão, etc.).
- Uma conta que fica só de leitura perde dados. Os dados nunca são apagados automaticamente.

## Decisões do Pedro

- Uso: vender a outros freelancers (SaaS).
- Registo: o freelancer regista-se sozinho e começa logo; assistente com passos que pede todas as
  configurações essenciais para criar a conta.
- Acesso a todas as contas: **só super admins**.
- Assinatura com período de experiência; 5 €/mês ou anual com 10 % de desconto (54 €/ano).
- NIF obrigatório no registo (preparar a declaração trimestral).
- Os conceitos atuais (recebimentos, despesas, trabalhos, avenças, extrato, reconciliação,
  "Transferência minha", fecho do mês, previsão) mantêm-se, agora dentro de cada conta.

## Modelo

### Conta (`accounts`)

Modelo `Account` (na UI: "Conta"). Campos: `name` (nome comercial ou nome do freelancer), `slug`
(único, no URL), `nif` (9 dígitos, validado com o dígito de controlo português, único entre contas),
`trial_ends_at`, `billing_exempt` (bool; a conta do Pedro), colunas do Cashier (Stripe), timestamps.

Pivot `account_user` (`account_id`, `user_id`, `role` = `owner` na v1). Quem cria a conta é `owner`.

### Que tabelas ganham `account_id`

Todas as de negócio: `clients`, `suppliers`, `categories`, `projects`, `retainers`, `receivables`,
`payments`, `expenses`, `recurring_expenses`, `one_off_incomes`, `bank_transactions`,
`bank_transaction_links`, `client_messages`, `settings`. Também `audits` (para filtrar o histórico
por conta).

Ficam globais: `users`, tabelas de permissões, `notifications` (por utilizador; os dados da
notificação levam `account_id` para o link abrir na conta certa), `jobs`, `cache`, `sessions`.

Únicos que passam a ser por conta: `categories (account_id, type, name)`, `projects.code`,
`retainers.code`, `one_off_incomes.code`, `settings (account_id, key)`,
`bank_transactions (account_id, hash)` — reimportar o mesmo extrato noutra conta não pode colidir.
A geração de códigos (ex.: `RecordOneOffIncome::nextCode`) passa a contar só dentro da conta.

### Isolamento: como garantir

O Filament só limita automaticamente as queries dos Resources. Grande parte da lógica está fora
deles (`CompanyAccount`, `TransferCoverage`, `Reconciliation`, `MatchBankTransactions`,
`ApplyBankTransaction`, `MonthReview`, `CashForecast`, `FinanceSettings`, widgets, comandos).
Por isso:

- Um trait `BelongsToAccount` em todos os modelos de negócio: **global scope** pela conta atual e
  preenchimento automático de `account_id` ao criar.
- A "conta atual" vem de um serviço único (ex.: `CurrentAccount`), definido pelo middleware de
  tenancy do Filament (`Filament::getTenant()`) e, nos comandos/jobs, explicitamente por conta.
- **Falha fechada**: sem conta atual definida, uma query a um modelo de negócio lança exceção (não
  devolve tudo). Só código explícito de super admin/migração pode desligar o scope.
- Regras `exists:`/`unique:`, `Rule::unique`, selects com `relationship()` e `createOption` têm de
  respeitar a conta.
- Testes: um teste de arquitetura que verifica que todos os modelos com `account_id` usam o trait;
  testes de acesso cruzado (URL de um registo de outra conta → 404; pesquisa global; selects;
  widgets do painel; exportação Excel; comandos agendados).

### Filament

- Painel com `->tenant(Account::class, slugAttribute: 'slug')`. URLs passam a
  `/{conta}/payments/...`. Seletor de conta no topo só aparece a quem tem mais de uma (ou a super
  admins).
- `User::getTenants()` devolve as contas do utilizador; para super admins devolve todas.
  `canAccessTenant()` idem.
- Página "Perfil da conta" (tenant profile): nome, NIF.
- As **Definições financeiras** passam a ser da conta (tabela `settings` com `account_id`).

### Papéis

- Shield continua sem "teams". `super_admin` é global: entra em todas as contas, nunca paga, vê as
  páginas de sistema.
- Utilizador normal: `panel_user`, dono da sua conta, acesso total dentro dela.
- Só super admins veem: Papéis (Shield), Cópias de segurança, Importar Excel (formato pessoal do
  Pedro), e uma lista de contas (nome, NIF, estado da assinatura, data de registo) para apoio.

## Registo: assistente em passos

Página de registo própria (substitui o `Register` do Filament) com `Wizard`. No fim cria, numa
transação: utilizador, conta, pivot `owner`, definições, categorias base, `trial_ends_at = +14 dias`.
Depois pede a verificação do email (`->emailVerification()`) e entra no painel da conta, vazio.

1. **Acesso** — nome, email, palavra-passe (+ confirmação).
2. **Atividade** — nome da conta, NIF (validado), data de início de atividade.
3. **Impostos** — isento de IRS? isento de IVA (art. 53.º)? limite de isenção (pré-preenchido com o
   valor legal atual). Sem pressupor a resposta: cada pergunta tem uma frase de ajuda curta.
4. **Segurança Social** — taxa (21,4 %), parte do rendimento relevante (70 %), isenção do primeiro
   ano (sugerida a partir da data de início de atividade).
5. **Banco** (pode saltar) — nomes que aparecem nas transferências (titular e outras contas suas),
   um por linha, com exemplo genérico.
6. **Confirmar** — resumo e aceitação dos Termos de Serviço e da Política de Privacidade (obrigatório).

## Assinatura

- **Laravel Cashier (Stripe)** com `Account` como entidade que paga.
- Preços na Stripe: mensal 5,00 €, anual 54,00 €. Sem IVA (Pedro isento, art. 53.º); sem Stripe Tax.
- **Experiência**: 14 dias sem cartão (`trial_ends_at` na conta, "generic trial" do Cashier), acesso
  total. Aviso discreto no topo com os dias que faltam e botão "Subscrever".
- **Página "Assinatura"** na conta: escolher mensal ou anual → Stripe Checkout. Gerir cartão,
  recibos e cancelamento → portal de faturação da Stripe. Webhooks do Cashier.
- **Sem experiência e sem assinatura ativa → só leitura**: pode ver tudo e exportar, não pode criar,
  alterar, apagar, importar extratos nem enviar emails. Aviso fixo a explicar e a levar à página
  "Assinatura". Implementação recomendada: um único ponto (ex.: `Gate::before` + verificação nas
  actions que não passam por policies), não espalhado por cada ecrã.
- Super admins e contas com `billing_exempt` (a do Pedro) nunca ficam só de leitura.
- Meios de pagamento: cartão. MB WAY/Multibanco só se a Stripe os suportar em subscrições
  recorrentes (verificar na documentação da Stripe na altura; não é requisito).
- A fatura legal é emitida pelo Pedro fora da app (ver não-objetivos).

### Regras da assinatura (decisões do Claude, para o Pedro confirmar)

- **Conta ativa** = experiência a decorrer, ou assinatura em estado `active`/`trialing`, ou
  `past_due` (a Stripe ainda está a tentar cobrar; não se bloqueia quem está a pagar), ou cancelada
  mas ainda dentro do período pago (grace period do Cashier). Fica **só de leitura** com
  `unpaid`, `incomplete_expired`, `canceled` depois do fim do período, ou experiência terminada
  sem assinatura. Sem período extra depois dos 14 dias.
- **Subscrever durante a experiência mantém os dias que faltam**: o Checkout recebe `trial_end`
  igual a `trial_ends_at`, e a primeira cobrança é nesse dia.
- **A experiência conta desde o registo** (14 dias a partir da criação da conta). O painel fica
  bloqueado até o email estar verificado (comportamento normal do `->emailVerification()`).
- **Uma conta por registo.** Um utilizador com sessão não cria segunda conta na v1 (sem página de
  "criar conta" dentro do painel). O seletor de conta só aparece a quem pertence a mais de uma
  (na prática, super admins).

### Saber o que faturar (decisão do Claude)

A fatura portuguesa precisa do nome, NIF e morada do cliente. O nome e o NIF já estão na conta;
a **morada** é pedida no Stripe Checkout (morada de faturação obrigatória) e guardada pela Stripe.
A cada pagamento confirmado (webhook `invoice.paid`), os super admins recebem um email com conta,
NIF, morada, valor, data e período, e a lista de contas dos super admins mostra os pagamentos
recebidos para conferir o que já foi faturado.

### Aceitação dos termos (decisão do Claude)

Guardar no utilizador `terms_accepted_at` e `terms_version`. Pedir nova aceitação quando a versão
muda fica para depois.

## Funcionalidades atuais que mudam

1. **Categorias**: cada conta nova recebe um conjunto base. Receita: Serviços, Avenças, Outras
   receitas. Despesa: Software e subscrições, Equipamento, Comunicações e internet, Deslocações,
   Formação, Contabilidade, Comissões (o nome usado por `RecordOneOffIncome::COMMISSION_CATEGORY`),
   Outras despesas. As do Pedro ficam como estão.
2. **Importar Excel**: só super admins.
3. **Linha de negócio**: o `datalist` deixa de ter "Printee, Pressiu" fixo e passa a sugerir as
   linhas já usadas na conta (trabalhos e avenças).
4. **Emails a clientes** (lembrete de pagamento, extrato): assinados com o nome da conta, `reply-to`
   com o email do dono da conta, `from` continua o endereço da Contiu.
5. **Definições fiscais**: os valores por omissão (`FinanceSettings::DEFAULTS`) deixam de assumir a
   situação do Pedro; uma conta nova tem os valores que respondeu no assistente.
6. **Textos com dados do Pedro**: placeholder "PEDRO FILIPE PEREIRA MONTEIRO / Ordenado" nas
   definições e o "Ordenado" no Centro de ajuda → exemplos genéricos. O Centro de ajuda ganha uma
   secção curta sobre a conta e a assinatura.
7. **Comandos agendados** (`finance:generate-recurring`, `finance:send-reminders`): correm conta a
   conta, com a conta atual definida. Contas só de leitura não geram nem enviam nada.

## Migração dos dados atuais

- Criar a conta do Pedro (`billing_exempt = true`), com o NIF dele (pedido no deploy ou preenchido
  depois no perfil da conta; a coluna pode ser `nullable` só para contas `billing_exempt`).
- Pôr `account_id` dessa conta em todas as linhas existentes, incluindo `settings`.
- O utilizador do Pedro fica `owner` dessa conta e `super_admin`. Em produção é o **único**
  utilizador e o único super admin (confirmado pelo Pedro). O "Admin" do `DatabaseSeeder` é só de
  desenvolvimento; o seeder passa a criar também uma conta de exemplo para esse utilizador.
- Não se criam super admins pelo ecrã de Utilizadores: o papel `super_admin` só se atribui por
  comando (`php artisan` / Shield), para não abrir por engano o acesso a todos os dados (RGPD).
- A migração tem de correr em PostgreSQL (produção, Supabase) e SQLite (testes).

## Restrições

- Laravel 13, Filament 5, PostgreSQL em produção, Pest. UI em pt-PT. Dinheiro em cêntimos.
- **MAIL tem de estar configurado em produção** antes de abrir o registo (verificação de email,
  recuperação de palavra-passe). Hoje está por configurar.
- O cron do scheduler em produção (também por configurar) passa a ser obrigatório.
- Termos de Serviço e Política de Privacidade (RGPD: o Pedro passa a ser subcontratante dos dados
  dos clientes dos freelancers) têm de existir antes do lançamento. O texto é do Pedro/jurista;
  o builder cria as páginas públicas e a estrutura.

## Deixado ao builder

- Nomes exatos de classes, estrutura do wizard, aspeto do aviso de experiência/só leitura.
- Geração do `slug` (a partir do nome, com sufixo se repetido).
- Versão do Cashier compatível com Laravel 13.
- Como apresentar a lista de contas aos super admins (Resource simples chega).
- Ordem de implementação, desde que o isolamento (scope + testes) venha antes do registo público.
