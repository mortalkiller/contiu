# Regras fiscais configuráveis e obrigações do mês — design

Data: 2026-11-09 · Estado: rascunho para aprovação do Pedro

## Intenção

Hoje a estimativa de impostos da Contiu está feita para o caso do Pedro e tem regras fixas: o
coeficiente de IRS é sempre 0,75, a Segurança Social não aplica a contribuição mínima, a isenção do
1.º ano é um interruptor manual e não existe IVA nas compras a fornecedores estrangeiros.

A Contiu é vendida a outros freelancers (ENI, regime simplificado), por isso estas regras passam a
depender das definições de cada conta. E a app passa a dizer, todos os meses, **o que o freelancer
tem de fazer e pagar e até quando**: um cartão "Este mês" no topo do painel, um email no dia 1 e uma
notificação antes de cada prazo.

As regras vêm da análise feita a 2026-11-09 (Código Contributivo, CIRS, CIVA, informações
vinculativas da AT), confirmada pelo Pedro. **São estimativas para planear: a app continua a dizer
"confirma com o teu contabilista".**

## Objetivos

1. **Cartão "Este mês" no painel** (opção A escolhida pelo Pedro, com esboço visto): obrigações do
   mês com valor, prazo, dias que faltam e "marcar como feito".
2. **Alertas**: email no dia 1 de cada mês com a lista do mês; notificação no sininho 3 dias antes
   de cada prazo.
3. **Segurança Social certa**: contribuição mínima de 20 €; taxa 21,4 % ou 25,2 %; isenção do
   1.º enquadramento calculada a partir da atividade; isenção de contribuições do art. 157.º
   quando a SS a reconheceu.
4. **IRS configurável**: coeficiente 0,75 ou 0,35; redução de 50 % / 25 % nos primeiros anos;
   IRS Jovem.
5. **IVA nas compras a fornecedores com sede fora de Portugal** (autoliquidação), com prazo e valor
   no cartão, mesmo para quem está isento pelo art. 53.º.
6. **O "isento de impostos" continua só nos registos**, como hoje (decisão do Pedro): cada
   recebimento e avença pode ser marcado isento, e as Definições têm "Recebimentos isentos por
   defeito". Um recebimento isento não conta para nenhuma estimativa nem obrigação. **Não há
   interruptor de isenção ao nível da conta**: o atual "Isento de IRS" sai das Definições.
