# Integrações futuras da Contiu

O catálogo em **Definições → Integrações** agrupa as propostas por área. Os cartões «Brevemente» mostram funcionalidades planeadas. O InvoiceXpress continua disponível em **Faturação**, com acesso aos documentos através de **Receitas → Documentos fiscais**.

## Vagas de trabalho

A numeração indica a prioridade proposta. Cada tarefa pode começar quando as suas dependências estiverem resolvidas. Uma resposta pendente de um fornecedor só adia as tarefas ligadas a esse fornecedor.

### Vaga 0 · Validar acesso e preparar o piloto

Estas tarefas são de investigação e podem ser atribuídas em paralelo. Cada subagente entrega os requisitos de acesso, exemplos de dados sem informação real e as dúvidas que ainda precisam de resposta.

[Resultados da vaga 0, exemplos sintéticos e validações em falta](integracoes-vaga-0.md)

| Tarefa | Resultado esperado |
| --- | --- |
| **0A · Email** | Escolher a forma de receber mensagens e autenticar a entrega. Confirmar limites de anexos, endereços por conta e armazenamento privado. |
| **0B · Bancos** | Confirmar cobertura dos bancos e tipos de conta do piloto, histórico, renovação do consentimento e acesso de teste. Avaliar alternativas à TrueLayer se a cobertura portuguesa for insuficiente. |
| **0C · Recebimentos** | Validar Stripe, ifthenpay e Eupago em três tarefas independentes. Recolher exemplos de identificadores, estados, montantes e notificações. Confirmar o acesso de teste e como verificar os eventos de cada fornecedor. |
| **0D · Faturação** | Validar Moloni e Moloni ON separadamente. Confirmar APIs, séries, documentos, sandbox e formas de referenciar artigos sem os alterar. |
| **0E · e-Fatura** | Confirmar se o perfil do piloto consegue consultar documentos como adquirente, quais os campos devolvidos e como testar a correspondência com despesas. |
| **0F · Clockify** | Confirmar o acesso aos registos de tempo e como identificar projetos, horas faturáveis e entradas corrigidas ou removidas. |

### Vaga 1 · Despesas e calendário

| Tarefa | Depende de | Entrega e validação |
| --- | --- | --- |
| **1A · Faturas por email** | 0A e o fluxo existente de importação de despesas | Receber PDFs numa fila de revisão. Reutilizar a leitura de QR e a deteção de duplicados. Testar o reenvio e a associação de um PDF a uma despesa já importada. |
| **1B · Google Calendar e Apple Calendar** | O calendário e os prazos fiscais existentes | Publicar uma subscrição iCalendar com link revogável. Validar datas, filtros e revogação nos dois clientes. |

**Em paralelo.** Um subagente fica com a receção e a revisão de despesas e outro com a subscrição do calendário. Google e Apple usam o mesmo feed e ficam na mesma tarefa.

### Vaga 2 · Movimentos bancários e recebimentos

| Tarefa | Depende de | Entrega e validação |
| --- | --- | --- |
| **2A · Movimentos bancários** | 0B, fornecedor escolhido após confirmação de cobertura e fluxos existentes de importação de extratos e reconciliação | Importar movimentos em modo de leitura e sugerir correspondências. Testar repetições, movimentos que já entraram por ficheiro e consentimento expirado. |
| **2B · Registo de recebimentos externos** | Exemplos de pelo menos um fornecedor em 0C e fluxos existentes de afetação de pagamentos e depósitos de plataformas | Definir onde os fornecedores entregam os dados à Contiu. Testar a identificação por conta e fornecedor, os montantes em cêntimos e a proteção contra duplicados. |
| **2C · Stripe** | 0C para Stripe e 2B | Importar pagamentos, comissões, reembolsos e depósitos. Validar a composição de um depósito e a reconciliação ao cêntimo. |
| **2D · ifthenpay** | 0C para ifthenpay e 2B | Receber e verificar confirmações de referências já associadas a cobranças. Cobrir notificações repetidas e valores divergentes. |
| **2E · Eupago** | 0C para Eupago e 2B | Receber e verificar confirmações de referências já associadas a cobranças. Cobrir eventos repetidos ou fora de ordem e estados que exigem revisão. |

**Em paralelo.** 2A e 2B podem avançar em simultâneo. Depois de 2B, atribuir 2C, 2D e 2E a subagentes diferentes. A validação dos recebimentos usa o extrato existente enquanto 2A estiver em curso. Quando ambas as frentes estiverem prontas, testar um pagamento que chega pelo fornecedor e aparece no banco, confirmando que é contabilizado uma só vez.

### Vaga 3 · Documentos fiscais, e-Fatura e trabalhos

