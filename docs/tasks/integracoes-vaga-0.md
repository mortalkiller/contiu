# Contiu · Validação das integrações da vaga 0

Levantamento de 11 de novembro de 2026. As conclusões distinguem o que consta na documentação oficial do que só pode ser confirmado com acesso ao piloto. Os exemplos são dados sintéticos para testes futuros. Os campos dos exemplos representam a leitura proposta pela Contiu, exceto quando identificados como campos do fornecedor.

| Frente | Resultado da vaga 0 | Próxima validação |
| --- | --- | --- |
| 0A · Email | Percurso AWS proposto e exemplo local preparado | Provar a entrega SMTP e confirmar a infraestrutura do piloto |
| 0B · Bancos | O catálogo público da TrueLayer para Portugal só devolveu Revolut | Verificar cobertura por banco e modalidade antes de escolher o fornecedor |
| 0C · Recebimentos | Contratos e exemplos separados para Stripe, ifthenpay e Eupago | Confirmar permissões e entregas em sandbox por fornecedor |
| 0D · Faturação | Ambos documentam rascunhos com referência a um artigo existente | Testar a conta de demonstração Moloni e confirmar um ambiente de teste Moloni ON |
| 0E · e-Fatura | Contrato de consulta como adquirente verificado e XML sintético validado | Provar acesso com o perfil do piloto e comparar com o portal |
| 0F · Clockify | Consulta de horas documentada e cálculo sintético preparado | Validar permissões, amostra autenticada e origem da tarifa |

## Pontos de partida na Contiu

- **Despesas.** `ExpenseDocumentQr` e `ImportExpenseDocuments` já tratam a leitura de QR, a pré-visualização e a confirmação da importação. O email e o e-Fatura devem aproveitar a identificação de documentos existente e testar a sobreposição com CSV e QR.
- **Bancos.** `ImportBankStatement` já guarda movimentos pendentes a partir do extrato. A sua impressão digital inclui data, valor, descrição, saldo e ocorrência. Um fornecedor bancário precisa da sua própria identidade por conta e de uma regra separada para propor correspondências com movimentos já importados por ficheiro, porque a descrição e o saldo podem diferir.
- **Recebimentos.** Usar o fluxo de pagamentos da conta e `PaymentAllocation` para os valores a receber. `RecordAllocatedPayment` serve para repartições por pelo menos duas cobranças. Para pagamentos simples, usar o fluxo de uma cobrança. `PlatformPayout` e `PlatformPayoutAmounts` são pontos de partida para depósitos e comissões. Verificar que a confirmação do fornecedor e a linha do banco representam o mesmo recebimento.
- **Faturação.** `FiscalProviderRegistry` resolve atualmente apenas o InvoiceXpress. O trabalho partilhado da vaga 3 deve permitir escolher o fornecedor por conta e preservar o histórico das ligações ao mudar de fornecedor. Manter as regras de emissão e o estado de validação separados por fornecedor.
- **Calendário.** `ActivityCalendar` e `TaxObligations` já calculam prazos e vencimentos. A subscrição proposta usa essas fontes e é independente das validações dos fornecedores desta vaga.

## Faturas por email · 0A

