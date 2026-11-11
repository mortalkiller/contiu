# IVA regional na Contiu

**Objetivo.** Preparar faturas de serviços com IVA do Continente, dos Açores e da Madeira. A fatura guarda a região e a taxa que foram usadas. A validação e o cálculo final são feitos no servidor em cêntimos inteiros.

A primeira versão aplica a regra geral dos serviços domésticos. Para empresas, a sugestão usa a região do estabelecimento destinatário. Para particulares, usa a região da atividade a partir da qual o serviço é prestado. Os casos especiais exigem uma seleção explícita. O artigo 53.º e o tratamento de serviços a empresas da UE conservam os respetivos fluxos.

## Tarefas em paralelo

- [x] **Moradas, perfis e sugestão.** Criar tabelas para países, divisões administrativas portuguesas e moradas de clientes. A ficha mostra o seletor de país, endereço, código postal e localidade. Para Portugal, acrescenta Distrito/Ilha, Concelho e Freguesia em cascata. Para moradas estrangeiras, usa texto livre para a divisão administrativa e a freguesia. A localidade é o nome usado na morada postal. Preservar as moradas antigas. Sugerir a região a partir da divisão administrativa portuguesa, com a preferência explícita do estabelecimento como alternativa, e manter a região da atividade para particulares.
- [x] **Catálogo e definições da integração.** Ler região, código e percentagem em GET /taxes.json. Permitir que o titular associe impostos existentes por região e categoria. Preservar a configuração continental anterior e aplicar as permissões atuais.
- [x] **Faturas e rascunhos.** Passar região e categoria da interface até à pré-visualização, persistência, reabertura e validação da resposta do fornecedor. Verificar divergências com cobranças associadas. Invalidar a pré-visualização quando mudar a escolha.
- [x] **Histórico e notas de crédito.** Importar a região quando o fornecedor a identifica. Nas correções, reutilizar o imposto da linha original e preservar os dados históricos. Cobrir a ambiguidade dos 4% e notas relativas a faturas regionais.

Os 249 países e territórios, com os códigos ISO 3166-1 de duas letras, três letras e numérico, são provenientes do projeto [iso-codes](https://salsa.debian.org/iso-codes-team/iso-codes). A hierarquia portuguesa usa nomes e códigos da Direção-Geral do Território, [CAOP2025](https://www.dgterritorio.gov.pt/atividades/cartografia/cartografia-tematica/caop), sob [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). O ficheiro incluído na Contiu é um extrato de nomes, códigos, relações e regiões, sem geometrias.

A enumeração de regiões e taxas é comum às quatro tarefas. Os códigos da API são PT, PT-AC e PT-MA. Para esta versão, a tabela cobre as taxas atuais. Os documentos históricos conservam a percentagem e os dados devolvidos pelo fornecedor.

## Integração e validação

- [x] Executar os testes relevantes e a suite completa. Testar com respostas simuladas os três territórios, a colisão de 4%, taxas ausentes e imposto diferente na resposta. Validar a interface de sugestão e escolha.
- [ ] Na sandbox, verificar se é possível usar o mesmo artigo a 23% e 16% sem alterar o catálogo. Antes de permitir esse caminho, confirmar região, imposto, total e a identidade do artigo antes e depois. Até haver essa confirmação, selecionar um artigo já configurado com a taxa correspondente. Manter os controlos de emissão e recuperação existentes.
- [x] Integrar os ficheiros validados na main preservando as alterações locais em curso.

A validação de leitura da sandbox encontrou as taxas normais das três regiões e um único artigo configurado para o Continente. O ensaio de um rascunho regional real fica pendente de um artigo de teste com a taxa regional. O Chrome validou a sugestão para São Miguel, os campos livres para Espanha e a pré-visualização de uma fatura com 1 000 € de base e 160 € de IVA, com um catálogo simulado.