7. **O registo pede tudo o que estas regras precisam** (decisão do Pedro: "tem de preencher tudo o
   que necessitarmos"). As Definições permitem mudar tudo depois.

## Não-objetivos

- Calcular o IRS a pagar (escalões, deduções, retenções). Continua a mostrar o **rendimento
  tributável** estimado.
- Regra dos 15 % de despesas do art. 31.º, n.º 13.
- Verificar sozinha as condições das isenções do art. 157.º (a app só pergunta se a SS a
  reconheceu), a suspensão de contagem do art. 145.º, n.º 4 (cessação ainda no período inicial) e a base mínima até à 1.ª declaração
  (art. 165.º).
- IVA na importação de bens e nas aquisições intracomunitárias de bens.
- Modelo 30 (pagamentos a não residentes).
- Regime normal de IVA: declarações periódicas de quem não está isento (só a autoliquidação das
  compras ao estrangeiro, que se aplica a todos).
- Taxas de IVA dos Açores e da Madeira (a taxa da autoliquidação é uma definição, 23 % por omissão).
- Submeter declarações às Finanças ou à SS. A app só avisa e calcula.

## Anti-objetivos

- Um valor que pareça oficial e esteja errado sem aviso. Cada número do cartão diz de onde vem.
- Pedir ao freelancer que faça contas: ele responde a perguntas de facto (datas, sim/não,
  escolhas com explicação) e a app calcula.
- Obrigações que não se aplicam à conta aparecerem no cartão.

## Definições da conta

Todas em `FinanceSettings` (chave/valor por conta), pedidas no registo e editáveis em
**Definições fiscais**. Valores entre parêntesis são os de omissão para contas existentes.

### Atividade

| Definição | Tipo | Notas |
|---|---|---|
| `activity_started_on` | data | Já existe. Data de início **ou reinício** nas Finanças. |
| `activity_start_kind` | `first` \| `restart` | Novo ("first"). |
| `previous_activity_ended_on` | data, opcional | Novo. Só para reinício: data de cessação da atividade anterior. |
| `ss_enrolled_from` | data, opcional | Novo. "Enquadrado a partir de", como aparece na Segurança Social Direta (Trabalho → Remunerações e contribuições → Trabalhadores independentes → Consultar e alterar os dados de atividade). Se vazio, a app calcula pela regra abaixo. |
| `dependent_income_years` | lista de anos | Novo. Anos em que houve salário (cat. A) ou pensão (cat. H). O registo pergunta só o ano atual; as Definições mostram o ano atual e o anterior. |

### IRS e Segurança Social

| Definição | Tipo | Notas |
|---|---|---|
| `irs_exempt` | — | **Sai.** O IRS passa a ser calculado com o coeficiente, a redução e o IRS Jovem; a isenção fica nos registos (`tax_exempt`). A migração apaga a definição. |
| `irs_coefficient` | `0.75` \| `0.35` (0.75) | Novo. "Serviços da tabela do art. 151.º" (ex.: programadores, designers, consultores) ou "Outras prestações de serviços". A escolha diz respeito à natureza do serviço, não ao código CAE/CIRS (Circular AT 2/2016). |
| `irs_jovem` | bool (false) | Novo. "Aderiste ao IRS Jovem?" |
| `birth_date` | data, opcional | Novo. Só com IRS Jovem (limite dos 35 anos). |
| `irs_jovem_year` | `{year, number}` | Novo. "Em <ano atual>, és no ano n.º … do IRS Jovem (1 a 10)". Os anos seguintes somam 1. |
| `ss_rate` | `0.214` \| `0.252` | Já existe como número; passa a escolha: "Trabalhador independente (21,4 %)" ou "Empresário em nome individual ou EIRL (25,2 %)". |
| `ss_relevant_income_share` | número (0.70) | Já existe. |
| `ss_contribution_exempt` | bool (false) | Novo. "A Segurança Social reconheceu-te isenção de contribuições?" (art. 157.º: acumulação com trabalho por conta de outrem, pensionista, ou só ter pago o mínimo no ano anterior). Ajuda: onde ver na Segurança Social Direta. |
| `ss_first_year_exempt` | — | **Sai.** Passa a ser calculado (ver regras). |

### IVA

| Definição | Tipo | Notas |
|---|---|---|
| `vat_exempt`, `vat_exemption_threshold` | — | Já existem. |
| `reverse_charge_vat_rate` | número (0.23) | Novo. Taxa usada na autoliquidação. |

### Fornecedores e clientes

- **Fornecedor**: novo campo `seat` com `pt` \| `eu` \| `non_eu` (omissão `pt`). "Onde fica a sede
  da empresa?". As despesas a fornecedores `eu` ou `non_eu` entram na autoliquidação.
- **Cliente**: novo campo `seat` igual, e `is_business` (bool). Serve para a declaração
  recapitulativa (abaixo).

### Contas existentes

A migração mantém os valores atuais e põe os novos nos de omissão. Como os novos campos de
atividade não existem nas contas antigas, o cartão "Este mês" mostra, enquanto faltarem, um aviso
"Completa as definições fiscais" com link para as Definições, em vez de valores de SS/IRS.

## Regras de cálculo

Tudo em cêntimos, num serviço único (evolução do atual `TaxEstimator`) usado pela página
**Estimativa de impostos**, pela **Previsão de tesouraria** e pelo cartão "Este mês".

### Rendimento

Como hoje: recebimentos (`payments`) não marcados `tax_exempt`. A marca "isento de impostos" de
cada recebimento e avença, e o "Recebimentos isentos por defeito", continuam iguais (decisão do
Pedro). Se todos os recebimentos de um trimestre forem isentos, o rendimento desse trimestre é 0
e a SS do trimestre seguinte é a contribuição mínima.

### Segurança Social (Código Contributivo)

- **Base mensal** = rendimento do trimestre × `ss_relevant_income_share` ÷ 3 (art. 162.º e 163.º, n.º 1).
- **Contribuição mensal** = base × `ss_rate`, **com mínimo de 20 €**, também em trimestres sem
  rendimento ou só com recebimentos isentos (art. 163.º, n.º 2; guia da SS de janeiro de 2026). Os
  20 € são a contribuição, não uma base. O mínimo é uma constante (2000 cêntimos).
- Com `ss_contribution_exempt` ligado não há contribuição: os meses aparecem "Isento (art. 157.º)".
- Excluir um recebimento do cálculo (marca "isento de impostos") não é o mesmo que estar isento de
  contribuir: com tudo excluído, a contribuição é o mínimo de 20 €.
- O IRS Jovem não reduz a base da SS (art. 162.º usa o valor das prestações de serviços).
- A SS considera os valores à data da prestação do serviço, como declarados no IRS. A Contiu usa a
  data do recebimento, que coincide com a fatura-recibo; a página diz que é uma aproximação.
- A contribuição de um trimestre paga-se nos 3 meses seguintes, entre os dias 10 e 20 (art. 155.º).
- **Mês em que começa a obrigação**: o mês de `ss_enrolled_from`, se preenchido (é o que a SS
  diz). Se não estiver, a app calcula e diz que é uma estimativa:
  - início (`first`): 1.º dia do 12.º mês posterior ao mês de início (art. 145.º, n.º 1);
  - reinício (`restart`): o mês do reinício (art. 145.º, n.º 3).
  Os meses antes desse são "Isento (1.º enquadramento)".

### IRS (CIRS, regime simplificado)

Por ano:

1. **Rendimento bruto** do ano.
2. **IRS Jovem** (art. 12.º-B), se ligado e com idade ≤ 35 anos a 31/12 do ano e ano n.º 1–10:
   - percentagem: ano 1 → 100 %; 2–4 → 75 %; 5–7 → 50 %; 8–10 → 25 %;
   - **parte isenta** = bruto × percentagem, com limite de 55 × IAS do ano;
   - IAS numa tabela em `config/contiu.php`: 2025 → 522,50 €; 2026 → 537,13 €. Um ano sem IAS
     na tabela usa o último conhecido e a página diz que é provisório.
3. **Coeficiente** = `irs_coefficient`, reduzido (art. 31.º, n.os 10 e 11):
   - 50 % no ano do início de atividade e 25 % no ano seguinte;
   - só se nesse ano não houver salário nem pensão (`dependent_income_years`);
   - não se aplica a um reinício com cessação anterior há menos de 5 anos.
4. **Rendimento tributável** = (bruto − parte isenta) × coeficiente.

A página mostra cada passo (bruto, isento pelo IRS Jovem, coeficiente usado e porquê, tributável).

### IVA

- **Limite da isenção (art. 53.º)**: como hoje, mais o patamar de 125 % (18 750 € com o limite de
  15 000 €). Acima de 100 %: sai da isenção no ano seguinte (declaração em janeiro). Acima de
  125 %: declaração de alterações em 15 dias úteis.
- **Autoliquidação** (CIVA, art. 2.º, n.º 1, al. e); art. 27.º, n.os 3 e 5):
  - despesas de serviços (`is_goods` falso) sem IVA (`vat_rate` 0) a fornecedores com `seat` `eu`
    ou `non_eu`, agrupadas pelo mês da data da despesa
    (`spent_on`, *decisão minha* como data de exigibilidade);
  - IVA = valor sem IVA × `reverse_charge_vat_rate`;
  - declaração e pagamento até ao último dia do mês seguinte;
  - meses sem estas compras não têm obrigação;
  - na isenção do art. 53.º o IVA é um custo (sem dedução, art. 53.º, n.º 3). No regime normal é
    neutro, mas tem de ser declarado; o cartão mostra o mesmo prazo.