| Tarefa | Depende de | Entrega e validação |
| --- | --- | --- |
| **3A · Seleção do fornecedor fiscal** | 0D para pelo menos um dos produtos e o fluxo atual de Documentos fiscais | Rever o registo de fornecedores, as capacidades, a escolha da ligação e a associação de documentos. Confirmar que os fluxos do InvoiceXpress continuam a funcionar antes de integrar novos fornecedores. |
| **3B · Moloni** | 0D para Moloni e 3A | Configurar a ligação, criar rascunhos e sincronizar documentos. Verificar na sandbox que os artigos existentes se mantêm intactos. |
| **3C · Moloni ON** | 0D para Moloni ON e 3A | Implementar e validar os mesmos fluxos com as regras e a sandbox do Moloni ON. |
| **3D · e-Fatura** | 0E e a identificação de despesas existente | Comparar faturas recebidas com despesas da Contiu e sinalizar divergências. Acrescentar o teste de correspondência com a fila de email quando 1A estiver disponível. |
| **3E · Clockify** | 0F e os fluxos de trabalhos e cobranças | Importar e associar horas, apresentar o esforço por trabalho e propor uma cobrança para revisão. Testar alterações e importações repetidas. |

**Em paralelo.** 3A, 3D e 3E podem avançar em simultâneo. Depois de 3A, Moloni e Moloni ON podem ser implementados em paralelo, com cada subagente responsável pelo seu fornecedor. A validação da emissão definitiva é feita separadamente para cada fornecedor, depois da prova em sandbox.

### Vaga 4 · Pedidos de pagamento

| Tarefa | Depende de | Entrega e validação |
| --- | --- | --- |
| **4A · Pedido de pagamento a partir de uma cobrança** | 2B e pelo menos uma das tarefas 2D ou 2E | Permitir escolher o fornecedor e apresentar a referência Multibanco ou o pedido MB WAY na cobrança. Associar a confirmação pelo fluxo da vaga 2. |
| **4B · Pedidos via ifthenpay** | 4A e 2D | Gerar referências e pedidos em sandbox. Testar pedidos repetidos, expirados ou pagos. |
| **4C · Pedidos via Eupago** | 4A e 2E | Gerar referências e pedidos em sandbox. Testar pedidos repetidos, expirados ou pagos. |

**Em paralelo.** 4B e 4C podem avançar em simultâneo depois de 4A. Esta vaga pode decorrer em paralelo com a vaga 3.

### Coordenação dos subagentes

- Atribuir a cada subagente o código e os testes de uma tarefa. A sessão principal integra as mudanças no catálogo, nas traduções e nas rotas partilhadas.
- Fechar a tarefa partilhada antes de distribuir os fornecedores que dependem dela. Se dois agentes precisarem de mudar o mesmo fluxo, integrar essa mudança primeiro.
- Fechar cada vaga com testes dos fluxos afetados, validação no browser quando houver alterações de interface e um registo das verificações feitas com dados simulados ou em sandbox.

## Faturação · Moloni e Moloni ON

Adicionar os dois fornecedores como ligações distintas. A configuração fica em Integrações e os fluxos de preparação, envio e sincronização ficam em Documentos fiscais. Confirmar separadamente as APIs, autenticação, séries, tipos de documento, leitura do catálogo, limites e ambientes de teste de cada produto.

**Exemplo.** Preparas a fatura de uma avença de 750 € na Contiu, escolhes o fornecedor configurado e crias um rascunho com um artigo que já existe no catálogo. Ao sincronizar o documento final, a Contiu liga-o à cobrança original.

**Primeira validação.** Confirmar na sandbox que o artigo se mantém intacto e que repetir o envio ou a importação preserva um único documento ligado à cobrança. Validar a emissão definitiva separadamente para cada fornecedor.