**Decisão proposta.** Usar AWS SES para receber mensagens, S3 privado para guardar temporariamente o MIME e SNS/SQS para entregar o aviso à Contiu. A regra de receção indica o destinatário SMTP. Assim, um reencaminhamento ou uma cópia oculta podem ser atribuídos à conta certa mesmo quando o cabeçalho `To` mostra outro endereço. A Contiu recebe a mensagem pela fila, usando permissões limitadas ao tópico, à fila e aos objetos de entrada. [Receção SES](https://docs.aws.amazon.com/ses/latest/dg/receiving-email-concepts.html) · [Campos da notificação](https://docs.aws.amazon.com/ses/latest/dg/receiving-email-notifications-contents.html) · [Política SNS/SQS](https://docs.aws.amazon.com/sns/latest/dg/subscribe-sqs-queue-to-sns-topic.html)

**Alternativas avaliadas.** A Resend documenta receção e webhooks assinados. Falta confirmar um destinatário do envelope SMTP que permita tratar BCC e reencaminhamentos sem ambiguidade. A Mailgun documenta esse destinatário e é uma alternativa caso o percurso AWS não esteja disponível. [Resend · Evento de receção](https://resend.com/docs/webhooks/emails/received) · [Mailgun · Receção HTTP](https://documentation.mailgun.com/docs/mailgun/user-manual/receive-forward-store/receive-http)

**Limites propostos.** Aceitar até 20 MiB por mensagem e 10 MiB por PDF descodificado, alinhando o segundo valor com o formulário atual de comprovativos. O SES documenta até 40 MB para a mensagem completa na ação S3. Usar um alias revogável por conta. Entregas com vários destinatários de contas diferentes ficam para revisão. A fila pode repetir a entrega. Usar o ID da mensagem por conta, o hash do PDF para reenvios idênticos e a identidade fiscal para versões diferentes da mesma fatura. [Quotas SES](https://docs.aws.amazon.com/ses/latest/dg/quotas.html) · [Entregas repetidas em SQS](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html)

**Exemplo sintético.** O evento SES tem `mail.messageId = fixture-001`, `receipt.recipients = ["bills-a@inbound.example.test"]` e `receipt.action.objectKey = ingress/fixture-001`. O MIME tem `To: bills-b@inbound.example.test` e um PDF válido de uma página sem QR. Esperar um anexo em revisão na conta A, zero entradas na B e zero despesas automáticas. O PDF sintético foi descodificado e validado localmente. Repetir a entrega, simular uma falha antes de a confirmar e testar um PDF com o mesmo documento que entrou por QR ou CSV.

**Por validar no piloto.** Região AWS com receção de email, domínio e DNS, permissões, retenção do MIME, fila de mensagens com falha e consumidor supervisionado. A configuração Laravel contempla S3/SQS, mas o checkout ainda não tem os adaptadores AWS necessários instalados nem há prova atual de um consumidor de fila da Contiu em produção. Confirmar também a leitura de QR diretamente de PDFs. A vaga 1A pode preparar e testar o fluxo com mensagens simuladas antes da prova de entrega SMTP.

## Bancos · 0B

**Alvo do piloto.** Cobrir os bancos de uso comum em Portugal. Testar primeiro contas à ordem em EUR. Uma conta pessoal está em nome da pessoa. Uma conta de negócio pode ser de um trabalhador independente ou ENI em nome próprio, ou de uma sociedade. Validar separadamente as modalidades que cada banco disponibiliza, porque a oferta comercial e a cobertura da API podem diferir.

| Prioridade | Instituições a verificar | Segmentos |
| --- | --- | --- |
| Primeira ronda | CGD, Millennium bcp, Santander, BPI, novobanco, ActivoBank, Crédito Agrícola, Banco CTT e Banco Montepio | Pessoal, profissional/ENI e sociedade, quando disponíveis. Confirmar a oferta empresarial do ActivoBank e a cobertura do Banco CTT para sociedades antes de prometer esses segmentos. |
| Segunda ronda | Revolut, moey!, Wise e N26 | Distinguir Revolut Pessoal, Pro e Business e validar moey! à parte do Crédito Agrícola. Confirmar a oferta e a cobertura por modalidade na Wise e N26. |

**Observado no catálogo público.** Em 11 de novembro de 2026, a API Data v1 da TrueLayer devolveu apenas Revolut (`ob-revolut-pt`) ao consultar Portugal, com e sem o filtro de permissões para leitura. Nenhum dos nove bancos da primeira ronda apareceu nessa consulta. A sandbox pública devolveu o Mock Bank. A presença no catálogo não prova disponibilidade para a app da Contiu ou para um segmento de conta. [Consulta pública](https://auth.truelayer.com/api/providers?country=pt&scopes=accounts%20transactions%20offline_access) · [Catálogo da sandbox](https://auth.truelayer-sandbox.com/api/providers) · [Cobertura na Console](https://docs.truelayer.com/docs/supported-providers-table)

**Decisão proposta.** Manter a importação por extrato e deixar a escolha do fornecedor aberta para a 2A. Confirmar na Console a cobertura Data por instituição e a elegibilidade de uma nova app para Data v1 em Portugal. A Salt Edge e a Kontomatik publicam catálogos portugueses e entram como alternativas a comparar. Validar acesso para a Contiu, condições comerciais, histórico e disponibilidade por segmento antes de escolher. [TrueLayer Data v1](https://docs.truelayer.com/docs/data-api-basics) · [Salt Edge · Portugal](https://www.saltedge.com/products/account_information/coverage/pt) · [Kontomatik · Cobertura](https://developer.kontomatik.com/coverage?resource=ais)

**Exemplo sintético.** Conta interna `banco-teste-a`, conta remota `acct-test-01`, crédito EUR de 75 000 cêntimos, descrição `AVENCA TESTE 001`, `transaction_id = txn-test-01-a` e `normalised_provider_transaction_id = txn-stable-test-01`. A Contiu sugere uma cobrança de 75 000 cêntimos. Repetir a leitura com outro `transaction_id`, ou reconectar e receber outro ID de conta remota, preserva o movimento quando o identificador estável permite confirmar a correspondência. Sem esse identificador, duas entradas legítimas de igual valor têm de continuar distintas. Testar também sobreposição com o extrato, moeda diferente e consentimento expirado. [IDs de movimentos](https://support.truelayer.com/hc/en-us/articles/360025889254-Why-are-transaction-ids-subject-to-change) · [IDs de contas](https://support.truelayer.com/hc/en-us/articles/360014473857-Why-are-my-account-id-s-changing-after-reauthentication)

**Por validar no piloto.** Para cada banco e modalidade, registar o ID do fornecedor, o tipo de conta devolvido, o movimento mais antigo disponível, uma nova leitura em segundo plano e o resultado da reconexão. Fazer a prova com uma conta consentida num ambiente autorizado. A leitura de contas reais, o histórico, a renovação do consentimento e a cobertura de contas de negócio permanecem por confirmar.

## Stripe · 0C

**Confirmado na documentação.** Os depósitos automáticos permitem consultar os movimentos de saldo por identificador do depósito. O evento `payout.reconciliation_completed` indica que a composição já pode ser consultada. Para depósitos manuais, a Stripe disponibiliza o histórico de saldo sem atribuir os movimentos ao depósito. A sandbox é isolada. Para um piloto de uma conta, usar uma chave restrita de leitura emitida pelo comerciante. O webhook da integração de recebimentos deve ter um destino e um segredo próprios, separados do webhook existente das subscrições da Contiu. Verificar a assinatura sobre o corpo bruto e confirmar a conta e o ambiente antes de processar. [Reconciliação](https://docs.stripe.com/payouts/reconciliation) · [Chaves restritas](https://docs.stripe.com/keys/restricted-api-keys) · [Sandboxes](https://docs.stripe.com/sandboxes) · [Webhooks](https://docs.stripe.com/webhooks)

**Decisão proposta.** Usar os dados Stripe como primeira amostra para a vaga 2B. Importar primeiro por consulta e usar eventos para acelerar a atualização. Guardar os identificadores da conta, ambiente, evento e movimento de saldo. Reutilizar os componentes do depósito local com cuidado. O fluxo manual de depósito pode criar uma receita avulsa, que duplicaria uma receita já afetada a uma cobrança.

**Exemplo sintético, em cêntimos EUR.**

| Movimento | Identificador de origem | Bruto | Comissão | Líquido |
| --- | --- | ---: | ---: | ---: |
| Pagamento 1 | `ch_teste_1` | 60 000 | 1 800 | 58 200 |
| Pagamento 2 | `ch_teste_2` | 40 000 | 1 200 | 38 800 |
| Reembolso parcial do pagamento 2 | `re_teste_1` | −2 000 | 0 | −2 000 |

O depósito automático `po_teste_1` é de 95 000 cêntimos, a somar os três líquidos. Sem reembolso, seria de 97 000 cêntimos. Afetar o primeiro pagamento de 60 000 cêntimos a uma cobrança de 75 000 cêntimos deixa 15 000 cêntimos em aberto. Repetir o evento, a sincronização e a importação do extrato deve deixar dois pagamentos, um reembolso, um depósito e uma associação bancária. Testar também a chegada do extrato antes do fornecedor e a chegada de um evento antes de a composição do depósito estar disponível.

**Por validar no piloto.** Acesso a uma sandbox do comerciante, permissões mínimas, destino HTTPS de teste com entrega assinada e disponibilidade de um depósito automático reconciliável. Uma diferença, uma moeda inesperada ou um depósito manual sem composição associada fica para revisão.

## ifthenpay · 0C

**Confirmado na documentação.** Para Multibanco e MB WAY, a ifthenpay documenta um callback GET de pagamento confirmado com segredo partilhado, valor, identificador do pedido e identificador da cobrança. O Multibanco acrescenta entidade e referência. O MB WAY tem também uma notificação POST opcional de estado. A assinatura dessa notificação cobre `orderId`, `amount` e `requestId`. A consulta autenticada de pagamentos concluídos pode devolver valor bruto, comissão, líquido e data de processamento. [Callbacks e notificação MB WAY](https://ifthenpay.com/docs/en/guides/callback/) · [Consulta de pagamentos](https://www.ifthenpay.com/docs/en/api/list-of-payments-rest/)

**Decisão proposta.** Ligar a confirmação a uma referência ou pedido já conhecido. Usar a consulta de pagamentos para recuperar confirmações em falta e resolver divergências. Usar a notificação de estado MB WAY como sinal para consultar o pedido quando for necessário confirmar o estado, porque a assinatura documentada não cobre o campo de estado. Uma referência é texto e deve preservar os zeros iniciais.

**Exemplo sintético.** Cobrança `CT-MB-001`, pedido `fixture-mb-001`, entidade `00000`, referência `000000001`, 75 000 cêntimos EUR. A consulta devolve 75 cêntimos de comissão e 74 925 cêntimos líquidos. Confirmar que duas notificações em simultâneo criam apenas um recebimento e que o movimento do banco reconcilia o líquido. Testar valor divergente, método incorreto, evento atrasado e MB WAY recusado ou expirado.

**Por validar no piloto.** Chaves de teste para cada método, acesso autorizado à consulta de pagamentos, endereço HTTPS de teste, configuração de callbacks e confirmação do histórico e dos estados que chegam na prática. O simulador público pode testar a receção sem provar o fluxo financeiro de ponta a ponta. [Acesso de teste](https://www.ifthenpay.com/docs/en/) · [Simulador de webhooks](https://ifthenpay.com/docs/tools/webhook-tester/)

## Eupago · 0C

**Confirmado na documentação.** A API tem ambiente de sandbox. As notificações v2 são enviadas por POST, documentam os estados de pagamento, reembolso, erro, cancelamento e expiração e incluem uma assinatura HMAC SHA-256 no cabeçalho `X-Signature`. O corpo pode ser cifrado. A v1 comunica apenas pagamentos confirmados e inclui uma chave de API nos parâmetros. Existe uma consulta autenticada do estado de uma referência Multibanco. [API e sandbox](https://docs.eupago.pt/reference/api-eupago) · [Notificações v2](https://docs.eupago.pt/reference/realtime-webhooks-20) · [Notificações v1](https://docs.eupago.pt/reference/webhooks) · [Consulta de referência](https://docs.eupago.pt/reference/reference-information)

**Decisão proposta.** Usar a v2 no piloto e verificar a assinatura antes de aceitar os dados. Associar o evento à ligação da conta, ao canal e a uma referência já conhecida. O identificador da transação evita repetir o recebimento. Um novo estado da mesma transação pode exigir uma ação diferente. Uma expiração ou um erro não cria um recebimento. Um reembolso confirmado deve ser tratado como reversão ou seguir para revisão se faltar a transação original.

**Exemplo sintético.** Conta de teste A, canal `canal-teste-a`, cobrança `cobranca-750`, transação `7000001`, estado `Paid`, montante de 75 000 cêntimos, comissão de 125 cêntimos e moeda EUR. Repetir a notificação produz um só recebimento. Alterar o valor, trocar o canal ou enviar uma assinatura inválida não regista o pagamento. Receber a expiração antes de um pagamento válido ou um reembolso antes do pagamento original exige verificar o estado sem perder informação.

**Por validar no piloto.** Acesso à v2 no canal de teste, bytes exatos assinados com e sem cifra, representação dos montantes e das comissões e eventos efetivamente entregues para Multibanco e MB WAY. O montante de comissão do exemplo é fictício. O depósito líquido só deve ser reconciliado quando existir evidência do pagamento do fornecedor ao banco.

## Moloni e Moloni ON · 0D

### Moloni

**Confirmado na documentação.** O Moloni clássico usa OAuth 2.0, HTTP POST e respostas JSON. O acesso exige um Developer ID e o acesso à API na conta que vai usar a integração. O Explorer de sandbox exige que o login tenha acesso à conta de demonstração. A criação de uma fatura aceita `status: 0` para rascunho e `status: 1` para fechar. A série é escolhida com `document_set_id`. A linha exige `product_id` e dados do documento, como nome, quantidade e preço. A API lista faturas e expõe operações de leitura de séries. [Autenticação](https://www.moloni.pt/dev/autenticacao) · [Sandbox](https://www.moloni.pt/dev/sandbox/) · [Criar fatura](https://www.moloni.pt/dev/documents/invoices/insert/) · [Faturas](https://www.moloni.pt/dev/documents/invoices/) · [Séries](https://www.moloni.pt/dev/settings/)

**Por validar no piloto.** Obter acesso à demonstração, selecionar uma empresa e série de teste, ler um artigo existente, criar o rascunho em `status: 0` e reler o artigo e o documento. Comparar código, preço, impostos e stock antes e depois. Confirmar para cada tipo fiscal o seu estado, a série e os campos de referência usados na sincronização. Um timeout após o envio requer procurar o rascunho antes de reenviar.

### Moloni ON

**Confirmado na documentação.** É uma API GraphQL distinta. Aceita OAuth 2.0 ou chave de API. As operações sobre uma empresa exigem o módulo API Access nessa empresa. `invoiceCreate` permite usar `status: 0` para rascunho, uma série `documentSetId` e um artigo existente por `productId`. A linha pode usar o preço e os impostos do artigo, ou receber ajustes no próprio documento. Consultar a lista e o detalhe dos documentos e tratar erros de GraphQL e da operação, mesmo quando a resposta HTTP indica sucesso. [Acesso e autenticação](https://docs.molonion.pt/guides/gettingStarted) · [Criar fatura](https://docs.molonion.pt/guides/documents/creatingAnInvoice) · [Consultar documentos](https://docs.molonion.pt/guides/documents/queryingDocuments)

**Por validar no piloto.** A documentação publica um Explorer ligado à API e não identificámos um endpoint de sandbox isolado. O Moloni ON documenta um trial de 30 dias com acesso a módulos e emissão sem validade fiscal enquanto a conta não for pré-validada para emissão fiscal. É a via proposta para uma empresa dedicada a testes, com API Access, dados sintéticos e apenas rascunhos. Confirmar primeiro as leituras de séries e artigos. A sandbox do Moloni clássico é um acesso separado. [Condições do trial, secção 8](https://www.molonion.pt/legal/subscriptionconditions/moloni_on_condicoes_especificas_subscri%C3%A7%C3%A3o_v1.0_en.pdf) · [Séries por tipo no Moloni ON](https://docs.molonion.pt/reference/queries/documentSetsForDocument)

**Recuperação depois de um timeout.** As listas dos dois fornecedores permitem procurar pela referência local (`our_reference` no Moloni e `ourReference` no Moloni ON). Confirmar empresa, série, tipo, cliente, valor e estado antes de aceitar uma correspondência. A documentação consultada não confirmou chaves de idempotência para a criação de documentos. Se a pesquisa encontrar zero ou vários resultados, encaminhar para revisão antes de reenviar. [Pesquisa no Moloni](https://www.moloni.pt/dev/documents/documents/getall/) · [Filtros no Moloni ON](https://docs.molonion.pt/reference/enums/DocumentFilterField)

**Exemplo sintético para os dois.** Cobrança `cobranca-750`, empresa de teste `10`, série `20`, cliente `30`, artigo de serviço `40`, quantidade 1 e preço unitário local de 75 000 cêntimos. Usar `status: 0` e uma referência local estável. O catálogo mantém o preço de referência mesmo se o preço da linha diferir. O rascunho fica fora dos totais fiscais. A importação de um documento final correspondente deve associá-lo uma vez à cobrança. Neste levantamento foram consultadas as referências públicas, sem criar rascunhos ou emitir documentos.

## e-Fatura · 0E

**Confirmado na documentação e no WSDL.** A operação SOAP `Invoices` permite consultar como adquirente com `CustomerTaxID` igual ao NIF autenticado, usando janelas de até um mês e paginação. A resposta pode incluir número, ATCUD, NIF do fornecedor, data, tipo e totais. O ATCUD pode estar vazio. O contrato não expõe a afetação pessoal, profissional ou parcial. A ligação descrita pela AT usa certificado de cliente e autenticação SOAP. A FAQ refere o perfil WFA para comunicação e consulta, mas não confirma o seu uso como adquirente para o perfil do piloto. [WSDL](https://info.portaldasfinancas.gov.pt/pt/apoio_ao_contribuinte/Outras_entidades/Suporte_tecnologico/Webservice/e_Fatura/Documents/fatshareInvoices.wsdl) · [Manual de consulta](https://info.portaldasfinancas.gov.pt/pt/apoio_ao_contribuinte/Outras_entidades/Suporte_tecnologico/Webservice/e_Fatura/Documents/Comunicacao_dos_elementos_dos_documentos_de_faturacao.pdf#page=58) · [Requisitos de acesso](https://info.portaldasfinancas.gov.pt/pt/apoio_ao_contribuinte/Outras_entidades/Suporte_tecnologico/Webservice/e_Fatura/Documents/Comunicacao_dos_elementos_dos_documentos_de_faturacao_aspetos_gerais.pdf) · [FAQ do perfil WFA](https://info.portaldasfinancas.gov.pt/pt/faturas/Pages/faqs-00978.aspx)

**Exemplo sintético.** Consulta de outubro de 2026 com o NIF de teste `100000003`. A resposta devolve fornecedor `100000004`, fatura `FT DEMO/1`, ATCUD `TESTEABC-1`, data 9 de outubro, 3 000 cêntimos sem IVA, 690 de IVA e 3 690 no total. O pedido e a resposta sintéticos foram validados localmente contra o XSD. Os leitores de QR e CSV da Contiu devolveram os mesmos montantes. Na vaga 3D, testar uma única correspondência por conta, fornecedor e referência, confirmando ATCUD e totais quando existirem. Um valor divergente, uma nota de crédito ou uma fatura sem correspondência vai para revisão.

**Por validar no piloto.** Elegibilidade da Contiu para a consulta, certificado e permissão mínima do trabalhador independente. Antes de fechar a 3D, obter uma leitura autenticada autorizada e comparar uma janela com o portal do adquirente. Incluir, se disponíveis, uma fatura profissional, uma pessoal ou pendente e uma nota de crédito. O levantamento não fez pedidos SOAP à AT.

## Clockify · 0F

**Confirmado na documentação.** A API lista entradas de tempo por workspace e utilizador. Devolve o identificador da entrada, o projeto, o estado faturável e o intervalo. O relatório detalhado pode ser útil para equipas, mas tem paginação e um formato de resposta próprios. A chave pessoal herda as permissões do utilizador. O acesso e o URL dependem da configuração do workspace. A documentação indica Basic ou superior para alterar o estado faturável. Falta confirmar o valor de `billable` devolvido por um workspace Free. [API](https://docs.clockify.me/) · [Permissões](https://clockify.me/help/troubleshooting/general-api-troubleshooting/can-i-restrict-api-key-access) · [Plano Free](https://clockify.me/help/administration/billing/updates-to-clockify-free-plan)

**Decisão proposta.** Começar com um utilizador e um workspace de teste, mapeando o identificador do projeto para um trabalho da Contiu. Importar entradas fechadas por ID e manter a duração em segundos. Voltar a ler períodos completos para detetar correções. Confirmar a remoção de uma entrada antes de a retirar da proposta, porque pode ter mudado de projeto, data ou permissões. O trabalho na Contiu não tem hoje uma tarifa horária própria. Usar uma tarifa aprovada antes de calcular a proposta e pedir revisão antes de criar uma cobrança.

**Exemplo sintético.** A entrada `te-1` tem 12 horas faturáveis, a entrada `te-2` tem uma hora não faturável e a tarifa de teste é de 5 000 cêntimos por hora. Esperar 13 horas de esforço, 12 horas faturáveis e 60 000 cêntimos na proposta. Corrigir `te-1` para 11 horas e 30 minutos atualiza a mesma proposta para 57 500 cêntimos. Repetir a importação preserva os dois IDs. Uma correção após a faturação gera uma divergência para revisão.

**Por validar no piloto.** Plano, região, permissões, entradas faturáveis e não faturáveis, paginação e alterações no workspace escolhido. A fonte da tarifa, o arredondamento e o IVA fazem parte da decisão sobre a criação da cobrança.

## Sequência recomendada

- **Vaga 1.** Preparar email com dados simulados e calendário em paralelo. A prova real de email depende da configuração do domínio e da infraestrutura. O calendário usa as fontes da Contiu.
- **Vaga 2.** Usar a amostra Stripe para preparar o registo comum de recebimentos. Validar depois cada fornecedor no seu ambiente de teste. A ligação ao banco só fica pronta para piloto quando a cobertura por banco e modalidade for confirmada.
- **Vagas 3 e 4.** Preparar a seleção do fornecedor fiscal com respostas simuladas. Validar rascunhos Moloni e Moloni ON em separado, a consulta e-Fatura com o perfil do piloto e as horas com um workspace Clockify autorizado. A criação de pedidos de pagamento usa a confirmação já validada para o fornecedor escolhido.