- **Declaração recapitulativa** (RITI, art. 23.º e 30.º): se no mês houve recebimentos de clientes
  `eu` com `is_business`, obrigação até dia 20 do mês seguinte, sem valor a pagar.

## Cartão "Este mês" (painel)

Widget no topo do painel, por baixo dos números principais. Mostra o mês atual.

- **Linhas com valor e prazo**, por esta ordem de prazo:
  - Segurança Social do mês: valor, "Pagar entre 10 e 20 de <mês>", origem ("rendimentos de
    jul–set", "contribuição mínima"); nos meses de isenção, "Isento" sem prazo.
  - IVA das compras ao estrangeiro do mês anterior: valor, "Declaração periódica e pagamento até
    <último dia>", link para as despesas filtradas.
  - Declaração recapitulativa (se aplicável): "Até dia 20", sem valor.
  - Declaração trimestral da SS (janeiro, abril, julho e outubro): até ao fim do mês, sem valor.
- **Limite da isenção de IVA** (só com art. 53.º): barra de progresso e estado.
- **"A seguir"**: as obrigações dos próximos meses sem valor (próxima declaração trimestral da SS;
  IRS modelo 3 entre 1 de abril e 30 de junho).
- Cada linha mostra os dias que faltam e um botão **Marcar como pago / entregue**. Uma linha
  marcada sai do cartão. Fica guardada por conta e por obrigação (tabela nova
  `tax_obligation_completions`: `account_id`, `key` como `ss:2026-11` ou `vat-reverse:2026-10`,
  `completed_at`, `user_id`). Desmarcar é possível a partir do cartão ("Ver feitas").
- Sem obrigações no mês: "Nada a pagar este mês".
- Enquanto faltarem definições: aviso "Completa as definições fiscais".
- Numa conta só de leitura o cartão aparece, mas não marca.

## Alertas

- **Dia 1 de cada mês, 08:00**: email aos utilizadores da conta com as obrigações do mês (mesmo
  conteúdo do cartão) e o link para o painel. Não é enviado se não houver obrigações. Assinado com
  o nome da conta, como os outros emails (`SendsAsAccount`).
- **3 dias antes de cada prazo**: notificação no sininho, se ainda não estiver marcada como feita.
  Uma só por obrigação.
- Um comando agendado novo, conta a conta (como `finance:send-reminders`), que salta contas sem
  acesso (só de leitura).

## Registo

O passo **Impostos** do registo divide-se para pedir as definições novas:

1. **Atividade**: data de início, início ou reinício; se reinício, data da cessação anterior; data
   "Enquadrado a partir de" da Segurança Social Direta (opcional); se tiveste salário ou pensão
   este ano.
2. **IRS**: coeficiente (com explicação);
   IRS Jovem (data de nascimento e ano n.º).
3. **IVA**: como hoje.
4. **Segurança Social**: taxa (escolha), rendimento relevante, isenção de contribuições
   reconhecida (sim/não).

O passo de confirmação resume tudo. Os textos de ajuda explicam cada pergunta com um exemplo.

## Decisões tomadas na revisão (decisões minhas, para o Pedro confirmar ao ler)

1. **Autoliquidação só com despesas sem IVA.** Entram as despesas a fornecedores `eu`/`non_eu`
   com `vat_rate` = 0. Uma despesa a fornecedor estrangeiro com IVA > 0 não entra e aparece no
   cartão como aviso ("Despesa de fornecedor estrangeiro com IVA: confirma") com link para ela.
2. **Só serviços.** A autoliquidação é das compras de serviços. Cada despesa ganha um campo
   `is_goods` (bool, falso por omissão), visível só para fornecedores estrangeiros: "É uma compra
   de bens (material)?". Compras de bens não entram (importação e aquisições intracomunitárias de
   bens ficam fora, como não-objetivo).
