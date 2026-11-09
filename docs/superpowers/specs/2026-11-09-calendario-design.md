# Calendário da atividade — design

Data: 2026-11-09 · Estado: aprovado pelo Pedro

## Intenção

O Pedro quer "uma visão calendarizada da minha atividade" e ver os avisos dos prazos de entrega e
de pagamento. Tudo o que tem data na Contiu passa a aparecer num calendário: as obrigações fiscais,
os recebimentos, os valores a receber, as despesas e as avenças e despesas recorrentes ainda por
gerar. Ao lado do calendário fica uma coluna com os prazos que vêm a seguir, com os atrasados
primeiro, para os avisos não se perderem no meio do mês.

O calendário só mostra. Os registos e as obrigações continuam a ser criados, alterados e marcados
como feitos nos sítios onde já estão.

## Objetivos

1. **Página "Calendário"** no grupo "Análise", com o calendário à esquerda e a coluna "Prazos a
   seguir" à direita. É a opção B, escolhida pelo Pedro depois de ver o esboço.
2. **Seis tipos de evento** com cores diferentes e uma legenda que também serve de filtro (ligar e
   desligar cada tipo):

   | Tipo | Data no calendário | Texto | Ao clicar |
   |---|---|---|---|
   | Obrigações fiscais | `dueOn` | título, mês ou ano a que respeita, valor se houver | `url` da obrigação (quando existe) |
   | Recebimentos (`Payment`) | `paid_on` | "Recebido · cliente · valor" | lista de recebimentos |
   | A receber (`Receivable` não pago) | `due_on` | "A receber · descrição · valor em falta" | lista de valores a receber |
   | Despesas (`Expense`) | `spent_on` | "fornecedor ou descrição · valor", com "por pagar" se `is_paid` for falso | editar a despesa |
   | Avenças previstas | datas do `ScheduleProjector::retainerDueDates()` | "Avença · cliente (prevista) · valor" | ver a avença |
   | Recorrentes previstas | datas do `ScheduleProjector::recurringExpenseDates()` | "descrição (prevista) · valor" | lista de despesas recorrentes |

3. **Estado das obrigações visível pela cor**: por cumprir (âmbar), em atraso (vermelho), feita
   (verde, riscada). As isentas não têm prazo e não aparecem.
4. **Obrigações com janela** (a SS paga-se de 10 a 20 e o IRS de abril a junho) aparecem **só no dia
   do prazo**. A janela vem no texto do tooltip (o `detail` da obrigação), para o mês não ficar
   cheio de barras compridas.
5. **Coluna "Prazos a seguir"**: as obrigações de `TaxObligations::pending()` (em atraso primeiro,
   depois por prazo) e a seguir as de `upcoming()`, cada uma com o prazo e uma etiqueta "em atraso"
   ou "N dias". Sem nada pendente, mostra "Sem prazos por cumprir".
6. **Vistas**: mês (por omissão), lista ("Agenda", o mês em lista) e ano (`multiMonthYear`). A
   semana começa à segunda-feira e todos os textos estão em pt-PT.
7. **Tema claro e escuro** com a paleta verde da Pressiu, tanto no calendário como na coluna.

## Não-objetivos

- Criar, arrastar ou redimensionar eventos no calendário. Marcar obrigações como feitas também
  fica de fora: isso continua no cartão "Este mês" do painel.
- Novas notificações, emails ou lembretes.
- Exportar para iCal ou Google Calendar.
- Mostrar no calendário as "notas" do `TaxObligations` (definições incompletas, limiar do IVA,
  despesas estrangeiras com IVA), porque não têm data.

## Anti-objetivos (falha mesmo que funcione)

- **Duplicar regras fiscais.** O calendário lê as obrigações do `TaxObligations` e as datas
  previstas do `ScheduleProjector`, sem copiar nenhuma regra ou prazo.
- **Mostrar dados de outra conta.** Tudo passa pelo tenant atual (`BelongsToAccount`).
- **Contar a mesma coisa duas vezes.** Uma avença já gerada aparece como "A receber" e nunca também
  como "prevista". O `ScheduleProjector` já só projeta depois do último registo gerado. Um valor a
  receber pago não aparece, porque o recebimento já está lá.
- **Ficar lento em contas com muitos registos.** Só se lê o intervalo visível.

## Decisões

### Plugin: `saade/filament-fullcalendar` ^5.0 (MIT)

Comparado com o `guava/calendar` 3.2.2, porque ganha em:

- **pt-PT**: o locale `pt` da app chega ao FullCalendar como `pt` (português europeu, incluído no
  pacote). O Guava só aceita a língua sem região e não traz tradução `pt`.
- **Multi-conta**: tem tenancy documentada, com a relação `account`.
- **Tema**: usa as cores do painel e segue o modo escuro.
- **Manutenção**: 5.0.0 estável a 2026-10-27, último push a 2026-11-05 e 4 issues abertas, contra
  26 do Guava, cujo último push foi em julho.