[Moloni](https://www.moloni.pt/) · [Moloni ON](https://www.molonion.pt/)

## Bancos · TrueLayer

Avaliar a TrueLayer como opção para importar movimentos bancários com consentimento do utilizador e acesso de leitura. Antes de escolher o fornecedor, confirmar cobertura, tipos de conta, histórico disponível e renovação do consentimento para os bancos do piloto.

**Exemplo.** Entra uma transferência de 750 € e a Contiu sugere associá-la à cobrança da avença. Se houver várias cobranças compatíveis, o utilizador escolhe a associação.

**Primeira validação.** Repetir a sincronização sem duplicar movimentos, testar a transição da importação por ficheiro e confirmar que as propostas respeitam os montantes e o âmbito da conta.

[Data API](https://docs.truelayer.com/docs/data-api-basics) · [Cobertura bancária](https://docs.truelayer.com/docs/supported-providers-table)

## Despesas · Faturas por email

Dar a cada conta um endereço de receção. Os PDFs recebidos entram numa fila de revisão. Quando houver um QR legível, reutilizar a leitura e a deteção de duplicados da importação de despesas. Definir o serviço de receção, os limites dos anexos e o armazenamento privado.

**Exemplo.** Reencaminhas a fatura de 24,60 € do alojamento. A Contiu propõe o fornecedor, a data e o valor. Se a fatura já entrou por CSV, a revisão permite juntar o PDF à despesa existente.

**Primeira validação.** Cobrir reenvios, vários anexos, PDFs sem QR e faturas já registadas por CSV ou QR.

[Reencaminhamento por filtros no Gmail](https://support.google.com/mail/answer/10957)

## Recebimentos · Stripe, ifthenpay e Eupago

- **Stripe.** Importar os pagamentos, comissões, reembolsos e depósitos da conta Stripe do freelancer. Um depósito de 970 € pode corresponder a dois pagamentos que somam 1 000 € e a 30 € de comissões. A Contiu mostra a composição e permite reconciliar o depósito com o banco.
- **ifthenpay.** Associar confirmações de pagamento a cobranças. Numa fase seguinte, permitir criar uma referência Multibanco ou um pedido MB WAY para uma cobrança de 750 € e apresentar essa opção ao cliente.
- **Eupago.** Associar confirmações de pagamento a cobranças já ligadas a uma referência. Numa fase seguinte, permitir gerar referências Multibanco e pedidos MB WAY. Confirmar na sandbox qual a versão das notificações disponível para o piloto, como verificar a sua autenticidade e quais os estados que comunica.

**Primeira validação.** Testar pagamentos parciais, notificações repetidas, comissões, reembolsos e falhas de associação. Um pagamento confirmado deve ser contabilizado uma vez, mesmo quando chega pelo fornecedor e também aparece no banco.

[Stripe · Reconciliação de depósitos](https://docs.stripe.com/reports/payout-reconciliation) · [ifthenpay · API](https://www.ifthenpay.com/docs/en/) · [Eupago · API e sandbox](https://docs.eupago.pt/reference/api-eupago) · [Eupago · Notificações](https://docs.eupago.pt/reference/realtime-webhooks-20)

## Calendário · Google Calendar e Apple Calendar

Publicar um calendário iCalendar de leitura, com link revogável por conta. O utilizador escolhe se quer mostrar apenas prazos fiscais ou também os vencimentos de cobranças. As datas vêm dos serviços de calendário existentes.

**Exemplo.** Subscreves o calendário no telemóvel e vês o prazo da declaração trimestral da Segurança Social ao lado das tuas reuniões.

**Primeira validação.** Confirmar as datas em ambos os clientes, testar a revogação do link e evitar nomes de clientes ou montantes na configuração por defeito. A atualização depende da frequência de leitura de cada cliente.

[Google Calendar](https://support.google.com/calendar/answer/37100) · [Apple Calendar](https://support.apple.com/en-ie/102301)

## Impostos · e-Fatura

Estudar a consulta de faturas comunicadas com o NIF da conta na qualidade de adquirente. A primeira versão compara os registos da AT com as despesas da Contiu e apresenta divergências para revisão. Confirmar os requisitos de acesso e os campos disponíveis para trabalhadores independentes.

**Exemplo.** No fecho do mês, a Contiu assinala uma fatura de 36,90 € que consta no e-Fatura e ainda não tem correspondência nas despesas. O utilizador revê se pertence à atividade.

**Primeira validação.** Confirmar acesso autorizado, identificação do documento e correspondência com faturas importadas por email, QR ou CSV.

[AT · Manual de integração do e-Fatura](https://info.portaldasfinancas.gov.pt/pt/apoio_ao_contribuinte/Outras_entidades/Suporte_tecnologico/Webservice/e_Fatura/Documents/Comunicacao_dos_elementos_dos_documentos_de_faturacao.pdf)

## Trabalhos · Clockify

Ligar os projetos do Clockify aos trabalhos da Contiu e importar os registos de tempo. Usar as horas faturáveis para preparar uma cobrança e o total de horas para comparar o esforço com a margem.

**Exemplo.** Importas 12 horas faturáveis de um trabalho a 50 €/hora. A Contiu sugere uma cobrança de 600 €, para rever antes de preparar a fatura.

**Primeira validação.** Confirmar o mapeamento de projetos, distinguir horas faturáveis das restantes e tratar correções aos registos sem duplicar horas ou cobranças.

[API do Clockify](https://docs.clockify.me/)

## Regras comuns

- Separar dados e credenciais por conta. Só os utilizadores autorizados gerem ligações.
- Preservar a origem e o identificador externo para permitir novas sincronizações sem duplicados.
- Manter os montantes em cêntimos inteiros e verificar os valores de origem antes de reconciliar.
- Mostrar a última sincronização, falhas e conflitos que precisam de revisão. Testar primeiro com dados simulados e depois com contas de teste.