3. **Definições incompletas** = falta `activity_started_on` ou
   `activity_start_kind`. Nesse estado o cartão mostra só "Completa as definições fiscais" no lugar
   das linhas de SS e IRS (o IVA mostra-se). A Estimativa e a Previsão calculam com `first` e mostram
   um aviso "Definições fiscais incompletas: valores aproximados". A migração **não** preenche
   `activity_start_kind` nas contas existentes, para que o aviso apareça.
4. **Mínimo de 20 € em todos os meses com obrigação**, também nos anos anteriores (a regra existe
   desde 2019). A regra do art. 165.º (mínimo até à 1.ª declaração) **sai** desta versão e passa a
   não-objetivo: cada mês usa o trimestre anterior, com o mínimo.
5. **Chaves e prazos de todas as obrigações**: `ss:AAAA-MM` (prazo dia 20), `ss-declaration:AAAA-Qn`
   (último dia de jan/abr/jul/out), `vat-reverse:AAAA-MM` (último dia do mês seguinte),
   `recapitulativa:AAAA-MM` (dia 20 do mês seguinte), `irs-modelo3:AAAA` (30 de junho). Todas se
   marcam como feitas e todas têm a notificação 3 dias antes do prazo final. **Em atraso**: uma
   obrigação não marcada continua no cartão depois do prazo, marcada "Em atraso", e entra no email
   do dia 1 seguinte.