Ambos instalam sem conflitos com Filament 5.11.2, Laravel 13.40 e PHP 8.5 (`composer require
--dry-run`). Só se usam as vistas gratuitas (`dayGrid`, `list`, `multiMonth`), por isso não é
precisa nenhuma licença FullCalendar Premium. **A aprovação deste design inclui adicionar esta
dependência.** O risco é a 5.0 ser recente. Por isso, o plano começa por uma verificação curta do
plugin dentro do tema da Contiu: pt-PT, semana a começar à segunda-feira, vista de ano e modo
escuro. Se a 5.x falhar nalgum destes pontos, a alternativa é a linha `^4.0` do mesmo plugin
(FullCalendar 6, também compatível com Filament 5). Se essa também falhar, o trabalho para e o
Pedro é consultado *(decisão do Claude)*.

O CSS do plugin entra no `resources/css/filament/admin/theme.css` e depois corre o
`npm run build`.

### Obrigações para qualquer intervalo

Hoje o `TaxObligations` só tem métodos públicos para "este mês" e "próximos". O cálculo para um mês
qualquer (`dueIn()`) é privado. Passa a haver um método público, por exemplo
`between(CarbonImmutable $from, CarbonImmutable $to): list<TaxObligation>`, que:

- junta `dueIn()` de cada mês do intervalo;
- deixa de fora as obrigações sem `dueOn` (isentas) e as que caem fora do intervalo;
- respeita o mesmo início que o `dueEarlier()`, incluindo o limite de `OVERDUE_MONTHS` (12 meses
  para trás): não mostra nada de antes desse limite, de antes de "Acompanhar obrigações desde" ou,
  sem essa data, de antes da criação da conta. Assim o calendário nunca mostra em atraso uma
  obrigação que o painel já não mostra. Esse cálculo do início fica num só sítio, partilhado pelos
  dois métodos *(decisão do Claude)*;
- funciona tanto para meses passados como futuros, com o estado calculado em relação a hoje, como
  no resto do serviço.

**Valor da Segurança Social nos meses futuros** (decisão do Pedro: "só com trimestre fechado").
O valor da SS de um mês vem dos rendimentos de um trimestre anterior. Se esse trimestre ainda não
fechou (o último dia é depois de hoje), a obrigação continua no dia 20, mas **sem valor**
(`amount` a `null`) e com "valor por apurar" no detalhe, em vez de "contribuição mínima". A regra
fica no `TaxObligations::ss()`, para valer em todo o lado. O cartão "Este mês" não muda, porque o
trimestre de origem da SS do mês atual já está sempre fechado. Exemplo com hoje a 2026-11-09:

- a SS de dezembro de 2026 e a de janeiro de 2027 vêm do 3.º trimestre, já fechado, por isso
  mostram o valor;
- a de fevereiro de 2027 vem do 4.º trimestre, ainda aberto, por isso fica "por apurar".

### Onde fica o código

- `App\Support\ActivityCalendar` (nome à escolha do builder): recebe o intervalo e os tipos ligados
  e devolve uma lista de entradas simples (tipo, data, título, valor em cêntimos, estado, url,
  detalhe). Não depende do plugin, o que facilita os testes.
- Um widget do plugin em `app/Filament/Analysis/Widgets/`, que transforma essas entradas em eventos
  do FullCalendar (cores por classe CSS, `allDay`, `url`, tooltip) e tem os filtros por tipo.
- A página `App\Filament\Pages\Calendar` (slug `calendario`, título "Calendário", grupo "Análise",
  ícone Phosphor de calendário, a seguir às páginas que já existem no grupo) junta o widget e a
  coluna "Prazos a seguir".
- Valores com `Money::format()`. Valores das despesas e das previstas com IVA (`total`), como no
  `CashForecast`. O "A receber" mostra o `outstanding`.

## Testes (Pest, TDD)

- `TaxObligations::between()`: devolve as obrigações de vários meses, incluindo passados e futuros;
  não inclui as isentas nem as de antes do início do acompanhamento; marca bem as feitas e as em
  atraso. A SS de um mês cujo trimestre de origem ainda não fechou não tem valor e diz "valor por
  apurar". O cartão "Este mês" continua igual.
- Serviço do calendário: cada tipo cai no dia certo; um `Receivable` pago não aparece; os
  cancelados (`excludingCancelled`) também não; as previstas não repetem o que já foi gerado; os
  filtros desligam os tipos; só aparece o intervalo pedido.
- Isolamento: os registos de outra conta não aparecem.
- Página: abre para um membro da conta e mostra a coluna com os prazos em atraso primeiro.
- Depois, `vendor/bin/pint --dirty`, toda a suíte (`php artisan test --compact --parallel`) e
  confirmação no browser em modo claro e escuro.

## Fica para o builder

- Os nomes exatos das classes e métodos, e os tons de cada cor dentro da paleta (âmbar e vermelho
  para os avisos, verdes da Pressiu para os recebimentos, neutros para as despesas, tracejado para
  as previstas), desde que funcionem nos dois temas.
- Onde guardar os filtros ligados (propriedade Livewire, ou na URL como no `CashForecast`).
- Como desenhar a coluna "Prazos a seguir" (widget ou vista Blade), seguindo os componentes que o
  painel já usa.
