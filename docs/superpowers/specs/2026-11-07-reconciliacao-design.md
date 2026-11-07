# Extrato bancário simples: explicar cada linha

Data: 2026-11-07 · Estado: proposta final para aprovação do Pedro (substitui a versão com saldos a
consumir)

## Porquê

O Pedro é ENI no regime simplificado, com uma **conta da atividade** (a do extrato que importa) e a
**conta pessoal**. Clientes pagam-lhe às vezes na conta pessoal e ele passa o dinheiro depois, por vezes
juntando vários pagamentos; paga algumas despesas com a conta pessoal; recebe donativos ocasionais
pelo Stripe (o apoiante paga 5,00 €, o Stripe fica com 0,68 €, chegam 4,32 €); há clientes que pagam
em partes.

**Regra do Pedro: o sistema não pode dar trabalho a gerir.** Tem de ser o mais simples possível e
correto. A app ainda é local: dados e modelo podem ser refeitos e reimportados.

## Princípio

- **Os registos são a verdade** para impostos e contas: recebimentos (com fatura-recibo) e despesas (com
  fatura). Registam-se quando e onde o dinheiro mexeu, em qualquer conta.
- **O extrato só confirma** que nada do que passou pela conta da atividade ficou por registar.
- Por isso a reconciliação é **só do lado do extrato**: cada linha tem de ficar explicada. A app nunca
  pede para reconciliar recebimentos ou despesas, nem guarda saldos por reconciliar em registos.

## Como cada linha fica explicada

Uma linha do extrato fica num de quatro estados:

| Estado | Quando | Efeito nas contas |
|---|---|---|
| **Por tratar** | acabou de ser importada | nenhum |
| **Registado** | ligada a um ou mais registos (que já existiam ou que criaste a partir dela) | os registos é que contam |
| **Transferência minha** | dinheiro entre contas do Pedro (de/para a conta pessoal, ordenado) | nenhum: não é receita nem despesa |
| **Ignorado** | qualquer outra coisa que não interessa | nenhum |

### Registado

- Uma linha liga-se a **um ou mais registos**: recebimentos (entram com +), despesas (entram com −).
  Ex.: cliente que paga duas faturas numa transferência; pagamento do Stripe = receita − comissão.
- A app mostra a soma dos registos escolhidos e a **diferença** para o valor da linha. Diferença zero é
  o normal; se houver (câmbio de uma despesa em dólares, arredondamento) o Pedro vê-a e confirma. Não
  há saldos parciais nem "fica por reconciliar".
- Cada registo só pode estar ligado a uma linha.
- A partir da linha pode criar o que falta: despesa (nova, de uma recorrente ou de um fornecedor),
  recebimento de um valor a receber (total ou parcial) ou receita avulsa. O que é criado fica ligado à
  linha.
- **Desfazer** devolve a linha a "Por tratar"; o que foi criado a partir dela é apagado (com
  confirmação), o que já existia fica como estava. Uma despesa que a linha marcou como paga volta a por
  pagar.

### Transferência minha

- Um clique, ou automático com "Aceitar sugestões": a app **sugere** quando a descrição tem o nome do
  Pedro (todas as palavras do nome do utilizador, ex.: "TRF DE PEDRO FILIPE PEREIRA MONTEIRO") ou
  "ordenado".
- Cobre as duas direções (entrada vinda da conta pessoal, saída para a conta pessoal). Não se diz a que
  recebimentos ou despesas corresponde: **contabilisticamente não corresponde a nenhum**, é dinheiro do
  titular a mudar de conta. O recebimento do cliente que caiu na conta pessoal já foi registado com a
  data em que o cliente pagou.

## Sugestões automáticas (para "Aceitar sugestões" fazer quase tudo)

Por ordem, sem empates (empate = sem sugestão, o Pedro escolhe):

1. Registo já existente com o mesmo valor até 10 dias antes ou depois (já implementado).
2. Valor a receber em aberto com o mesmo valor (já implementado) **ou menor, com o nome do cliente na
   descrição → recebimento parcial** (novo).
3. Despesa por pagar com o mesmo total; despesa recorrente; fornecedor no nome (já implementado).
4. **Transferência minha** (novo, regra acima).

Trabalho mensal esperado: carregar o extrato → "Aceitar sugestões" → tratar à mão as poucas linhas
que sobram.

## Receita avulsa (donativos e outras entradas sem trabalho nem avença)

- Formulário curto (no "+ Novo", na lista de recebimentos e a partir de uma linha do extrato): data,
  categoria de receita (ex.: "Donativos"), descrição, **valor bruto**, **comissão** (opcional, com o
  fornecedor, ex.: Stripe), nº da fatura-recibo, "Isenta de impostos" (predefinição das definições).
- Cria a receita **paga** pelo bruto e, se houver comissão, uma **despesa paga** de estrutura com a
  comissão (categoria "Comissões"). A partir do extrato, a linha de 4,32 € fica explicada pelas duas
  (5,00 − 0,68).
- Conta no painel, previsão, margens e impostos como os trabalhos: é faturada e recebida na mesma data,
  numa linha de negócio própria ("Receitas avulsas"), IVA 0.

## Recebimentos parciais de clientes

Já existem (um valor a receber com vários recebimentos). Do extrato, só muda a sugestão automática
acima. Cada recebimento parcial deve ter a sua fatura-recibo.

## O que sai (comparado com a proposta anterior)

Saldos "por reconciliar" em recebimentos e despesas, valores por associação, campo "conta" em cada
registo, "Entrada/Levantamento do titular" separados, listas de registos por reconciliar.

## O que muda no que já existe

- `bank_transactions`: a ligação a um único registo (`matched_type/matched_id`) passa a ligação a vários
  registos, com indicação de se o registo foi criado a partir da linha (para o desfazer). Estados:
  por tratar, registado, transferência minha, ignorado.
- Receita avulsa: entra no mesmo circuito dos trabalhos e avenças (valor a receber já pago), para que
  todos os totais existentes a incluam sem exceções. A forma (novo tipo de "referente a" ou outra) fica
  ao builder.
- Dados: podem ser apagados e reimportados (Excel + extrato).

## Enquadramento legal e contabilístico (a confirmar com contabilista)

- No regime simplificado não há obrigação de conta exclusiva para a atividade (art. 63.º-C da LGT é
  para contabilidade organizada). Usar a conta pessoal é legal; separar é boa prática.
- O rendimento conta quando o cliente paga, onde quer que pague; a transferência entre contas do
  titular não é rendimento nem gasto.
- Para imposto contam faturas/faturas-recibo emitidas e despesas com NIF no e-fatura (regra dos 15% do
  art. 31.º, n.º 13 do CIRS), não a conta por onde passou o dinheiro.
- Receita por plataforma: rendimento = bruto (valor da fatura-recibo); comissão = gasto.
- Por confirmar: fatura-recibo a apoiantes anónimos/estrangeiros; se a isenção de IRS abrange donativos.

## Deixado ao builder

Nomes, tabelas e classes; forma exata da receita avulsa; layout dos modais (seguindo os existentes).
Tudo com testes primeiro, Phosphor, pt-PT, `Money::format()`, e verificação do layout no browser.