6. **IRS Jovem**: guarda-se `irs_jovem_year` como par `{ano de referência, n.º}`; editar nas
   Definições grava o ano atual. Idade: até 35 anos inclusive a 31/12. **Acumula com a redução do
   art. 31.º, n.º 10**: parte isenta primeiro (sobre o bruto, IV n.º 28155), coeficiente reduzido
   no resto. Confirmado pelo Pedro a 2026-11-09 como interpretação conjugada dos art. 12.º-B e 31.º
   (o n.º 9 do 12.º-B não a exclui), sem informação vinculativa sobre a acumulação; a página diz-o.
   O IRS Jovem não mexe na Segurança Social.
7. **Quem edita** as Definições fiscais: quem já pode hoje (membros da conta). `birth_date` é do
   titular da atividade, por isso fica nas definições da conta (`FinanceSettings`), não no
   utilizador.

## O que fica para quem constrói

- Nomes de classes, a forma de dividir o `TaxEstimator` e o widget, os textos exatos de ajuda.
- Como guardar `dependent_income_years` e `irs_jovem_year` em `FinanceSettings` (valores JSON).
- O layout fino do cartão, seguindo o esboço aprovado e o tema da Contiu.

## Perguntas em aberto

(nenhuma)

## Exemplo: a conta do Pedro (dados da SS Direta e das Finanças, 2026-11-09)

| Definição | Valor |
|---|---|
| `activity_started_on` / `activity_start_kind` | 2025-08-07 / `restart` |
| `ss_enrolled_from` | 2025-08-01 |
| `ss_rate` | 0,214 (trabalhador independente) |
| `ss_contribution_exempt` | sim (acumulação com trabalho por conta de outrem) |
| `dependent_income_years` | anos com salário (sem redução do art. 31.º nesses anos) |
| `irs_coefficient` | 0,75 (programação, tabela do art. 151.º) |

Resultado esperado: SS "Isento (art. 157.º)" todos os meses; IRS com coeficiente 0,75 sem redução
(e IRS Jovem se aderir); IVA das compras ao estrangeiro e limite da isenção no cartão.
