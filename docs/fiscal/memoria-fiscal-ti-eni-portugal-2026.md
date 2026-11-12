<!--
Origem: relatório de pesquisa aprofundada feito no ChatGPT a pedido do Pedro, convertido para Markdown
sem alterar o texto. Esta é a segunda versão, recebida a 2026-11-11 (ficheiro original:
"memoria_fiscal_TI_ENI_Portugal_2026 (1).docx"). Substitui a primeira versão do mesmo dia: revê o estado
de várias regras, acrescenta as tabelas de IRS da Madeira de 2023 a 2026 e as taxas médias nacionais,
e o capítulo 12 com 44 casos de aceitação e 13 perguntas por esclarecer junto da AT, do ISS e do CC.

Como usar:
- É a referência das regras fiscais e contributivas (IRS categoria B, IVA, Segurança Social, faturação,
  calendário) para trabalhadores independentes e ENI. Consulta-a antes de mexer em cálculos de impostos,
  obrigações ou no registo de conta.
- Não a leias inteira: procura pelo identificador da regra (por exemplo SS040, IVA08, INI001) ou pelo tema.
- Cada regra traz o estado CONFIRMADO, DOUTRINA ou NÃO CONFIRMADO. Uma regra NÃO CONFIRMADO não deve
  virar cálculo automático sem validação de um contabilista certificado.
- Os casos de aceitação do capítulo 12 (IRS01–14, VAT01–08, SSC01–12, REG01–03, RAM01–04, ...) dão
  valores de fronteira já calculados: usa-os como dados de teste.
- O texto não foi verificado de forma independente contra o Diário da República. As ligações oficiais
  estão em cada regra. Data de corte do relatório: 2026-11-11.
-->

# Memória técnica fiscal e contributiva de trabalhadores independentes e ENI

### Objeto e data de referência

Regras para uma aplicação de gestão que determina enquadramentos, bases tributáveis, contribuições e obrigações declarativas de pessoas singulares com rendimentos da categoria B, em regime simplificado ou em contabilidade organizada. Data de verificação: **11 de novembro de 2026**. As tabelas históricas abrangem 2023 a 2026. As alterações posteriores à data de verificação não estão abrangidas.

O documento reúne o regime geral e identifica os regimes condicionais que exigem classificação adicional. Cada bloco relaciona a regra com a sua base legal e uma ligação oficial. A confirmação de uma regra não significa que qualquer perfil esteja suficientemente descrito para a aplicar: natureza efetiva da atividade, residência, estabelecimento, agregado familiar, datas, opções e notificações podem alterar o resultado.

**Revisão de validação.** Esta versão integra uma segunda verificação dirigida dos pontos em aberto, corrige as classificações de confiança anteriores, completa as tabelas gerais da Madeira e a lista base do dossier fiscal e acrescenta casos de aceitação e perguntas concretas de esclarecimento. O capítulo 12 distingue resultados calculáveis, orientações administrativas ou profissionais e combinações que ainda exigem decisão fundamentada.

**Limite de completude.** Esta memória não certifica um motor universal de liquidação de todos os impostos portugueses. Os pontos cuja redação histórica, interpretação, interação ou parametrização integral não ficou confirmada estão identificados expressamente. Entre eles encontram-se regimes setoriais, benefícios especiais, certas regras regionais históricas, convenções internacionais e módulos patrimoniais ou de processamento salarial. O núcleo fiscal e contributivo aqui documentado não deve ser utilizado para preencher automaticamente essas lacunas.

### Como ler o registo de regras

**CONFIRMADO** identifica uma norma verificada em fonte oficial, com o alcance indicado. **DOUTRINA** identifica orientações da AT ou do ISS; a orientação profissional da OCC é distinguida das interpretações administrativas. Uma informação vinculativa publicada demonstra o entendimento da AT no caso descrito, sem tornar todos os seus factos automaticamente extensíveis a outros contribuintes. **NÃO CONFIRMADO** identifica uma parte que permanece por validar; não equivale à afirmação de que determinada hipótese é falsa.

As consequências para a aplicação e os exemplos aritméticos são sínteses técnicas, não legislação nem doutrina oficial. Quando um bloco combina partes com estados diferentes, o estado acompanha a parte respetiva. A fonte primária prevalece sobre resumos informativos; divergências entre fontes oficiais são registadas, em vez de eliminadas por escolha silenciosa.

O texto apresenta o conteúdo operativo das normas por paráfrase, acompanhado de artigo, número e alínea quando aplicáveis. A redação literal deve ser consultada através da ligação oficial, selecionando a data relevante nas consolidações. A expressão «vigente no corte» confirma o texto consultado em 11-11-2026; quando não é indicada uma data inaugural da redação, essa data histórica permanece **NÃO CONFIRMADA** e o bloco não autoriza extrapolação retroativa.

### Datas que a aplicação tem de distinguir

Uma declaração entregue em 2026 pode respeitar a rendimentos de 2025. Uma contribuição de janeiro de 2026 pode usar rendimentos do último trimestre de 2025; em contabilidade organizada existe ainda o desfasamento entre ano do lucro, declaração fiscal, fixação e contribuição. A publicação de uma lei em 2026 também não implica que todos os seus artigos produzam efeitos nesse ano.

Por isso, cada regra deve ter data de publicação, entrada em vigor, produção de efeitos, período a que se aplica e norma transitória. Cada obrigação deve guardar o período declarado, o prazo legal, a prorrogação aplicável e a data concreta de vencimento. A atualização de uma consolidação não deve sobrescrever versões usadas em cálculos anteriores.

### Organização

1. Enquadramento e início reinício ou cessação de atividade.
1. IRS e determinação dos rendimentos da categoria B.
1. IVA nacional e operações internacionais.
1. Faturação e documentos.
1. Segurança Social.
1. Contabilidade organizada.
1. Obrigações periódicas calendário e infrações.
1. Parâmetros anuais de 2023 a 2026.
1. Árvore de decisão de enquadramento.
1. Alterações recentes e futuras.
1. Divergências e matérias a validar.
1. Validação adicional, casos de aceitação e perguntas de esclarecimento.

Siglas: AT, Autoridade Tributária e Aduaneira; ISS, Instituto da Segurança Social; TI, trabalhador independente; ENI, empresário em nome individual; CC, contabilista certificado; CO, contabilidade organizada; RR, rendimento relevante; BIC, base de incidência contributiva; DQ, declaração trimestral; DP, declaração periódica; CRC, Código dos Regimes Contributivos; RCRC, respetivo regulamento; CIRS, Código do IRS; CIVA, Código do IVA; RITI, Regime do IVA nas Transações Intracomunitárias; SNC, Sistema de Normalização Contabilística.

## 1 Enquadramento e início reinício ou cessação de atividade

### Campos de início e efeitos de cada escolha

#### ENQ01 Dados que determinam o enquadramento

**CONFIRMADO** para as bases materiais; **DOUTRINA** para o mapa de preenchimento. A declaração cadastral deve permitir identificar pessoa, residência/estabelecimento, início, atividade principal e secundárias, volume esperado, operações externas, natureza das operações IVA, opções fiscais, existência de contabilidade e CC. São dimensões independentes e devem manter valores próprios na aplicação.

| **Dado ou escolha** | **Efeito relevante** | **Base** |
| --- | --- | --- |
| Data de início ou reinício | Período fiscal, faturação e comunicação ao ISS; não basta data da primeira fatura | CIRS 112.º; CRC 143.º–146.º |
| CAE e códigos CIRS | Identificação cadastral; o coeficiente depende da atividade efetiva | CIRS 151.º e 31.º; Circular 2/2016 |
| Estimativa de rendimentos B | Enquadramento IRS no ano de início; opção ou obrigação de CO | CIRS 28.º/10 |
| Previsão de volume nacional no ano corrente | Elegibilidade do art. 53.º sem anualização no regime novo | CIVA 53.º/5 |
| Volume para periodicidade | Teste mensal/trimestral com anualização quando aplicável | CIVA 41.º/7–8 |
| Operações isentas e com dedução | Art. 9.º, art. 53.º, operações mistas e método de dedução | CIVA 9.º, 20.º, 23.º e 53.º |
| Clientes e fornecedores UE ou terceiros | Localização, VIES, autoliquidação e declarações específicas | CIVA 6.º e 29.º; RITI 25.º e 30.º |
| Opção pelo normal IVA | Vínculo próprio de cinco anos | CIVA 55.º |
| Opção mensal IVA | Declarações mensais; regime alterado em julho de 2025 | CIVA 41.º/2–4 |
| Contabilidade existente e identificação do CC | Obrigações contabilísticas e competência técnica; não substitui a opção fiscal | CIRS 117.º e 28.º |
| Opção fiscal CO | Determinação pelo lucro, dentro do prazo legal | CIRS 28.º/4–5 |
| IBAN e local da contabilidade | Dados operacionais de pagamentos/reembolsos e acesso à documentação | Modelo cadastral e manual AT |

O manual oficial de início disponível está atualizado a setembro de 2021. Documenta, nomeadamente, quadro 08 para atividades, quadro 09 para data/atividade esperada, quadro 11 para tipo de operações, quadros 13/14 para opções IVA, quadro 15 para IBAN, quadro 16 para contabilidade/CC, quadro 19 para opção fiscal e quadro 26 para serviços intracomunitários. **NÃO CONFIRMADO:** que a numeração e a apresentação de todos esses campos coincidam integralmente com a interface em 2026. O manual contém condições entretanto alteradas; não importar as exclusões de IVA, a permanência mensal de três anos ou a CAE Rev.3 como regras atuais.

**Vigência:** normas substantivas nas versões e datas dos capítulos IRS/IVA/SS; mapa de formulário com origem documental em 2021, validável por versão. Fonte: [manual AT de início](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/Manuais/Documents/Manual_Inicio_PF.pdf); artigos oficiais ligados nos blocos B007–B010, REG001–REG003 e IVA07–IVA26. A aplicação deve guardar o comprovativo do enquadramento atribuído, incluindo a data de efeitos, e apresentar conflitos de dados para correção.

#### ENQ02 TI ENI e responsabilidade patrimonial

**CONFIRMADO** quanto ao princípio legal. TI e ENI comuns são pessoas singulares; o nome comercial e a opção de contabilidade não criam por si uma pessoa coletiva nem uma separação geral do património. Pelo cumprimento das obrigações respondem os bens penhoráveis do devedor, sem prejuízo dos regimes especiais de separação de patrimónios. A composição do património responsável, incluindo bens comuns do casal e limites de penhora, exige as regras civis/processuais concretas; não se deve apresentar o ENI como tendo automaticamente responsabilidade limitada.

**Base:** Código Civil, artigo 601.º, aprovado pelo DL 47344/1966; CIRS 3.º/28.º para a tributação pessoal. **Vigência:** texto consolidado confirmado em 2026; regime patrimonial concreto não parametrizado nesta memória. Fonte: [Código Civil artigo 601.º](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/1966-34509075-49824075). EIRL e sociedades são formas jurídicas distintas, embora o CRC agrupe ENI/EIRL para determinadas regras contributivas.

### Incidência e classificação fiscal

#### B001 Rendimentos empresariais e profissionais

B compreende atividades comerciais, industriais, agrícolas, silvícolas e pecuárias; serviços por conta própria, incluindo científicos, artísticos ou técnicos; propriedade intelectual/industrial e informação de experiência quando recebidas pelo titular originário.

**Base e fonte:** CIRS 3.º, n.º 1, a)–c), [C 3](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx)

**Vigência e estado:** Texto atual confirmado em 11-11-2026. **CONFIRMADO**.

#### B002 Outros rendimentos e atos isolados

Incluem-se ainda rendimentos de capitais/prediais afetos à atividade, mais-valias empresariais, certas indemnizações, cessão temporária de exploração, subsídios e atos isolados. Um ato só é isolado quando não resulta de prática previsível ou reiterada.

**Base e fonte:** CIRS 3.º, n.º 2 e n.º 3, [C 3](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**. A qualificação exige factos; não existe um número universal de faturas que resolva a questão.

#### B003 Exclusão de pequenos rendimentos agrícolas

A exclusão agrícola/silvícola/pecuária depende de receitas dessas atividades, somadas aos restantes rendimentos ilíquidos sujeitos, ainda que isentos, do agregado, não excederem 4,5 vezes o valor anual do IAS.

**Base e fonte:** CIRS 3.º, n.º 4, [C 3](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**. Não aplicar a outros setores.

#### B004 Momento de imputação do rendimento

A imputação temporal B ocorre quando a fatura se torna obrigatória para IVA; não havendo essa obrigação, no pagamento/disponibilização. Em CO aplica-se a periodização do artigo 18.º CIRC. No simplificado, o rendimento de operações com criptoativos referido no art. 31.º/1/a e d segue a alienação onerosa, sendo equiparadas a alienação a cessação de atividade e a perda da qualidade de residente.

**Base e fonte:** CIRS 3.º, n.º 6; 31.º, n.os 17–18; CIRC 18.º, n.os 1 e 3, [C 3](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx), [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx), [IRC 18](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc18.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**; regra cripto desde 01-01-2023, Lei 24-D/2022. O simplificado não é universalmente um regime de caixa.

#### B005 Atividades comerciais e industriais

A lista comercial/industrial inclui comércio, fabricação, transportes, construção, hotelaria, restauração, agências e outras atividades. A emissão de criptoativos, mineração e validação de transações integra expressamente o elenco desde 2023.

**Base e fonte:** CIRS 4.º, n.º 1, em especial o); Lei 24-D/2022, [C 4](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs4.aspx), [OE 23](https://diariodarepublica.pt/dr/detalhe/lei/24-d-2022-205557192)

**Vigência e estado:** Cripto: 01-01-2023. Restante texto atual. **CONFIRMADO**.

#### B006 Arrendamentos e alojamento local

Arrendamentos podem ser objeto de opção por B, exercida em declaração de início ou de alterações; não confundir com a opção anual do AL por regras F.

**Base e fonte:** CIRS 4.º, n.º 1, n), e n.º 5; 28.º, n.º 14, [C 4](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs4.aspx), [C 28](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**.

#### B007 Códigos de atividade

A classificação faz-se por CAE ou pelos códigos da tabela aprovada pela Portaria 1011/2001. Exemplos: 1313 analistas de sistemas, 1320 consultores, 1332 programadores informáticos, 1336 designers, 1519 outros prestadores de serviços.

**Base e fonte:** CIRS 151.º; Portaria 1011/2001, anexo, na redação da Portaria 23/2022, [C 151](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs151.aspx), [P 1011](https://diariodarepublica.pt/dr/legislacao-consolidada/portaria/2001-177307831), [P 23](https://diariodarepublica.pt/dr/detalhe/portaria/23-2022-177309296)

**Vigência e estado:** Tabela alterada com efeitos em 01-01-2022. **CONFIRMADO**.

#### B008 Classificação material do serviço

O coeficiente depende da atividade efetivamente exercida. A inscrição por CAE não evita 0,75 quando a profissão está especificamente prevista; a classificação residual 1519 não transforma todos os serviços em profissão especificamente listada.

**Base e fonte:** Circular AT 2/2016, pontos 4 e 5, [CIRC 2](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Circular_2_2016.pdf)

**Vigência e estado:** Doutrina de 06-05-2016, relevante para as versões atuais. **DOUTRINA**.

#### B009 CAE Rev 4

A CAE Rev.4 substitui a revisão anterior desde 01-01-2025. O motor deve versionar códigos e equivalências, permitindo confirmação da conversão pelo contribuinte.

**Base e fonte:** DL 9/2025, arts. 1.º–4.º; aviso AT sobre validação CAE, [CAE 4](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Decreto_Lei_9_2025.pdf), [CAEAV](https://info.portaldasfinancas.gov.pt/pt/destaques/Paginas/Validacao_CAE.aspx)

**Vigência e estado:** Efeitos desde 01-01-2025. **CONFIRMADO** para revisão; **DOUTRINA** para procedimentos do aviso.

#### B010 Início alterações e cessação

A declaração de início precede a atividade; alterações, em regra, em 15 dias; cessação em 30 dias. O facto que materializa a cessação tem de ser apurado segundo o artigo 114.º, não apenas pela ausência de faturação.

**Base e fonte:** CIRS 112.º, n.os 1,2 e 3; 114.º, n.os 1–4, [C 112](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs112.aspx), [C 114](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs114.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**.

A atividade comercial de um ENI não constitui automaticamente uma sociedade. Para o motor de IRS, o titular e o seu agregado continuam essenciais. Uma interface deve perguntar o tipo de rendimento e a realidade da atividade antes de selecionar automaticamente coeficiente, retenção ou elegibilidade para mínimo de existência. O nome comercial e a designação «recibos verdes» não são suficientes.

### Escolha do regime e mudanças

#### REG001 Âmbito do regime simplificado

O simplificado abrange quem, não optando por CO, tenha rendimentos anuais ilíquidos B do ano anterior até 200.000 €. No início usa-se a estimativa anual declarada.

**Base e fonte:** CIRS 28.º, n.os 1–3 e 10, [C 28](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**.

#### REG002 Opção por contabilidade organizada

Opção por CO na declaração de início ou até 31 de março do ano em que produz efeitos. Mantém-se até declaração de alterações pela opção contrária, apresentada no prazo legal.

**Base e fonte:** CIRS 28.º, n.os 4 e 5, [C 28](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**. Não reintroduzir automaticamente antigas permanências de três anos.

#### REG003 Passagem obrigatória

Saída obrigatória: ultrapassar 200.000 € durante dois períodos consecutivos ou, num só período, ultrapassá-lo em mais de 25%; CO no ano seguinte.

**Base e fonte:** CIRS 28.º, n.º 6, [C 28](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**. «Mais de 25%» corresponde a **mais de 250.000 €**; igualdade não basta para esta segunda condição.

#### REG004 Serviços a uma única entidade

Serviços a uma única entidade podem beneficiar de opção anual pelas regras A, salvo sócios de entidades em transparência fiscal abrangidos pela exclusão legal.

**Base e fonte:** CIRS 28.º, n.º 8, [C 28](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**. Não converter todo o titular para trabalhador dependente nem presumir regras contributivas A.

#### REG005 Opção de alojamento local

AL na modalidade moradia/apartamento permite opção anual pelas regras F. Deve ser comparada com B, incluindo perdas, despesas e taxas próprias da alternativa.

**Base e fonte:** CIRS 28.º, n.º 14, [C 28](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx)

**Vigência e estado:** Desde redação da Lei 42/2016; atual. **CONFIRMADO**. Algoritmo completo F fora da presente cobertura.

#### REG006 Contabilidade e opção fiscal

Ter um contabilista ou manter contabilidade voluntária não equivale, só por si, a opção fiscal por CO. A situação cadastral e os requisitos legais são determinantes.

**Base e fonte:** CIRS 28.º; esclarecimento OCC «IRS — regime simplificado», [C 28](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx), [OCCREG](https://portal.occ.pt/pt-pt/noticias/irs-regime-simplificado-4)

**Vigência e estado:** Texto OCC consultado em 2026. **DOUTRINA**.

#### REG007 Ajustamentos de transição

Mudanças de regime exigem ajustamentos para impedir dupla tributação ou ausência de tributação; é necessário conservar valores de inventários, ativos, subsídios e rendimentos já considerados.

**Base e fonte:** CIRS 3.º, n.º 8; 31.º, n.os 6 e 8, [C 3](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx), [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx)

**Vigência e estado:** Atual. **CONFIRMADO**. A reconciliação deve ser individual, não uma simples mudança de coeficiente.

## 2 IRS e rendimentos da categoria B

### Regime simplificado

#### Tabela material dos coeficientes

Todos os coeficientes seguintes constam do artigo 31.º, n.º 1, na versão atual [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx). A redação cripto provém da Lei 24-D/2022 [OE 23](https://diariodarepublica.pt/dr/detalhe/lei/24-d-2022-205557192); o coeficiente de AL em área de contenção consta da redação introduzida pela Lei 2/2020. **Estado: CONFIRMADO** na data de corte. A aplicação a cada receita deve ser feita antes da soma dos resultados.

| **ID** | **Alínea** | **Coeficiente** | **Receitas e condicionantes** |
| --- | --- | --- | --- |
| SIM001 | a) | 0,15 | Vendas de mercadorias e produtos; operações com criptoativos exceto mineração; restauração, bebidas e hotelaria/similares, com exclusão do AL em moradia/apartamento. |
| SIM002 | b) | 0,75 | Atividades profissionais especificamente previstas na tabela do artigo 151.º. |
| SIM003 | c) | 0,35 | Serviços não abrangidos pelas alíneas anteriores; inclui, em regra, AL moradia/apartamento fora de área de contenção, sujeito a verificação do enquadramento concreto. |
| SIM004 | d) | 0,95 | Mineração de criptoativos; cessão/uso temporário de propriedade intelectual ou industrial e know-how; capitais B; resultado positivo predial; saldo positivo de mais/menos-valias e outros incrementos. |
| SIM005 | e) | 0,30 | Subsídios ou subvenções não destinados à exploração. |
| SIM006 | f) | 0,10 | Subsídios à exploração e outros rendimentos B não abrangidos nas anteriores. |
| SIM007 | g) | 1,00 | Serviços a sociedade em transparência fiscal de que o prestador é sócio; ou a sociedade em que, durante mais de 183 dias, detenha pelo menos 5% sozinho, direta/indiretamente, ou pelo menos 25% com cônjuge/unido de facto e ascendentes/descendentes indicados na lei. |
| SIM008 | h) | 0,50 | AL moradia/apartamento localizado em área de contenção. |

#### SIM009 Subsídios de investimento

O rendimento da alínea e), após coeficiente, é distribuído por cinco frações iguais, começando no ano do recebimento. Ao abandonar o simplificado, imputam-se as frações ainda não tributadas ao último ano desse regime. CIRS 31.º, n.os 5 e 8 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx). Vigência atual, **CONFIRMADO**. Exemplo de desenho: armazenar cada subsídio com calendário próprio, evitando voltar a repartir a receita em cada exercício.

#### SIM010 Capitais prediais e mais valias empresariais

A inclusão na categoria B não elimina retenções legalmente devidas. O resultado predial referido na alínea d) calcula-se com as deduções do artigo 41.º; não se aplica 0,95 diretamente a toda a renda sem apurar o resultado positivo relevante. CIRS 31.º, n.os 3 e 4, e 101.º, n.os 9 e 10 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx), [C 101](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101.aspx). Atual, **CONFIRMADO**. O algoritmo predial completo e os casos de desafetação de imóveis exigem módulo adicional.

#### Início e reinício

#### INI001 Redução inicial

O artigo 31.º, n.º 10 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx), reduz em 50% no ano de início e em 25% no ano seguinte os coeficientes b), c) e f), desde que não existam rendimentos A ou H no respetivo ano. Coeficientes resultantes: 0,375/0,5625; 0,175/0,2625; 0,05/0,075. Não abrange vendas, mineração, subsídios de investimento, rendimentos sujeitos a 1 ou AL da alínea h). **CONFIRMADO** atual.

#### INI002 Reinício

O n.º 11 impede a redução quando tenha ocorrido cessação há menos de cinco anos. Deve guardar-se a data efetiva da cessação e a do reinício, não apenas um indicador «primeira fatura». **CONFIRMADO**, CIRS 31.º, n.º 11 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx). A aplicação a início em dezembro consome o ano civil de início; não são doze meses móveis. Esta última tradução operacional é inferência da referência ao período de tributação, devendo o programa explicá-la.

#### INI003 Orientação operacional histórica

O manual AT de liquidação 2021 explicita verificações de ausência de A/H, da cessação anterior, do Anexo C e da irrelevância de atos isolados anteriores para certos testes. Confirma também a aplicação da regra de despesas de 15% nos anos com coeficiente reduzido. Fonte: [MAN 21](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/Manuais/Documents/Regras_Liquidacao_IRS_2021_Residentes.pdf), secção B. **DOUTRINA histórica**; não constitui manual atualizado de todas as interações 2026.

#### Contribuições obrigatórias e comprovação de 15 por cento

#### DES001 Dedução contributiva

Para rendimentos b)/c), as contribuições obrigatórias para regimes de proteção social conexas com a atividade podem ser abatidas na parte que exceda 10% do rendimento bruto, até ao rendimento líquido e sem duplicação com outra dedução. CIRS 31.º, n.º 2 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx); Circular 2/2016, ponto 7 [CIRC 2](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Circular_2_2016.pdf). **CONFIRMADO** para lei; interpretação operacional **DOUTRINA**. O montante que excede 10% não é o total das contribuições.

#### DES002 Acréscimo por despesas insuficientes

O n.º 13 do artigo 31.º exige comparar 15% dos rendimentos brutos b)+c) com a soma das despesas e encargos elegíveis. A diferença positiva acresce ao rendimento tributável. Não é uma dedução geral de custos reais a somar à despesa já presumida no coeficiente. CIRS 31.º, n.º 13 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx); manual AT [MAN 21](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/Manuais/Documents/Regras_Liquidacao_IRS_2021_Residentes.pdf). **CONFIRMADO** atual. Deve existir uma linha explícita «acréscimo por despesas não comprovadas».

#### DES003 Parcela automática

Na soma elegível entra o valor do artigo 25.º, n.º 1, a), ou, se superior, o montante de contribuições obrigatórias que não tenha sido deduzido nos termos do n.º 2. A referência a 8,54 IAS vigora desde 2025 por Lei 45-A/2024, artigo 89.º; em 2024 aplica-se a atualização de 4.104 € em 6% da Lei 32/2024, artigo 2.º. Fontes: [C 25](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs25.aspx), [C 25-24](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs25ra_202412.aspx), [L 32](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Lei_32_2024.pdf), [OE 25](https://diariodarepublica.pt/dr/detalhe/lei/45-a-2024-901667918). **CONFIRMADO**; valores históricos no ponto 10.

#### DES004 Outras parcelas

São elegíveis remunerações e encargos com pessoal comunicados; rendas de imóveis afetos; imputação de 1,5% do VPT dos imóveis de atividade ou 4% em hotelaria/AL, nas condições de propriedade, usufruto ou superfície; aquisições de bens/serviços relacionados com a atividade com documentação/comunicação exigida; importações e aquisições intracomunitárias. CIRS 31.º, n.º 13, b)–f) [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx). **CONFIRMADO** atual. A recolha deve distinguir montante, afetação, suporte, comunicação e eventual utilização noutra dedução.

#### DES005 Uso misto

As despesas das alíneas c), d) e e) apenas parcialmente afetas contam por 25%. Isto não é liberdade para escolher uma percentagem pessoal arbitrária nem se aplica automaticamente a todas as parcelas do artigo. CIRS 31.º, n.º 14 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx). **CONFIRMADO** atual.

#### DES006 Identificação e correção

Identificação no Portal das Finanças até ao fim de fevereiro do ano seguinte, na redação desde 01-07-2025; as importações/aquisições UE exclusivamente profissionais seguem identificação declarativa própria. A declaração pode substituir os valores comunicados nos termos do n.º 16, com comprovação. CIRS 31.º, n.os 15 e 16, DL 49/2025 [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx). **CONFIRMADO**. A anterior data de 25 de fevereiro não deve ser mantida para o prazo novo.

#### DES007 Profissões de desgaste rápido

O art. 31.º, n.º 12, permite deduzir, após os coeficientes, as importâncias do art. 27.º, até ao rendimento líquido e sem duplicação. Abrange praticantes desportivos nos termos regulamentares, mineiros e pescadores. O art. 27.º, n.os 1–4, admite seguros de doença/acidentes pessoais e seguros de vida com os riscos exclusivos legalmente previstos, e contribuições mutualistas adaptadas, até 5 IAS. Em 2026, a Lei 73-A/2025 acrescentou lesão desportiva e complemento de reforma; reforma/complemento têm benefício após 55 anos, e não pode existir garantia ou pagamento de capital em vida nos primeiros cinco anos, incluindo resgate/adiantamento. Se este pagamento ocorrer, segue-se o art. 60.º/2. **CONFIRMADO** quanto às condições do CIRS; a qualificação regulamentar específica de cada praticante desportivo permanece **NÃO CONFIRMADA** para classificação automática. Fontes: [C 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx), [CIRS 27.º atual](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs27.aspx) e [redação até 2025](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs27ra_202512.aspx). Limites anuais de 5 IAS: 2.402,15 € em 2023, 2.546,30 € em 2024, 2.612,50 € em 2025 e 2.685,65 € em 2026; valores aritméticos.

Uma implementação básica, sem benefícios especiais, pode representar: soma de receitas multiplicadas pelos coeficientes aplicáveis; menos dedução contributiva permitida; mais insuficiência de despesas; mais/menos ajustamentos legais específicos. Esta sequência conceptual não resolve automaticamente IRS Jovem, deficiência, rendimentos de fontes distintas ou rateios. A utilização de despesas familiares como profissionais deve ser consistente com as exclusões das deduções à coleta previstas, nomeadamente, no artigo 78.º-B, n.º 4 [C 78 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs78b.aspx).

### Contabilidade organizada gastos ativos e perdas

#### CO001 Remissão para o CIRC

Determinação do lucro segundo CIRC, com adaptações CIRS, excluindo expressamente os artigos CIRC 51.º, 51.º-A, 51.º-B, 51.º-C e 54.º-A.

**Base e fonte:** CIRS 32.º, [C 32](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs32.aspx)

**Vigência e estado:** **CONFIRMADO**, atual.

#### CO002 Resultado e periodização

Ponto de partida: resultado líquido contabilístico mais variações patrimoniais relevantes e correções fiscais. Rendimentos/gastos imputados ao período a que respeitam, independentemente de recebimento/pagamento.

**Base e fonte:** CIRC 17.º, n.os 1 e 3; 18.º, n.os 1 e 3, [IRC 17](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc17.aspx), [IRC 18](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc18.aspx)

**Vigência e estado:** **CONFIRMADO**, atual.

#### CO003 Gastos e documentação

Gastos necessários à obtenção/garantia de rendimentos sujeitos, documentalmente comprovados. Documento identifica intervenientes, NIF quando exigido, bens/serviços, valor e data; se houver obrigação IVA, exige-se fatura.

**Base e fonte:** CIRC 23.º, n.os 1–4 e 6, [IRC 23](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc23.aspx)

**Vigência e estado:** **CONFIRMADO**; n.º 6 na redação DL 49/2025 desde 01-07-2025.

#### CO004 Remuneração do titular

Remuneração, ajudas de custo, uso de viatura própria e subsídio de refeição do próprio titular B não são gastos fiscais dedutíveis.

**Base e fonte:** CIRS 33.º, n.º 1, [C 33](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs33.aspx)

**Vigência e estado:** **CONFIRMADO**, atual. Não tratar o titular como seu empregado.

#### CO005 Habitação e despesas comuns

Habitação parcialmente afeta: encargos indicados na lei limitados a 25% do total comprovado. Despesas comuns a profissionais são repartidas pelo uso ou proporção dos rendimentos brutos, conforme a norma.

**Base e fonte:** CIRS 33.º, n.os 5 e 6, [C 33](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs33.aspx)

**Vigência e estado:** **CONFIRMADO**, atual.

#### CO006 Gastos não dedutíveis

Não dedutíveis, entre outros: imposto sobre lucros e TA; gastos não documentados; documentação inválida; despesas ilícitas; multas/coimas e certos juros por infrações; impostos de terceiros.

**Base e fonte:** CIRC 23.º-A, n.º 1, a)–f), [IRC 23 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc23A.aspx), adaptado por CIRS 32

**Vigência e estado:** **CONFIRMADO**, atual. Identificar natureza contratual ou sancionatória dos juros.

#### CO007 Empregados combustíveis e alugueres

Ajudas de custo/quilómetros de empregados exigem mapas e condições legais; combustíveis exigem afetação do veículo e consumo normal; alugueres de viaturas têm limitação da componente equivalente à depreciação não admitida.

**Base e fonte:** CIRC 23.º-A, n.º 1, h), i), j), [IRC 23 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc23A.aspx)

**Vigência e estado:** **CONFIRMADO**, atual. Não confundir com remunerações do titular CO004.

#### CO008 Depreciações

Depreciações de ativos sujeitos a deperecimento, em regra desde entrada em funcionamento. Taxas e métodos seguem CIRC e decreto regulamentar aplicável; elementos até 1.000 € podem ser integralmente deduzidos, salvo conjunto funcional.

**Base e fonte:** CIRC 29.º, 31.º e 33.º, [IRC 29](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc29.aspx), [IRC 31](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc31.aspx), [IRC 33](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc33.aspx)

**Vigência e estado:** **CONFIRMADO** nos princípios. Catálogo integral do DR 25/2009 **NÃO CONFIRMADO** nesta memória.

#### CO009 Mais valias empresariais

Mais-valias: valor de realização líquido de encargos menos valor fiscal do ativo, considerando depreciações e demais ajustamentos; desafetações podem usar valor de mercado.

**Base e fonte:** CIRC 46.º, n.os 1–3, [IRC 46](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc46.aspx)

**Vigência e estado:** **CONFIRMADO**, atual. Imóveis B têm regras CIRS específicas adicionais.

#### CO010 Reinvestimento

Reinvestimento elegível pode limitar a 50% o saldo positivo de mais/menos-valias, com detenção mínima, bens elegíveis e reinvestimento no ano anterior, próprio ou até ao fim do segundo seguinte. Reinvestimento parcial é proporcional; incumprimento repõe a parcela com majoração de 15%.

**Base e fonte:** CIRC 48.º, n.os 1,2,5,6,9 e 10, [IRC 48](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc48.aspx)

**Vigência e estado:** **CONFIRMADO** regime geral; suspensões transitórias de prazos e casos excecionais **NÃO CONFIRMADOS**.

#### CO011 Reporte de perdas

Perdas B são reportáveis por 12 anos, do mesmo titular e categoria, com remissão para CIRC 52 na parte aplicável. Podem acompanhar a passagem de CO para simplificado. Não deduzir prejuízo B ao salário do cônjuge.

**Base e fonte:** CIRS 55.º, n.º 1, a), n.os 4 e 7, [C 55](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs55.aspx)

**Vigência e estado:** **CONFIRMADO**, atual.

#### CO012 Limite de dedução de perdas

A remissão do artigo 55.º torna relevante o teto de dedução de perdas de 65% do lucro; o prazo ilimitado do CIRC não substitui os 12 anos expressos do CIRS.

**Base e fonte:** CIRC 52.º, n.os 1 e 2; CIRS 55.º, [IRC 52](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc52.aspx), [C 55](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs55.aspx)

**Vigência e estado:** Teto 65% desde 2023; **CONFIRMADO** regime geral. Majorações e suspensões ligadas a perdas 2020/21: **NÃO CONFIRMADO**.

#### Viaturas e tributações autónomas de IRS

#### VIA001 Limite depreciável

Para aquisições desde 01-01-2015: 62.500 € elétricos; 50.000 € híbridos plug-in; 37.500 € GPL/GNV; 25.000 € restantes viaturas abrangidas. Fonte: Portaria 467/2010, artigo 1.º, n.º 4, na redação da Lei 82-D/2014, artigo 24.º [P 467](https://diariodarepublica.pt/dr/legislacao-consolidada/portaria/2010-894002398); CIRC 34.º, n.º 1, e) [IRC 34](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc34.aspx). **CONFIRMADO**. São tetos de custo fiscal depreciável, não limites das taxas autónomas.

#### VIA002 Quantidade admissível em IRS

A Portaria 1041/2001, n.º 3 [P 1041](https://diariodarepublica.pt/dr/detalhe/portaria/1041-2001-517217), limita os encargos fiscalmente aceites a uma viatura por titular B e por trabalhador ao serviço, quando indispensável ao exercício da atividade, com exceção indicada para cilindrada inferior a 125 cm³. N.os 1 e 2 articulam a limitação com amortizações/aluguers. Vigência desde 2001 conforme n.º 4; **CONFIRMADO** texto e **DOUTRINA** de confirmação OCC [OCCTA](https://occ.pt/pt-pt/noticias/irs-tributacao-autonoma-0).

| **ID** | **Situação** | **Taxa IRS e condições** | **Base/estado** |
| --- | --- | --- | --- |
| TA001 | Despesas não documentadas em CO | 50%, sem prejuízo da não dedutibilidade. | CIRS 73.º, n.º 1, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx). **CONFIRMADO** atual. |
| TA002 | Representação | 10% sobre encargos dedutíveis; definição no n.º 4. | CIRS 73.º, n.os 2 e 4, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx). **CONFIRMADO** atual. |
| TA003 | Viaturas de passageiros/mistas | 10% se custo <30.000 €; 20% se ≥30.000 €; elétricos excluídos. Motos/motociclos 10%. | CIRS 73.º, n.º 2, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx); desde 2025 Lei 45-A/2024. **CONFIRMADO**. |
| TA004 | Híbridos plug-in | 5% e 10%, nos respetivos patamares. | CIRS 73.º, n.º 10, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx). **CONFIRMADO** atual. |
| TA005 | GPL/GNV | 7,5% e 15%, nos respetivos patamares. | CIRS 73.º, n.º 11, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx). **CONFIRMADO** atual. |
| TA006 | Ajudas de custo e quilómetros de empregados | 5% nas condições do n.º 7, incluindo a distinção entre faturação ao cliente e sujeição a IRS no beneficiário. | CIRS 73.º, n.º 7, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx). **CONFIRMADO** atual; remissão textual antiga exige correspondência ao CIRC atual. |
| TA007 | Pagamentos a regimes fiscais claramente mais favoráveis | 35%, salvo prova de operações efetivas sem caráter anormal/exagerado. | CIRS 73.º, n.º 6; 33.º, n.º 9, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx), [C 33](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs33.aspx). **CONFIRMADO** atual. |
| TA008 | Simplificado | Não aplica as TA dos n.os 2,7,10,11; a dos n.º 1 é expressamente de CO e o n.º 6 remete para os sujeitos do n.º 1. | CIRS 73.º, n.os 1,6,8, [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx). **CONFIRMADO** atual. |

Em 2023–2024, o limiar das viaturas para TA de IRS era **20.000 €**, mantendo os dois patamares de taxas indicados [C 73-24](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs73ra_202412.aspx). A partir de 2025 passou a 30.000 €. A base do artigo 73.º é a dos encargos **dedutíveis**, não uma cópia da base de IRC. Depreciações, rendas, seguros, manutenção, combustível e impostos podem integrar essa base, conforme n.º 5. As exclusões do n.º 3 devem ser aplicadas especificamente: afetação a serviço público de transportes/aluguer e caso da depreciação de viaturas com acordo de utilização tributado em A. Não copiar requisitos de autonomia/CO₂ de híbridos, tabelas de IRC ou agravamento automático por prejuízo do artigo 88.º CIRC para IRS sem norma própria. Fontes: [C 73](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs73.aspx), [OCCTA](https://occ.pt/pt-pt/noticias/irs-tributacao-autonoma-0).

### Retenção dispensa e pagamentos por conta

#### Retenção

#### RET001 Entidade obrigada a reter

Obrigação do pagador que disponha ou deva dispor de CO. Retenção na colocação à disposição/pagamento, sobre o bruto antes de IVA.

**Base e fonte:** CIRS 101.º, n.os 1,4,8, [C 101](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101.aspx)

**Vigência e estado:** **CONFIRMADO**, atual. Não impor retenção a todo cliente particular.

#### RET002 Taxas por natureza do rendimento

Profissões especificamente listadas 151: 23%; PI/know-how titular originário: 16,5%; outros serviços e certos subsídios/atos isolados de serviços: 11,5%.

**Base e fonte:** CIRS 101.º, n.º 1, a), b), c), [C 101](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101.aspx)

**Vigência e estado:** 23% desde 01-01-2025; demais atuais. **CONFIRMADO**.

#### RET003 Atividades com incentivo especial

Taxa 20% nas atividades IFICI qualificadas; RNH transitório exige a redação anterior salvaguardada. Não aplicar 20% genericamente a serviços estrangeiros ou tecnologia.

**Base e fonte:** CIRS 101.º, n.º 1, d); Lei 82/2023, art. 236.º, [C 101](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101.aspx), [C 101-23](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs101ra_202312.aspx), [C 12 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12a.aspx)

**Vigência e estado:** IFICI desde 2024; RNH transitório. **CONFIRMADO**, elegibilidade individual.

#### RET004 Dispensa por rendimento anual

Dispensa opcional se previsão anual da categoria inferior ao limite remetido para IVA 53, e sem rendimentos no ano anterior iguais/superiores ao limite. Comissões de intermediação não beneficiam desta dispensa.

**Base e fonte:** CIRS 101.º-B, n.º 1, a), n.º 3, a), [C 101 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101b.aspx)

**Vigência e estado:** **CONFIRMADO**. Limites: 13.500/14.500/15.000/15.000 € em 2023/24/25/26.

#### RET005 Cessação da dispensa

A dispensa termina no mês seguinte àquele em que o limite é atingido; a igualdade já conta para atingir. Recibo deve conter menção legal quando se usa a dispensa.

**Base e fonte:** CIRS 101.º-B, n.os 2 e 3, b), [C 101 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101b.aspx)

**Vigência e estado:** **CONFIRMADO**, atual. Não retroagir a todas as faturas do ano.

#### RET006 Reembolsos e outras dispensas

Dispensados reembolsos legalmente documentados de despesas em nome/por conta do cliente e certas deslocações imputáveis a cliente; também rendimentos enumerados nas alíneas legais de 101 B1 c.

**Base e fonte:** CIRS 101.º-B, n.º 1, b), c), [C 101 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101b.aspx)

**Vigência e estado:** **CONFIRMADO**, atual. Separar reembolso verdadeiro de preço do serviço.

#### RET007 Retenção individual inferior a 25 euros

Desde 01-07-2025, dispensa quando cada retenção B/E/F seja inferior 25 €. Antes, alínea d) apenas E e 5 €.

**Base e fonte:** CIRS 101.º-B, n.º 1, d), DL 49/2025, [C 101 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101b.aspx), [C 101 B-0625](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs101bra_202506.aspx)

**Vigência e estado:** **CONFIRMADO**. Igual 25 € não satisfaz «inferior».

#### RET008 Redução da base de retenção

Certas atividades laboratoriais previstas na norma, propriedade intelectual EBF 58 e deficiência≥60% podem ter retenção sobre 50% da base; PI e deficiência cumuladas, sobre 25%; opção/menção exigidas.

**Base e fonte:** CIRS 101.º-D, n.os 1–3, [C 101 D](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101d.aspx)

**Vigência e estado:** **CONFIRMADO**, atual. Não confundir base reduzida com taxa reduzida.

#### RET009 Entrega e natureza da retenção

Retenção é pagamento por conta; entrega pelo pagador até 20 do mês seguinte; possibilidade de taxa inteira superior a pedido, nos termos legais.

**Base e fonte:** CIRS 98.º, n.os 1,3,6, [C 98](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs98.aspx)

**Vigência e estado:** **CONFIRMADO**, atual.

O limite monetário comum à dispensa de retenção IRS e ao artigo 53.º IVA não unifica os regimes: o teste IRS refere-se à categoria e à previsão/ano anterior; a isenção IVA tem condições próprias de volume de negócios, estabelecimento e operações. A regra de transição 13.500/14.500 foi fixada no artigo 282.º, n.º 3, da Lei 24-D/2022 [OE 23](https://diariodarepublica.pt/dr/detalhe/lei/24-d-2022-205557192); o atual artigo 53.º CIVA usa 15.000 € [IVA 53](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/artigo-53-o-do-civa.aspx).

#### Pagamentos por conta

#### PPC001 Calendário

Três pagamentos até 20 julho, 20 setembro, 20 dezembro. CIRS 102.º, n.º 1 [C 102](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs102.aspx). **CONFIRMADO**, atual. Despachos excecionais anuais podem alterar prazos de cumprimento; não foram inventariados integralmente aqui.

#### PPC002 Fórmula

Desde 2025, total = **0,65 × (C × (RLB/RLT) − R)**. C é coleta do penúltimo ano após deduções 78.º, n.º 1, exceto a dedução por deficiência da alínea i); R são retenções B do penúltimo ano; RLB rendimento líquido positivo B; RLT rendimento líquido total. Cada prestação é um terço arredondado por excesso para euros; prestação inferior 50 € não é exigível. CIRS 102.º, n.os 2–3; Lei 45-A/2024, art. 89.º [C 102](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs102.aspx), [OE 25](https://diariodarepublica.pt/dr/detalhe/lei/45-a-2024-901667918). **CONFIRMADO**. Fator 2023/24=0,765 [C 102-24](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs102ra_202412.aspx). Tratar divisões por zero e resultados negativos de acordo com a exigibilidade, preservando o demonstrativo AT.

#### PPC003 Redução cessação

Pode cessar a obrigação se retenções B e PPC do próprio ano já cobrirem o imposto total estimado ou cessar a obtenção B. Pode reduzir-se a prestação à diferença estimada legalmente prevista. CIRS 102.º, n.os 4–5 [C 102](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs102.aspx). **CONFIRMADO**. A opção pressupõe estimativa do IRS completo, não apenas receita×coeficiente×taxa marginal.

#### PPC004 Juros e voluntários

Redução/cessação indevida pode gerar juros compensatórios quando ultrapassado o desvio de 20% previsto no n.º 6, com as restantes condições e cálculo diário do n.º 7. Adiantamentos voluntários de pelo menos 50 € admitidos nas condições do n.º 8. CIRS 102.º, n.os 6–8 [C 102](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs102.aspx). **CONFIRMADO** atual. O sistema deve conservar previsão, imposto estimado e montantes pagos, não apresentar a redução como perdão de imposto.

### IRS Jovem regras anuais e transição

#### Rendimentos de 2023 e 2024

#### JOV001 Elegibilidade antiga

Idade 18–26, não dependente, ciclo de estudos de nível QNQ≥4 concluído antes do primeiro ano de rendimentos elegível; limite de entrada 30 anos para doutoramento QNQ 8. Cinco anos seguidos/interpolados, com continuação até 35 anos e utilização uma única vez. CIRS 12.º-B, n.os 1–3 e 6 nas redações históricas [JOV 23](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs12bra_202312.aspx), [JOV 24](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs12bra_202412.aspx). **CONFIRMADO**. A extensão à categoria B tem enquadramento na Lei 12/2022 e transição respetiva; não retroprojetar todas as regras 2025.

| **ID/ano** | **Ano 1** | **Ano 2** | **Ano 3** | **Ano 4** | **Ano 5** | **Fonte e estado** |
| --- | --- | --- | --- | --- | --- | --- |
| JOV002/2023 | 50%, cap 12,5 IAS | 40%, cap 10 IAS | 30%, cap 7,5 IAS | 30%, cap 7,5 IAS | 20%, cap 5 IAS | CIRS 12-B 5, Lei 24-D/2022,[JOV 23](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs12bra_202312.aspx). **CONFIRMADO**. |
| JOV003/2024 | 100%, cap 40 IAS | 75%, cap 30 IAS | 50%, cap 20 IAS | 50%, cap 20 IAS | 25%, cap 10 IAS | CIRS 12-B 5, Lei 82/2023,[JOV 24](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs12bra_202412.aspx). **CONFIRMADO**. |

Com IAS 2023=480,43 €, os tetos monetários são 6.005,38 €; 4.804,30 €; 3.603,23 €; 3.603,23 €; 2.402,15 €, arredondados a cêntimos. Com IAS 2024=509,26 €, são 20.370,40 €; 15.277,80 €; 10.185,20 €; 10.185,20 €; 5.092,60 €. Os produtos e arredondamentos apresentados são cálculo aritmético a partir das normas, não uma regra adicional de elegibilidade.

#### Desde 2025

#### JOV004 Âmbito e duração

Rendimentos A/B de pessoa até 35 anos, não dependente, mediante opção declarativa, nos dez primeiros anos relevantes. Sem exigência de conclusão de estudos. Anos sem A/B não consomem a contagem; a idade máxima continua a limitar. CIRS 12.º-B, n.os 1 e 3, Lei 45-A/2024, art. 89.º [C 12 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12b.aspx), [OE 25](https://diariodarepublica.pt/dr/detalhe/lei/45-a-2024-901667918). **CONFIRMADO**, desde 01-01-2025. A categoria B em contabilidade organizada também é admitida: o Ofício-circulado n.º 20291/2026, de 17-03, ponto 3.2, confirma a opção no quadro 3 D.1 do Anexo C para rendimentos de 2025 e seguintes [OF 20291](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio-circulado-20291-2026.pdf). Esta confirmação declarativa não resolve a base de isenção nem a afetação de gastos na CO.

#### JOV005 Isenção e teto

100% ano 1; 75% anos 2–4; 50% anos 5–7; 25% anos 8–10. Limite anual da isenção 55 IAS: 28.737,50 € em 2025; 29.542,15 € em 2026. É um limite por titular para o benefício A/B, não um teto autónomo por categoria. Os rendimentos isentos relevam para progressividade e são considerados sem deduções na determinação das taxas. CIRS 12.º-B, n.os 4–5; 22.º, n.os 4 e 7, alínea a) [C 12 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12b.aspx), [C 22](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs22.aspx). **CONFIRMADO**. O teto limita a parcela isenta, não a receita máxima para poder exercer a opção. O critério de repartição entre A e B quando o teto é atingido permanece **NÃO CONFIRMADO** nesta memória; não atribuir prioridade nem rateio proporcional sem fundamento adicional.

#### JOV006 Transição

Em 2025 enquadra-se o titular no ano subsequente ao número de anos anteriores de rendimentos A/B, excluindo os anos em que foi dependente. A opção tardia não transforma esse ano em «ano 1»; A e B obtidos no mesmo ano não contam como dois anos. Lei 45-A/2024, art. 116.º, n.º 2; folheto AT 2025 e PIV 28315 [OE 25](https://diariodarepublica.pt/dr/detalhe/lei/45-a-2024-901667918), [FJOV 25](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/Folhetos_informativos/Documents/Folheto_IRS_jovem_2025.pdf), [PIV 28315](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/informacoes_vinculativas/rendimento/cirs/Documents/PIV_28315.pdf). Lei **CONFIRMADA**; exemplos/contagem administrativa **DOUTRINA**. O folheto AT, páginas 3–4, exclui também da contagem os anos com dispensa de entrega da Modelo 3: esta precisão é **DOUTRINA**, não texto literal da norma transitória. Não confundir dispensa legal com falta de entrega. Recolher histórico anual de rendimentos, condição de dependente e dispensa aplicável.

#### JOV007 Incompatibilidades

Exclui beneficiários atuais/anteriores de RNH e IFICI, quem opte por 12.º-A e quem não tenha situação tributária regularizada. CIRS 12.º-B, n.º 9 [C 12 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12b.aspx). **CONFIRMADO** atual. O motor deve recolher histórico, não só benefício aplicado no ano corrente.

#### JOV008 Base B

A PIV 28155, despacho de 24-03-2025, ponto 6, confirma que a isenção incide no rendimento bruto de advocacia classificado no artigo 3.º, n.º 1, alínea b), no caso de uma requerente no regime simplificado em 2025; confirma também a progressividade [PIV 28155](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/informacoes_vinculativas/rendimento/cirs/Documents/PIV_28155.pdf). **DOUTRINA** de um caso concreto. A ficha não apresenta cálculo numérico nem valida expressamente a sequência completa dos coeficientes, reduções iniciais, contribuições e despesas. A aplicação do coeficiente ao bruto remanescente após isenção é uma combinação interpretativa dos artigos 12.º-B e 31.º, não uma fórmula expressamente demonstrada na ficha. Permanecem **NÃO CONFIRMADOS** o rateio A+B com teto atingido, as bases dos testes de 10%/15% quando existe parcela isenta, a afetação de contribuições/despesas e as interações com múltiplos coeficientes, deficiência ou outras receitas empresariais. Na CO, a admissão no benefício está confirmada em JOV004, mas não se deve extrapolar desta PIV uma isenção sobre volume de negócios sem determinar gastos e lucro fiscal. A vinculação da informação reporta-se ao objeto do pedido, nos termos do artigo 68.º, n.º 14, da LGT [LGT 68](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/lgt/Pages/lgt68.aspx).

#### JOV009 Retenção B

O artigo 99.º-F regula tabelas A/H; a norma 101.º-D, n.º 4, refere isenções do EBF, não diretamente 12.º-B. O guia prático OCC 2025 indica ausência de redução especial da retenção B por IRS Jovem. Fontes [C 99 F](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs99f.aspx), [C 101 D](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs101d.aspx), [OCCJOV](https://www.occ.pt/sites/default/files/public/2025-01/Guia_Pratico_IRS_FINAL.pdf). **DOUTRINA** para a conclusão operacional; não criar automaticamente retenção zero a partir da isenção anual sem norma aplicável.

### Dependências indispensáveis do IRS final

Um cálculo de B terminado no artigo 31.º ou 32.º é apenas a determinação de rendimento da categoria. O resultado final exige os módulos seguintes.

#### Residência agregado englobamento e taxas

#### FIN001 Residência

O artigo 16.º, n.os 1–5 define residência, permanência, habitação e residência parcial; o artigo 17.º, n.os 1–4 define residência regional, considerando permanência, residência habitual/registo e, subsidiariamente, centro de interesses. Não selecionar região apenas pelo local de um cliente. [C 16](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs16.aspx), [C 17](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs17.aspx). **CONFIRMADO**, atual. Convenções de dupla residência e estatuto de não residente requerem análise adicional.

#### FIN002 Englobamento

O artigo 22.º agrega rendimentos líquidos por categorias, com exceções e opções próprias. Quando a lei imponha progressividade, os rendimentos isentos entram na determinação das taxas sem deduções (n.os 4 e 7, alínea a)); não reduzir automaticamente essa parcela isenta pelo coeficiente B. Para tributação conjunta, o artigo 69.º prevê divisão por 2 e multiplicação da coleta por 2, observando ainda a imputação proporcional dos rendimentos isentos do artigo 22.º, n.º 7, alínea b). Filhos não criam automaticamente divisores adicionais. [C 22](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs22.aspx), [C 69](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs69.aspx). **CONFIRMADO** atual. A comparação conjunta/separada exige o cálculo completo, incluindo deduções e mínimos.

#### FIN003 Solidariedade

Artigo 68.º-A: 2,5% sobre parcela entre 80.000 € e 250.000 €; 5% acima 250.000 €. Tributação conjunta aplica o mecanismo sobre metade e multiplica por 2. [C 68 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs68a.aspx). **CONFIRMADO** atual. Não aplicar 5% a todo rendimento acima 80.000 €.

#### Mínimo de existência

#### ME001 Âmbito restrito

CIRS 70.º, n.º 2 abrange rendimentos brutos predominantemente A, pensões ou atividades da tabela Portaria 1011/2001, **excluindo o código 15**. Não é uma isenção universal para todo ENI, qualquer CAE, quaisquer serviços 0,35 ou todos os titulares B. Fonte [C 70](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs70.aspx). **CONFIRMADO**, atual.

#### ME002 Variáveis e travões

Consideram-se todos os rendimentos brutos declarados, incluindo isentos/excluídos, com tratamento próprio de saldos de mais-valias e prediais. As deduções específicas incluem as indicadas no 70.º, n.º 5, b), designadamente as decorrentes de 31.º, n.º 1, b), n.os 2 e 10. Exclui-se o abatimento se os rendimentos brutos globais excederem 2,2×14 IAS×n.º sujeitos passivos, ou se rendimentos não englobados sujeitos a taxas liberatórias excederem 14 IAS×n.º sujeitos passivos. CIRS 70.º, n.os 4–5 [C 70](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs70.aspx). **CONFIRMADO** atual.

#### ME003 Fórmula atual tradução algébrica

Usando VR=valor referência, RB=brutos, DE=deduções específicas, G=limite despesas gerais 250 € por sujeito passivo (zero para dependente), t=taxa primeiro escalão em decimal e B1=limite primeiro escalão:

- L = VR + (B1 − G/t) /3,60.
- RB≤VR: abatimento preliminar=max(0, VR−DE−G/t).
- VR<RB≤L: max(0, VR−2,60×(RB−VR)−DE−G/t).
- RB>L: max(0, L−B1−1,35×(RB−L)−DE).
- Limitar o abatimento ao intervalo entre zero e RB−DE; aplicar previamente os testes de exclusão.

Base: CIRS 70.º, n.os 2–5 [C 70](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs70.aspx), alterações Lei 82/2023 e Lei 34/2024, art 2.º [L 34](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Lei_34_2024.pdf). **CONFIRMADO** para a norma; fórmulas acima são tradução operacional que deve ser confrontada com a publicação anual AT prevista no n.º 6. A incidência conjunta com IRS Jovem, rendimento estrangeiro e outras exclusões fica dependente de validação específica.

#### ME004 Histórico

VR 2023=10.640 €; 2024=11.480 €; 2025=12.180 €; 2026=max(12.880 €, 21 IAS), sendo 12.880 € com IAS 2026. Em 2023 aplicou-se a norma transitória do artigo 225.º da Lei 24-D/2022: parâmetros 2,3; 1,3; divisor 3,3, em vez dos posteriores 2,60; 1,35; 3,60. Fontes [ME 23](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2022-205695052-205739827), [AT 24](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/IRS/IRS_2024/Paginas/5_Deducoes_beneficios_taxas.aspx), [C 70-25](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs70ra_202512.aspx), [C 70](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs70.aspx). **CONFIRMADO**. A página atual AT contém «Lei 73-A/2024» junto ao valor 2026, mas o diploma ligado e o OE correto são Lei 73-A/2025; é uma gralha de atribuição.

#### Deduções deficiência e regimes especiais

#### DED001 Coleta e adiantamentos

Deduções do artigo 78.º, n.º 1 incluem dependentes/ascendentes, despesas gerais, saúde, educação, imóveis, alimentos, faturas, lares, deficiência, dupla tributação, benefícios fiscais, AIMI e trabalho doméstico. Existem limites individuais e globais. Retenções/PPC são abatidos nos termos do n.º 2; ao contrário das deduções ordinárias, o seu excesso pode gerar reembolso. [C 78](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs78.aspx). **CONFIRMADO**, atual. O presente documento não certifica todas as percentagens e limites das deduções setoriais.

#### DED002 Limite global

O CIRS 78.º, n.º 7, limita a soma das deduções das alíneas c) a h), k) e m) do n.º 1; não todas as deduções. Até ao limite do primeiro escalão do art. 68.º inclusive, não há este limite global. Acima desse limite e até 80.000 € inclusive, o limite é , sendo R o rendimento coletável relevante e L o limite do primeiro escalão do ano. Acima de 80.000 €, o limite é 1.000 €. L vale 7.479 € em 2023, 7.703 € em 2024, 8.059 € em 2025 e 8.342 € em 2026. Na tributação conjunta, considera-se o rendimento após o divisor do art. 69.º; não duplicar automaticamente o limite global. O n.º 8 majora o limite em 5% por dependente/afilhado civil elegível nos agregados com três ou mais. O n.º 14 reduz a metade os limites definidos por agregado em tributação separada, com as regras próprias de afetação de despesas. Base: CIRS 78.º, n.os 7, 8 e 14, e 68.º/68.º-A. **CONFIRMADO:** fórmula na redação da Lei 12/2022, vigente nos quatro anos; alínea m) incluída desde 2024 pela Lei 82/2023. Fontes: [C 78](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs78.aspx) e [imagem oficial da fórmula](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/PublishingImages/irs78_Lei_12_2022.PNG). As restantes condições específicas de cada dedução continuam a exigir o respetivo artigo.

#### DED003 Alguns valores estruturais

Dependente 600 €; residência alternada nas condições legais 300 € por progenitor. Suplemento até 3 anos: 126 € no regime geral ou 63 € por progenitor em residência alternada. Para o segundo dependente e seguintes até 6 anos: suplemento de 300 € no regime geral ou 150 € por progenitor em residência alternada, sem cumular os dois suplementos. Ascendentes: 525 € e 110 € adicionais se único e cumpridas as condições. CIRS 78.º-A, n.os 1–4 [C 78 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs78a.aspx); regra do segundo dependente na redação da Lei 24-D/2022, desde 01-01-2023. Despesas gerais: 35% até 250 €/sujeito; monoparentais 45% até 335 €; exclui despesas profissionais. CIRS 78.º-B, n.os 1,4,9 [C 78 B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs78b.aspx). **CONFIRMADO** atual; a partilha concreta deve seguir a situação familiar.

#### DEF001 Base e coleta

Deficiência fiscalmente relevante: incapacidade≥60% comprovação legal. Rendimentos A/B considerados 85%, H 90%, com exclusão máxima 2.500 € por categoria. CIRS 56.º-A, n.os 1–2 [C 56 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs56a.aspx). Artigo 87.º prevê dedução 4 IAS por titular,2,5 IAS por dependente/ascendente elegível, outros benefícios e acompanhamento≥90%; n.º 9 contém transição de reavaliação sob condições. [C 87](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs87.aspx). **CONFIRMADO** atual. Não confundir redução de rendimento, dedução à coleta e redução de base de retenção; são três etapas diferentes.

#### ESP001 Ex residentes

Artigo 12.º-A: 50% dos rendimentos A/B excluídos, por cinco anos, até limite de rendimentos de 250.000 €, para quem se torne residente até 2026, não tenha residido nos cinco anos anteriores, tenha residência anterior em Portugal e situação regularizada. Incompatível com inscrição RNH. O limite introduzido aplica-se a quem se torne residente em 2024 ou posterior, por Lei 82/2023, art. 236.º, n.º 6. [C 12 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12a.aspx). **CONFIRMADO** atual. Históricos anteriores 2024 têm condições diferentes; não foram integralmente reproduzidos.

#### ESP002 RNH transitório

A revogação dos 16.º, n.os 8–12 não elimina direitos salvaguardados. Lei 82/2023, art. 236.º, n.os 3–5 preserva situações inscritas e certas residências 2023/2024 com compromissos documentados nas datas legais, pelo prazo remanescente. Taxa 20% em A/B de atividades elegíveis e regras estrangeiras decorrem da redação antiga salvaguardada, não de um RNH aberto a qualquer novo residente 2026. [C 16](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs16.aspx), [C 12 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12a.aspx), [C 101-23](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs101ra_202312.aspx). **CONFIRMADO** regime transitório; catálogo elegível, documentação e todos os métodos internacionais **NÃO CONFIRMADOS** integralmente.

#### ESP003 IFICI

EBF 58.º-A, n.os 1–3: novos residentes sem residência nos cinco anos anteriores e atividades/entidades qualificadas podem ter 20% sobre líquidos A/B durante dez anos consecutivos, com opção de englobamento. N.os 4–7 regulam continuidade, regresso e inscrição tardia; n.os 10–12 excluem RNH/ex-residentes e duplicação. [EBF 58 A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/bf_rep/Pages/EBF58A.aspx). Portaria 352/2024/1 regula inscrição e profissões/atividades [P 352](https://diariodarepublica.pt/dr/detalhe/portaria/352-2024-901014291); não basta ser programador por conta própria. **CONFIRMADO** estrutura; inscrição concreta, listagens de entidades e regulamentação regional **NÃO CONFIRMADAS** integralmente.

#### DED004 Rendas de habitação atualização 2026

O limite geral da dedução de rendas passa a 900 € em 2026 e a 1.000 € a partir de 2027, nas condições do artigo 78.º-E do CIRS. Base: Decreto-Lei n.º 97/2026, artigos 5.º, 15.º e 18.º; norma transitória de 2026 e produção de efeitos em 01-01-2026. Fontes: [C 78 E](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs78e.aspx) e [DL 97/2026](https://diariodarepublica.pt/dr/detalhe/decreto-lei/97-2026-1124493227). **CONFIRMADO** para estes limites e datas; as condições, majorações e interações integrais da dedução permanecem **NÃO CONFIRMADAS** nesta memória. Não aplicar valores futuros aos anos anteriores.

#### ESP004 Outras dependências

Crédito de imposto estrangeiro, retenções definitivas, opções de englobamento de outras categorias, participação municipal IRS, deduções EBF/PPR/donativos e regimes de propriedade intelectual podem modificar o resultado. Identificação deste mapa não equivale a investigação integral dessas regras. **NÃO CONFIRMADO** para cálculo completo fora dos blocos aqui documentados.

#### Regiões autónomas

#### RA001 Açores

DLR 2/99/A, art 4.º, n.º 1, na redação do DLR 15-A/2021/A, aplica redução 30% às taxas nacionais vigentes. O n.º 2 define os destinatários, incluindo residentes fiscais independentemente do local da atividade. [ACORES](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-legislativo-regional/1999-164477580-164477048). **CONFIRMADO** na versão consolidada consultada; a pesquisa oficial devolveu texto, embora a abertura da página exija JavaScript. A aplicação detalhada a solidariedade, retenções especiais e outros subtotais exige correspondência normativa própria, não uma multiplicação cega do imposto final por 70%.

#### RA002 Madeira 2026

DLR 17/2026/M, art 2.º, substitui a tabela do DLR 3/2001/M, art 2.º: taxas 8,54%; 10,64%; 14,49%; 16,52%; 21,42%; 24,22%; 30,17%; 31,22%; 33,60%, nos mesmos limites nacionais 2026. O art 3.º fixa entrada 06-11-2026 e efeitos 01-01-2026. [MADEIRA 26](https://diariodarepublica.pt/dr/detalhe/decreto-legislativo-regional/17-2026-1191346240). **CONFIRMADO**. As tabelas finais da Madeira de 2023–2025 constam das tabelas anuais regionais do capítulo 8 da memória consolidada, com fontes e versões próprias. A relação entre taxas normais nacionais e regionais de 70% é válida nesta tabela 2026; as taxas médias devem ser lidas diretamente no diploma. Não presumir essa relação para todos os anos anteriores nem para a liquidação final. Outros benefícios e adaptações regionais não estão integralmente parametrizados.

### Exemplos de cálculo parcial

Os exemplos seguintes são cálculos próprios, úteis para detetar erros de implementação; não representam simulação completa de IRS nem substituem condições individuais.

#### EX001 Despesas não são abatidas duas vezes

Serviços 0,75; 40.000 € brutos em 2026, sem SS dedutível nem outros encargos elegíveis: base por coeficiente 30.000 €; 15% brutos 6.000 €; automático 4.587,09 €; acréscimo 1.412,91 €; resultado B 31.412,91 €, antes de outras regras. Não subtrair 4.587,09 € aos 30.000 €. Regras DES002/003.

#### EX002 Início não apaga os 15 por cento

Mesmos dados com condições da redução 50%: coeficiente 0,375, resultado base 15.000 €, acréscimo 1.412,91 €, total B 16.412,91 €. Um rendimento A/H no ano impede a redução. A aplicação combinada com IRS Jovem exige validação própria e não decorre deste exemplo.

#### EX003 Retenção não é imposto final

Uma prestação profissional sujeita a 23% de retenção pode ter imposto anual inferior ou superior. A retenção calculada sobre o bruto sem IVA é registada como adiantamento e abatida na liquidação; não reduz a receita B nem o coeficiente.

#### EX004 Fronteiras que exigem testes

Receita 200.000 € versus 200.000,01 €; 250.000 € versus 250.000,01 €; retenção 24,99 € versus 25 €; viatura 29.999,99 € versus 30.000 €; cessação exatamente há cinco anos; limite da dispensa atingido em dezembro; primeiro ano em dezembro; opção CO em 31 março; beneficiário IRS Jovem com 35 anos e interrupções. Estes casos derivam dos comparadores legais e são mais informativos do que testar apenas receitas redondas.

## 3 IVA nacional e operações internacionais

#### IVA01 Sujeição e momento relevante

**CONFIRMADO; regime estrutural vigente em 2023–2026.** O exercício independente de uma atividade económica, incluindo serviços profissionais e determinadas operações ocasionais, coloca a pessoa no âmbito do IVA. O imposto não acompanha automaticamente o recebimento: normalmente releva a colocação do bem à disposição ou a realização do serviço, articulada com a emissão tempestiva da fatura, o termo do prazo legal de emissão e os adiantamentos. Serviços continuados têm regras próprias. O regime de caixa é uma opção especial, não a regra dos recibos verdes. A aplicação deve guardar separadamente datas de realização, faturação, recebimento e exigibilidade.

**Base:** CIVA, arts. 2.º, n.º 1, al. a), 7.º, n.os 1 e 3–5, e 8.º, n.os 1–3. Fontes: [sujeitos passivos](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva2.aspx), [facto gerador](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva7.aspx), [exigibilidade](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva8.aspx).

#### IVA02 Localização geral de bens e serviços

**CONFIRMADO; regras gerais aplicáveis em 2023–2026.** Bens com transporte localizam-se, em regra, onde começa a expedição; sem transporte, onde são disponibilizados. Serviços B2B localizam-se no estabelecimento do adquirente a que são prestados; B2C, no estabelecimento do prestador, salvo exceções. Um cliente estrangeiro não torna automaticamente a operação isenta. Primeiro determina-se a localização, depois a sujeição/isenção e quem liquida.

**Base:** CIVA, art. 6.º, n.os 1 e 6, als. a) e b). [Artigo 6.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva6.aspx).

#### IVA03 Exceções à localização e serviços digitais

**CONFIRMADO; alterações de eventos virtuais desde 29-3-2025.** Imóveis, transportes de passageiros, restauração, acesso presencial a eventos e aluguer de curta duração de meios de transporte têm critérios especiais. Em B2C existem ainda regras para transportes de bens, trabalhos sobre móveis, intermediação, aluguer prolongado e serviços eletrónicos/telecomunicações/radiodifusão. Desde o DL 33/2025, eventos transmitidos ou disponibilizados virtualmente têm tratamento específico ligado ao destinatário; não se confundem com entrada presencial.

Para B2C com destinatário fora da UE, consultoria, engenharia, advocacia, contabilidade, publicidade, tratamento de dados e direitos, entre outras categorias expressamente enumeradas, podem localizar-se fora de Portugal; existem correções por utilização efetiva. Desenvolvimento personalizado e serviços automatizados exigem classificação factual, não apenas o CAE.

**Base:** CIVA, art. 6.º, n.os 7–15; DL 33/2025, de 24 de março. Fontes: [CIVA](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva6.aspx), [FAQ oficial de serviços internacionais](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/questoes_frequentes/pages/faqs-00924.aspx).

#### IVA04 Isenções profissionais condições concretas

**CONFIRMADO; redação vigente no corte.** O art. 9.º não é uma lista geral de profissões isentas. A operação tem de preencher a norma:

| **Norma** | **Operação e condição relevante** |
| --- | --- |
| Art. 9.º, 1) | Serviços no exercício das profissões de médico, odontologista, psicólogo, parteiro, enfermeiro e outras profissões paramédicas, nos termos aplicáveis aos cuidados de saúde. |
| 2) e 3) | Cuidados em estabelecimentos hospitalares/similares; serviços e próteses dentárias nas condições específicas. |
| 9) | Ensino por estabelecimentos integrados no sistema nacional ou reconhecidos como tendo fins análogos. |
| 10) | Formação profissional por organismos públicos ou entidades reconhecidas competentes; ser formador individual não basta. |
| 11) | Lições sobre matérias do ensino escolar ou superior; desde 1-1-2024 foi retirada a antiga exigência literal «a título pessoal». |
| 15), al. a) | Serviços de artistas e músicos aos respetivos promotores, para as finalidades enumeradas; não qualquer serviço criativo. |
| 16) | Transmissão/licença de direitos de autor e direitos conexos pelos titulares, herdeiros/legatários ou terceiros por sua conta. |
| 27), 28), 29), 30) | Operações financeiras especificadas, seguros/intermediação, locação e transmissão imobiliária, com exceções próprias. |

**Base:** [CIVA, art. 9.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva9.aspx). **DOUTRINA:** [Ofício 25018/2024](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio_circulado_25018_2024.pdf), sobre ensino. A finalidade terapêutica não pode ser presumida em qualquer serviço associado à saúde: [Acórdão STA 1/2022](https://info.portaldasfinancas.gov.pt/pt/atualidades/legislativa/Paginas/Acordao_Supremo_Tribunal_Administrativo_1_2022.aspx), relativo a nutrição em contexto desportivo.

#### IVA05 Renúncia a isenções e operações mistas

**CONFIRMADO; vigente no corte.** Não existe opção universal para tributar voluntariamente todas as atividades do art. 9.º. O art. 12.º permite renúncia apenas nos casos indicados, incluindo certas formações e serviços de saúde, com condições e permanência mínima de cinco anos; imóveis seguem disciplina particular. Uma pessoa pode simultaneamente realizar operações isentas e tributadas, obrigando a tratar separadamente as bases e a dedução.

**Base:** CIVA, arts. 12.º, n.os 1–5, e 23.º. [Renúncia](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva12.aspx), [atividades mistas](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva23.aspx).

#### IVA06 Taxas territoriais e temporais

**CONFIRMADO.** Taxa reduzida/intermédia/normal:

| **Território** | **2023** | **1-1 a 30-9-2024** | **Desde 1-10-2024 até ao corte** |
| --- | --- | --- | --- |
| Continente | 6% / 13% / 23% | 6% / 13% / 23% | 6% / 13% / 23% |
| Açores | 4% / 9% / 16% | 4% / 9% / 16% | 4% / 9% / 16% |
| Madeira | 5% / 12% / 22% | 5% / 12% / 22% | **4% / 12% / 22%** |

Aplica-se a taxa vigente na exigibilidade e no território onde a operação se localiza; não basta a residência do emitente. As listas I e II têm de ser classificadas por operação. Serviços comuns de programação não ganham uma taxa reduzida por serem atividade profissional.

**Base:** CIVA, arts. 18.º, n.os 1, 3, 7 e 9, e 6.º, n.os 16–17; DLR 6/2024/M, arts. 21.º e 121.º, n.º 2; DLR 15-A/2021/A e Ofício 30237/2021 para os Açores, desde 1-7-2021. Fontes: [taxas CIVA](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva18.aspx), [Madeira](https://diariodarepublica.pt/dr/detalhe/decreto-legislativo-regional/6-2024-873755644), [histórico oficial das taxas](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/ra/Pages/ivara18_062022.aspx). Algumas páginas informativas governamentais ainda apresentam 5% na Madeira: não prevalecem sobre o diploma.

### Isenção das pequenas empresas reforma de 2025

#### IVA07 Evolução histórica e separação do IRS

**CONFIRMADO.** Limites nominais do art. 53.º: **13.500 em 2023; 14.500 em 2024; 15.000 desde 2025**. O calendário de saída e as condições anteriores à reforma diferem das atuais: não recalcular 2023–2024 com a tolerância de 25% introduzida em 2025.

Até à reforma, contabilidade organizada, importações/exportações e atividades do anexo E impediam o regime nas condições então previstas. Desde o DL 35/2025, **contabilidade organizada, importações e atividades do anexo E deixaram de ser, por si, impedimentos**. Consequentemente, IRS simplificado não implica isenção IVA, e contabilidade organizada já não obriga sempre a IVA normal.

**Base:** Lei 24-D/2022, art. **282.º, n.º 3**; DL 35/2025, arts. 2.º e 10.º. Fontes: [OE 2023 original](https://diariodarepublica.pt/dr/detalhe/lei/24-d-2022-205557192), [redação anterior do art. 53.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/ra/Pages/iva53ra_202503.aspx), [reforma](https://diariodarepublica.pt/dr/detalhe/decreto-lei/35-2025-912066244). A anotação histórica da AT remete para n.º 2 do art. 282.º; o texto original coloca os valores no n.º 3.

#### IVA08 Acesso nacional atual

**CONFIRMADO; regime novo desde 29-3-2025, com transição específica.** Para estabelecidos em Portugal: volume de negócios anual em território nacional do ano anterior **não superior a 15.000**, e não realização de exportações ou atividades conexas. No início, considera-se a previsão até ao fim do ano civil corrente, **sem anualizar**. O n.º 6 exclui os sujeitos que pratiquem uma só operação tributável nas condições do art. 2.º, n.º 1, al. a), usualmente designada ato isolado, e as transmissões intracomunitárias de meios de transporte novos. Uma operação pouco frequente dentro de atividade aberta não é automaticamente um ato isolado.

**CONFIRMADO — exportação ocasional:** uma única exportação de bens no âmbito da atividade provoca a perda do art. 53.º desde essa operação, independentemente do valor, com declaração de alterações em 15 dias úteis. A própria exportação pode continuar isenta pelo art. 14.º, cumpridos os requisitos e a prova: distinguir a isenção da operação da perda do regime do sujeito. Serviços B2B não localizados em Portugal não constituem exportações ou atividades conexas apenas por terem cliente estrangeiro; aplicar IVA03, IVA09 e IVA16.

**Base:** CIVA, art. 53.º, n.os 1, 5 e 6, e art. 58.º, n.º 2, al. c), n.º 4, al. c), e n.º 5, al. c). [Texto do art. 53.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/artigo-53-o-do-civa.aspx), [art. 58.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva58.aspx), [isenção da exportação no art. 14.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva14.aspx). **DOUTRINA convergente:** [Ofício 25062/2025, pontos 9 e 21, al. c), e exemplo 7](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio_circulado_25062_2025.pdf), que exemplifica a exportação de um bem. **Divergência documental resolvida pela lei:** a [FAQ AT 5735](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/questoes_frequentes/pages/faqs-00101.aspx) acrescenta «exclusivamente», palavra ausente do articulado e incompatível com a circular; não utilizar essa formulação para admitir exportações ocasionais no regime.

#### IVA09 Qual volume de negócios entra no limite

**CONFIRMADO; desde a reforma.** Os arts. 52.º-A e 52.º-B usam o local da operação, não a nacionalidade do cliente nem o total de recibos. Incluem operações que seriam tributadas sem esta isenção, certas isenções completas e operações financeiras/seguros/imobiliárias não acessórias; excluem alienações de bens de investimento. Serviços B2B localizados fora de Portugal não integram o volume nacional só por serem faturados por um residente.

O volume do art. 42.º, relevante para outras obrigações, é diferente. **DOUTRINA:** o Ofício 25094/2025 explica a inclusão de operações externas no volume geral, subsídios ligados ao preço e operações isoladas relevantes; aquisições com autoliquidação não são vendas do adquirente. Não reutilizar um único acumulador para todos os limites.

**Base:** [art. 52.º-A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva52a.aspx), [art. 52.º-B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva52b.aspx), [art. 42.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva42.aspx), [Ofício 25094/2025](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio-circulado-25094-2025.pdf).

#### IVA10 Ultrapassagem igualdade e início da tributação

**CONFIRMADO; novo art. 58.º.** Se o ano terminar acima de 15.000, mas sem ultrapassar **18.750**, a tributação inicia-se em **1 de janeiro seguinte**. Ultrapassagem em mais de 25%, isto é, **>18.750**, determina saída imediata. Exatamente 15.000 não perde o acesso; exatamente 18.750 não aciona saída imediata. A perda de outros requisitos também pode provocar tributação imediata. A declaração de alterações tem prazo de **15 dias úteis**, contado do termo do ano ou do evento aplicável.

**DOUTRINA confirmada na orientação AT:** a operação que faz ultrapassar 18.750 fica integralmente tributada; não apenas a parcela excedente. Exemplo de motor: acumulado 18.500 e venda de 500 implica IVA sobre 500, se não houver outra isenção.

**Base:** CIVA, art. 58.º, n.os 2–5; [lei consolidada](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva58.aspx), [Ofício 25062/2025](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio_circulado_25062_2025.pdf). Em 2026, a orientação exemplifica 22 de janeiro como termo dos 15 dias úteis para a saída por excesso de 2025.

#### IVA11 Transição de 2025 não retroativa

**CONFIRMADO.** O DL 35/2025, publicado em 24 de março, contém um art. 10.º que impede uma aplicação uniforme ao ano inteiro. Contribuintes antes excluídos e agora elegíveis puderam declarar em junho para entrar em **1-7-2025**, salvo volume do primeiro semestre acima de 18.750. Quem já estava isento e ultrapassou esse valor no primeiro semestre passou ao normal em 1 de julho, com declaração nos primeiros 15 dias úteis. Faturas anteriores validamente isentas não se tornam retroativamente tributadas. Há regras próprias para não residentes anteriormente abrangidos, registos efetuados antes da publicação e pré-notificação transfronteiriça a partir de abril.

**Base:** DL 35/2025, art. 10.º, n.os 1–10. [Diploma original](https://diariodarepublica.pt/dr/detalhe/decreto-lei/35-2025-912066244). **DOUTRINA:** [Ofício 25062/2025, secção transitória](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio_circulado_25062_2025.pdf).

#### IVA12 Opção pelo normal regresso e reinício

**CONFIRMADO.** A opção voluntária pelo regime normal produz efeitos desde a declaração e vincula durante **cinco anos**. O regresso requer os requisitos e declaração em janeiro no momento permitido. Passagem do normal ao isento pode obrigar à regularização do IVA anteriormente deduzido em existências e bens de investimento. Cessar e reiniciar não elimina as barreiras: o art. 56.º restringe o acesso após cessação no normal e quando o limite seria excedido.

**Base:** CIVA, arts. 54.º, n.os 1–3, 55.º, n.os 1–4, e 56.º. Fontes: [passagem à isenção](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva54.aspx), [opção](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva55.aspx), [reinício](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva56.aspx).

#### IVA13 Obrigações e incompatibilidade com dedução

**CONFIRMADO; redação reformada em 2025.** Isento continua a emitir faturas, cumprir declarações cadastrais e usar a menção **«IVA - regime de isenção»**. A isenção não permite deduzir IVA das compras afetas às operações isentas. O art. 59.º dispensa obrigações gerais adicionais, incluindo a declaração recapitulativa no enquadramento esclarecido pela AT; isso não apaga obrigações específicas por importação, aquisições intracomunitárias tributadas ou serviços estrangeiros com autoliquidação.

**Base:** CIVA, arts. 57.º e 59.º; [art. 57.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva57.aspx), [art. 59.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva59.aspx). **DOUTRINA:** Ofício 25062/2025, ponto 28, sobre dispensa de recapitulativa. Não importar automaticamente obrigações anteriores à reforma.

#### IVA14 Isenção transfronteiriça e número EX

**CONFIRMADO; Diretiva aplicável desde 1-1-2025; execução portuguesa reformada em 2025.** O limite europeu de **100.000** é de volume anual na União, acumulado por pessoa; não substitui o limite português de 15.000 nem os limites de cada Estado. Um estabelecido noutro Estado-Membro pode beneficiar em Portugal com os requisitos do art. 53.º, n.º 2, pré-notificação no seu Estado e identificador com sufixo EX. Um estabelecido em Portugal pode pedir a isenção noutros Estados através da AT, cumprindo os respetivos requisitos. Só se aplica após confirmação do identificador/país, não pela mera vontade de usar a isenção.

**Base:** CIVA, arts. 53.º, n.º 2, e 58.º-A; Diretiva 2020/285, art. 1.º, que altera, entre outros, os arts. 284.º–288.º-A da Diretiva IVA. [Art. 58.º-A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva58a.aspx), [Diretiva oficial](https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=celex%3A32020L0285). O teto harmonizado de 85.000 para certas escolhas nacionais não é um limite português utilizável.

#### IVA15 Reporte EX e saída

**CONFIRMADO.** Declaração trimestral EX até ao **fim do mês seguinte**, com valores por Estado, incluindo informação sem operações. Exceder 100.000 na União exige comunicação em **15 dias úteis** e informação do período parcial; não existe tolerância europeia automática de 25%. Cessação ou mudança do Estado de estabelecimento têm comunicação em cinco dias úteis. A saída voluntária e a alteração dos países seguem datas próprias do art. 58.º-C. A conversão EX usa a taxa BCE do primeiro dia do ano, ou publicação seguinte, diferente da conversão OSS.

**Base:** CIVA, arts. 58.º-B e 58.º-C. [Reporte](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva58b.aspx), [alterações](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva58c.aspx). **DOUTRINA:** [Ofício 25065/2025, de 8 de abril](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio_circulado_25065_2025.pdf).

### Clientes e fornecedores internacionais

#### IVA16 Serviços B2B UE e países terceiros VIES

**CONFIRMADO.** Na regra geral B2B, serviço a estabelecimento empresarial estrangeiro não é localizado em Portugal. Para cliente UE, validar estatuto/NIF no VIES e conservar prova; existem meios alternativos de prova nos casos do Regulamento 282/2011, art. 18.º, pelo que um resultado técnico isolado não é um classificador universal. A fatura identifica a causa de não liquidação e, quando aplicável, **«IVA - autoliquidação»**. Operações para país terceiro não originam declaração recapitulativa portuguesa de serviços UE.

**Base:** CIVA, arts. 6.º, n.º 6, al. a), 29.º, n.º 1, al. i), e 36.º, n.º 13; [Regulamento 282/2011, art. 18.º](https://eur-lex.europa.eu/legal-content/PT/ALL/?uri=CELEX%3A32011R0282), [FAQ AT](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/questoes_frequentes/pages/faqs-00924.aspx). Regra sujeita às exceções de IVA03.

#### IVA17 Recapitulativa normal e limiar de 50 000

**CONFIRMADO; ressalvada a dispensa atual do art. 59.º.** No regime normal, recapitulativa das operações abrangidas até dia **20 do mês seguinte**, para mensal, ou dia **20 após o trimestre**, para trimestral. O limiar de **50.000**, no trimestre em curso ou em qualquer dos quatro anteriores, que força reporte mensal respeita às transmissões de bens previstas na norma; não deve ser aplicado indiscriminadamente ao total de serviços. Só há reporte dos períodos com operações abrangidas.

**Base:** RITI, art. 30.º, conjugado com CIVA, art. 29.º, n.º 1, al. i). [RITI atual, art. 30.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti030.aspx). Usar a republicação de 2021; as pastas antigas da AT contêm redações históricas ainda acessíveis.

#### IVA18 Compra de serviços estrangeiros por isentos

**CONFIRMADO.** Um isento dos arts. 9.º ou 53.º continua a ser sujeito passivo para os serviços B2B recebidos. Serviços abrangidos pela regra geral prestados por fornecedor não estabelecido — publicidade, alojamento informático, software ou comissões, conforme contrato — podem exigir autoliquidação portuguesa **desde a primeira operação**, sem franquia de 10.000. A isenção das vendas não permite deduzir esse imposto.

Desde **1-7-2026**, o art. 27.º, n.º 3, determina declaração eletrónica correspondente às operações tributáveis e pagamento até ao **fim do mês seguinte** para os adquirentes abrangidos pelas als. e), g), h) e j) do art. 2.º, n.º 1, que **não estejam obrigados à apresentação de declaração periódica nos termos do art. 41.º**. Este é o critério literal atual; o n.º 5 limita a obrigação aos períodos com operações tributáveis.

**Base:** CIVA, arts. 2.º, n.º 1, al. e), e n.º 5, 6.º, n.º 6, al. a), e 27.º, n.os 3 e 5; DL 97/2026, arts. 4.º e 18.º, n.º 4. [Art. 27.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva27.aspx), [DL 97/2026 original](https://diariodarepublica.pt/dr/detalhe/decreto-lei/97-2026-1124493227). **DOUTRINA anterior sobre incidência:** [PIV 25603](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/informacoes_vinculativas/despesa/civa/Documents/PIV_25603.pdf); os procedimentos antigos não devem sobrepor-se à lei nova.

#### IVA19 Aquisições intracomunitárias de bens por isentos

**CONFIRMADO.** O RITI, art. 5.º, permite a derrogação para certos adquirentes sem dedução enquanto o total de aquisições relevantes não exceder **10.000 no ano anterior nem no corrente**. Meios de transporte novos e produtos sujeitos a impostos especiais têm exclusões. A opção pela tributação vincula, em regra, por dois anos. O limite refere-se a bens adquiridos, não a serviços estrangeiros nem às vendas.

Se uma aquisição individual exceder o limite, a declaração cadastral deve precedê-la; na ultrapassagem acumulada há prazo até ao fim do mês seguinte. Os arts. 22.º e 29.º impõem pagamento e declaração até ao fim do mês seguinte, nos períodos com aquisições tributadas.

**Base:** RITI, arts. 5.º, 25.º, 22.º e 29.º. Fontes: [derrogação](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti005.aspx), [registo](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti025.aspx), [declaração](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti029.aspx), [pagamento](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti022.aspx).

#### IVA20 Venda de bens UE e importações

**CONFIRMADO.** A isenção normal de transmissão intracomunitária de bens exige os requisitos do art. 14.º do RITI, incluindo transporte para outro Estado, estatuto/NIF do adquirente e obrigações declarativas. O n.º 7 afasta a aplicação das als. a) e d) do n.º 1 aos abrangidos pelo art. 53.º; essa adição do DL 35/2025 tem caráter interpretativo. Não atribuir automaticamente o mesmo motivo de isenção a fornecedor normal e pequeno isento.

Importar já não exclui automaticamente o art. 53.º, mas a importação continua, em regra, a suportar IVA aduaneiro não dedutível nesse regime. A opção pelo IVA de importação na declaração periódica tem requisitos próprios, incluindo periodicidade mensal e regularidade fiscal.

**Base:** [RITI, art. 14.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti014.aspx), DL 35/2025, art. 9.º; CIVA, arts. 2.º, n.º 1, al. b), e 27.º, n.º 8.

#### IVA21 Limiar B2C de 10 000 e OSS

**CONFIRMADO; sistema desde 1-7-2021.** O art. 6.º-A contém outro limite de **10.000**, conjunto para serviços de telecomunicações/radiodifusão/eletrónicos e vendas à distância intracomunitárias de bens, no ano anterior e corrente, quando o fornecedor está estabelecido apenas num Estado-Membro. Não é um limite geral de todos os serviços B2C. Ultrapassado, aplica-se destino desde a operação relevante; a opção antecipada pelo destino vincula dois anos.

O OSS União permite declarar certas vendas à distância e serviços B2C tributáveis noutros Estados; não é uma isenção. Declaração trimestral e pagamento até ao fim do mês seguinte. O regime extra-União tem destinatários próprios. A dedução do IVA português é exercida pela declaração normal, não abatida diretamente no reporte OSS.

**Base:** [CIVA, art. 6.º-A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva6a.aspx); Lei 47/2020, anexo I, arts. 4.º, 6.º, 11.º, 13.º e 18.º; [regimes oficiais](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/C-IVA-RegEspecial_Lei47_2020.aspx), [Comissão Europeia](https://vat-one-stop-shop.ec.europa.eu/one-stop-shop_en). Isenção EX e OSS exigem classificação por país/operação, não aplicação simultânea à mesma venda.

#### IVA22 IOSS e distinção aduaneira em 2026

**CONFIRMADO.** O IOSS abrange vendas à distância de bens importados em remessas de valor intrínseco **≤150**, excluindo produtos sujeitos a impostos especiais. O vendedor cobra IVA do destino; identificador válido permite o tratamento de importação previsto no regime. Declaração e pagamento **mensais até ao fim do mês seguinte**. A conversão OSS/IOSS usa a taxa BCE do último dia do período ou publicação seguinte.

A alteração aduaneira europeia de **1-7-2026**, com eliminação da franquia aduaneira de 150 e cobrança transitória de 3 por adição nas condições previstas, não elimina por si o limite de 150 do IOSS. Direitos aduaneiros e IVA são parâmetros separados.

**Base:** Lei 47/2020, anexo I, arts. 6.º, n.os 3–4, 19.º, 22.º–23.º e 27.º. [Lei e anexo](https://diariodarepublica.pt/dr/detalhe/lei/47-2020-140950562), [guia IOSS AT](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/Comercio_eletronico/Folhetos_informativos/Documents/IOSS_vendedores.pdf), [aviso AT sobre Regulamento UE 2026/382](https://info.portaldasfinancas.gov.pt/pt/destaques/Paginas/Mercadorias_eCommerce.aspx).

### Dedução apuramento periodicidade e caixa

#### IVA23 Dedução não equivale a despesa de IRS

**CONFIRMADO.** Deduz-se IVA documentado regularmente, em nome do sujeito passivo e relativo a operações que conferem esse direito; não uma percentagem automática de despesas profissionais. Exportações e certas operações externas podem conferir dedução, mas o art. 20.º, n.º 1, al. b), II), exclui agora operações abrangidas pela isenção de pequenas empresas noutro Estado. Autoliquidação exige imposto efetivamente liquidado para dedução. Faturas falsas e utilização alheia à atividade não geram o direito.

**Base:** CIVA, arts. 19.º, n.os 1–4 e 8, e 20.º, n.º 1; alteração DL 35/2025. [Art. 19.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva19.aspx), [art. 20.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva20.aspx).

#### IVA24 Exclusões e exceções de despesas

**CONFIRMADO; redação no corte.** Viaturas de turismo, deslocações, alojamento, refeições, representação e luxo têm exclusões, com exceções específicas. Combustíveis não partilham uma regra única: gasóleo/GPL/gás natural/biocombustíveis podem permitir 50%, ou 100% para categorias legalmente enumeradas; não generalizar à gasolina. Eventos podem permitir 50% ou 25% mediante requisitos distintos.

Há exceções para aquisição/locação/transformação de elétricos e híbridos plug-in até limites legais, GPL/GNV com 50%, e eletricidade de carregamento. Os limites de custo de referência são 62.500, 50.000 e 37.500, respetivamente. Não estender automaticamente essas exceções a toda a manutenção. A alteração de 2025 retirou bicicletas, com ou sem motor, da enumeração de exclusão em causa, com natureza interpretativa.

**Base:** CIVA, art. 21.º, n.º 1, als. a)–e), e n.º 2, als. a)–h); Portaria 467/2010, art. 1.º, n.º 4; Lei 45-A/2024, art. 95.º. [Art. 21.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva21.aspx), [limites de viaturas](https://diariodarepublica.pt/dr/legislacao-consolidada/portaria/2010-894002398).

#### IVA25 Afetação crédito e reembolso

**CONFIRMADO.** Operações mistas exigem afetação real e/ou pro rata nos termos do art. 23.º; utilização não económica não se resolve por simples proporção do volume de negócios. A dedução excedente reporta-se. O art. 22.º permite pedir reembolso, designadamente após 12 meses com crédito superior a 250, ou imediatamente nas hipóteses previstas, incluindo crédito superior a 3.000 e certas cessações/mudanças com pelo menos 25. Pedido de reembolso não significa recebimento automático ou inexistência de fiscalização.

**Base:** CIVA, arts. 22.º, n.os 4–8, e 23.º, n.os 1–8. [Crédito/reembolso](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva22.aspx), [dedução mista](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva23.aspx).

#### IVA26 Periodicidade normal e alteração de 2025

**CONFIRMADO.** Volume anterior **≥650.000: mensal**; inferior: trimestral, salvo opção ou obrigação especial. No início usa-se previsão; períodos incompletos são anualizados para este teste, ao contrário do novo art. 53.º. A opção mensal é exercida no início ou em janeiro. Desde **1-7-2025**, foi removida a antiga permanência de três anos: a opção mantém-se até alteração em janeiro. A passagem obrigatória por volume produz efeitos em 1 de janeiro, com declaração nesse mês. IVA de importação na declaração e inscrição em reembolso mensal são causas especiais de mensalidade.

**Base:** CIVA, art. 41.º, n.os 1–8; DL 49/2025. [Art. 41.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva41.aspx), [DL 49/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/49-2025-912653926). A adição sobre combustíveis que a consolidação já mostra apenas produz efeitos em 2027: IVA30.

#### IVA27 Declaração pagamento e calendário

**CONFIRMADO; padrão em vigor desde 2023.** Declaração normal até dia **20 do segundo mês seguinte** ao mês/trimestre; pagamento até dia **25 do mesmo mês**. Junho e segundo trimestre têm prazos especiais em **20 e 25 de setembro**. Declaração pode ser obrigatória mesmo sem imposto a pagar ou atividade no período. Ato isolado segue pagamento até ao fim do mês seguinte; IVA indevidamente mencionado tem regra própria de 20 dias.

As datas concretas exigem fins de semana, feriados, férias fiscais e despachos. Em 2026, a declaração de junho/segundo trimestre vence em 21 de setembro. Comunicação de faturas, recapitulativa, EX e OSS não herdam automaticamente o calendário da declaração normal.

**Base:** CIVA, arts. 27.º, n.os 1–3 e 10, 29.º, n.º 2, e 41.º, n.os 1 e 10. [Art. 27.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva27.aspx), [calendário declarativo AT 2026](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/Quadro_res_Decl_2026.aspx). A parametrização histórica anterior a 2023 requer a versão respetiva.

#### IVA28 IVA automático

**CONFIRMADO; desde 1-7-2025.** A declaração automática/provisória não cobre todos os contribuintes nem todas as compras. O art. 29.º-A regula confirmação e conversão nas condições legais. A Portaria 242/2025/1 exige, entre outros elementos, residência e classificação das faturas; exclui cenários como importações/exportações, aquisições com autoliquidação e regimes especiais. Ausência de dados comunicados não equivale a inexistência de operações nem garante dedução automática.

**Base:** [CIVA, art. 29.º-A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva29a.aspx), [Portaria 242/2025/1, art. 2.º](https://diariodarepublica.pt/dr/detalhe/portaria/242-2025-919826025).

#### IVA29 Regime de caixa 500 000 para 2 milhões

**CONFIRMADO.** Desde **1-7-2025**, o limite passou de **500.000 para 2.000.000**. Exige atividade registada há pelo menos 12 meses, regularidade fiscal/declarativa, não exercício exclusivo de atividade isenta pelo art. 9.º e, separadamente, não enquadramento no art. 53.º nem no regime de pequenos retalhistas. Abrange certas operações B2B; exclui importações, exportações, operações intracomunitárias, autoliquidação e operações entre relacionados. O volume passou a remeter para o **art. 52.º-B**, por alteração do DL 35/2025.

A exigibilidade acompanha recebimentos, mas não indefinidamente: existe limite de 12 meses após a fatura e eventos de saída/cessação. A dedução das próprias compras acompanha pagamento e recibo nos termos do art. 3.º; o n.º 2 permite a dedução no 12.º mês posterior à emissão da fatura, ou nos eventos de saída previstos, se ainda não houve pagamento. O cliente fora do regime pode deduzir a fatura do fornecedor em caixa nos termos do art. 3.º-A, sem esperar pelo pagamento. Opção em outubro para janeiro; permanência mínima de dois anos civis; séries e menção próprias.

**Base:** DL 71/2013, anexo, arts. 1.º–6.º, incluindo 3.º-A; DL 34/2025, arts. 2.º–3.º; DL 35/2025, art. 5.º. [Regime consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2013-56895610), [novo limite](https://diariodarepublica.pt/dr/detalhe/decreto-lei/34-2025-912066243), [FAQ AT](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/questoes_frequentes/pages/faqs-00105.aspx).

#### IVA30 Leis de 2026 entrada em vigor não é produção de efeitos

**CONFIRMADO.** O DL 97/2026 alargou a autoliquidação de construção em determinados casos de habitação e alterou o procedimento do art. 27.º, n.º 3, com efeitos em **1-7-2026**. Existe opção conjunta específica de retroação a 1-1-2026 para certas operações de construção; não aplicar essa retroação a todas as alterações do diploma.

A **Lei 65/2026, de 5 de novembro**, alarga a al. j) do art. 2.º, n.º 1, a adquirentes sujeitos passivos sem a antiga condição geral de dedução, e obriga vendedores de combustíveis à periodicidade mensal. É lei aprovada, com entrada em vigor em 6-11-2026 e aplicação futura às operações em causa: a **Retificação 39-B/2026/1** corrige o art. 12.º, n.º 1, reportando os efeitos a operações exigíveis no **trimestre seguinte**, portanto **1-1-2027**. O dever transitório do art. 8.º, n.º 2, de apresentar alteração em janeiro de 2027, eficaz em 1 de janeiro, respeita especificamente aos sujeitos abrangidos pelo **art. 75.º-A, n.º 1, do CIVA** que se encontravam na periodicidade trimestral à entrada em vigor da lei. Preservar esta remissão no cadastro, distinguindo-a da regra de periodicidade do art. 41.º, n.º 6. Não aplicar já em novembro o texto mais recente da consolidação AT.

**Base:** [DL 97/2026, arts. 4.º e 18.º, n.os 4 e 7](https://diariodarepublica.pt/dr/detalhe/decreto-lei/97-2026-1124493227), [Lei 65/2026, arts. 2.º, 8.º e 12.º](https://diariodarepublica.pt/dr/detalhe/lei/65-2026-1191346238), [retificação original](https://diariodarepublica.pt/dr/detalhe/declaracao-retificacao/39-b-2026-1192129277).

### Regimes condicionais

#### ESP01 Pequenos retalhistas

**CONFIRMADO quanto aos parâmetros nucleares; NÃO CONFIRMADO para implementação integral.** Art. 60.º: pessoas singulares sem contabilidade organizada, compras anteriores **≤50.000**, pelo menos **90%** de bens para revenda sem transformação; apuramento por **25% do IVA** de determinadas aquisições, com ajustamentos próprios. Há exclusões por importações/exportações, operações intracomunitárias indicadas, serviços tributáveis acima de **250** e anexo E. A flexibilização do art. 53.º não elimina estas exclusões do regime retalhista. Exige módulo próprio de arts. 60.º–68.º, incluindo faturação e pagamento.

**Base:** [CIVA, art. 60.º, n.os 1–9](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva60.aspx), redação DL 35/2025.

#### ESP02 Forfetário agrícola

**CONFIRMADO quanto ao núcleo.** Produtores que preencham o art. 53.º e forneçam produtos/serviços agrícolas dos anexos F/G podem optar. Compensação de **6%** sobre vendas elegíveis do ano anterior, pedido até ao **último dia de março**, com identificação dos adquirentes; não há pagamento inferior a **10**. Não se aplica a quaisquer vendas a consumidores nem equivale à dedução normal de IVA.

**Base:** CIVA, arts. 59.º-A e 59.º-B, n.os 1–6; arts. 59.º-C–59.º-E para opção/obrigações. [Âmbito](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva59a.aspx), [compensação](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva59b.aspx). **NÃO CONFIRMADO para automatização integral:** classificação de todos os produtos e serviços dos anexos e eventos de mudança de regime.

#### ESP03 Margem de usados arte e agências

**CONFIRMADO quanto à existência e necessidade de classificação; NÃO CONFIRMADO para parametrização integral.** Revendedores de usados/arte/coleção podem aplicar margem apenas quando a origem da aquisição satisfaz o DL 199/96; uma compra com IVA normal discriminado não se transforma automaticamente em compra apta a margem. Faturas, dedução e aquisições UE têm tratamento próprio. O OE 2026 altera o art. 15.º desse regime relativamente a objetos de arte.

Agências e operadores que atuem em nome próprio utilizando bens/serviços de terceiros podem cair no DL 221/85, com base na margem e regras próprias para operações fora da UE. O CAE, isoladamente, não decide. Estes regimes não devem ser simulados como simples IVA normal sobre a totalidade da venda.

**Base:** DL 199/96, regime anexo, arts. 1.º–5.º, 7.º, 14.º–17.º; Lei 73-A/2025, art. 78.º; DL 221/85, arts. 1.º–4.º. Fontes: [DL 199/96 na AT](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Decreto_Lei_199_96.pdf), [FAQ AT de regimes especiais](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/questoes_frequentes/Pages/faqs-01007.aspx), [regime das agências consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/1985-224861485), [OE 2026 oficial](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Lei_73_A_2025_OE2026.pdf).

## 4 Faturação e documentos

#### FAT01 Obrigação tipos e prazo de fatura

**CONFIRMADO; prazo do art. 36.º/1 na redação do DL 197/2012 desde 1-1-2013.** Emitir fatura por operação e adiantamento, independentemente de o cliente a pedir, salvo dispensas expressas. Fatura-recibo serve quando realização e pagamento coincidem; recibo documenta pagamento de fatura anterior. O prazo geral é o **quinto dia útil seguinte ao momento em que o imposto é devido nos termos do art. 7.º**. A emissão ocorre no próprio dia do recebimento em adiantamentos e quando o pagamento coincide com aquele momento. Serviços B2B intracomunitários abrangidos têm prazo até dia **15 do mês seguinte**. Isenção de IVA não dispensa automaticamente faturação.

**Base:** CIVA, arts. 29.º, n.º 1, al. b), e n.º 3, 36.º, n.º 1, als. a)–c), e 57.º. [Obrigações](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva29.aspx), [prazos e conteúdo](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva36.aspx).

#### FAT02 Conteúdo e fatura simplificada

**CONFIRMADO.** Fatura normal: data, numeração sequencial, identificação/NIF das partes quando exigidos, natureza/quantidade, preço líquido, taxa e imposto ou motivo legal de não liquidação, e data da operação quando diferente. Taxas distintas devem ficar discriminadas; documentos retificativos referenciam a fatura.

Fatura simplificada é admitida para certas vendas a consumidores até **1.000** e restantes operações até **100**. Desde o DL 35/2025, as operações de sujeitos do art. 53.º abrangidas pelo art. 40.º podem usar simplificada **sem limite de montante**, respeitando a territorialidade e conteúdo. NIF do adquirente empresarial é obrigatório; consumidor pode solicitar o seu NIF. «Simplificada» não significa documento sem motivo de isenção ou sem identificação do emitente.

**Base:** CIVA, arts. 36.º, n.os 5–6 e 13, e 40.º, n.os 1–3. [Art. 36.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva36.aspx), [art. 40.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva40.aspx).

#### FAT03 Portal da AT ou software certificado

**CONFIRMADO.** DL 28/2019, art. 4.º: software certificado quando se verifica qualquer gatilho — volume anterior **>50.000**, utilização de programa informático de faturação ou contabilidade organizada obrigatória/opcional. Para o limite, anualiza-se o período incompleto. Não existe autorização geral para emitir em Word/Excel só por estar abaixo de 50.000.

Desde **1-7-2025**, o art. **4.º-A** permite utilizar aplicações de faturação disponibilizadas pela AT independentemente desses gatilhos. Assim, contabilidade organizada não significa comprar necessariamente software privado se a aplicação AT suportar a emissão. Atos isolados devem ser emitidos pelas aplicações AT. Fora dos casos obrigatórios, subsistem meios legalmente previstos; avaria e documentos em papel têm regras próprias.

**Base:** DL 28/2019, arts. 3.º, 4.º, n.os 1–5, e 4.º-A, aditado pelo DL 49/2025. [Diploma consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2019-119662485), [alteração de 2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/49-2025-912653926).

#### FAT04 Séries ATCUD e QR

**CONFIRMADO.** Comunicação das séries antes de as utilizar; o código de validação da AT, combinado com o número sequencial, forma o ATCUD. ATCUD obrigatório desde **1-1-2023**, incluindo meios em papel abrangidos; QR obrigatório desde **1-1-2022** nos documentos emitidos por programas certificados. São requisitos diferentes. Nas aplicações AT, a gestão respetiva é assegurada pelo sistema; não duplicar comunicação de séries manualmente. A posição e presença nas páginas seguem os requisitos técnicos.

**Base:** DL 28/2019, arts. 7.º, n.º 3, e 35.º; Portaria 195/2020, arts. 2.º–6.º; Despacho 351/2021-XXII. Fontes: [Portaria](https://diariodarepublica.pt/dr/detalhe/portaria/195-2020-140210523), [FAQ oficial ATCUD](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/questoes_frequentes/Pages/faqs-00594.aspx), [despacho de transição](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/Despachos_SEAF/Documents/Despacho_SEAAF_351_2021_XXII.pdf).

#### FAT05 Comunicação mensal e SAFT de faturação

**CONFIRMADO; regra do dia 5 desde 2023.** Elementos das faturas e documentos relevantes devem chegar à AT até **dia 5 do mês seguinte**, por comunicação automática, ficheiro estruturado ou meios admitidos. A obrigação de comunicar ausência de emissão está no art. 3.º, n.º 9. Documentos emitidos pelas aplicações AT já alimentam os sistemas respetivos; evitar duplicação.

O SAF-T de faturação usado na comunicação mensal é distinto do futuro envio anual de SAF-T contabilístico. Em 2026 existiram extensões específicas — por exemplo janeiro, abril e maio — além de deslocações de calendário; não fixar todos os meses no dia 5 sem tabela de exceções.

**Base:** DL 198/2012, art. 3.º, n.os 1–3 e 9. [Diploma consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2012-106458692), [calendário AT 2026](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/Quadro_res_Decl_2026.aspx).

#### FAT06 Correções de documentos

**CONFIRMADO quanto à lei; DOUTRINA quanto ao procedimento detalhado de 2026.** Retificações de valor/imposto exigem documento retificativo nos termos aplicáveis e podem exigir prova de conhecimento/reembolso ao adquirente. O Ofício **25120/2026, de 28 de julho**, distingue estes casos de inexatidões formais, como NIF/morada/descrição, para as quais orienta cancelamento e emissão correta, preservando a data real da operação. Operação inexistente e operação efetiva posteriormente anulada não devem receber automaticamente o mesmo tratamento documental.

**Base:** CIVA, arts. 36.º, n.º 6, e 78.º; [Ofício 25120/2026](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Ofcio-circulado-25120-2026.pdf). **NÃO CONFIRMADO para automatização integral:** prazos e prova de cada regularização de crédito incobrável/duvidoso, que exigem módulo próprio dos arts. 78.º-A e seguintes.

#### FAT07 PDF autenticidade e faturação pública

**CONFIRMADO.** O OE 2026 considera faturas PDF como eletrónicas para efeitos fiscais até **31-12-2026**. O DL 28/2019 exige autenticidade, integridade e legibilidade; o art. 12.º prevê mecanismos de assinatura/selo qualificados e EDI, usando formulação não exaustiva. Não é rigoroso transformar automaticamente o fim da tolerância PDF numa obrigação universal, única e incondicional de assinatura qualificada em todas as faturas a partir de 2027.

Faturação eletrónica estruturada em contratos públicos é obrigação distinta. Grandes empresas já estão abrangidas; segundo a lei aprovada no corte, PME/microfornecedores públicos passam ao calendário de **1-1-2027**. Um PDF fiscalmente aceite não substitui necessariamente a estrutura eletrónica exigida no contrato público.

**Base:** Lei 73-A/2025, art. 95.º, n.º 3, e art. 260.º, n.º 2, que altera DL 111-B/2017, art. 9.º, n.º 4; DL 28/2019, arts. 6.º e 12.º. [OE 2026](https://diariodarepublica.pt/dr/detalhe/lei/73-a-2025-993270096-994025875), [FAQ oficial eSPap](https://www.espap.gov.pt/FrontEnd/Paginas/Areas/SP_Fin/SP_Fin_FEAP_FAQ_tpl_1.aspx?AspxAutoDetectCookieSupport=1).

#### FAT08 SAFT contabilístico e proposta OE 2027

**CONFIRMADO COMO LEI:** o OE 2026, art. 95.º, n.º 2, fixa o envio do SAF-T contabilístico para períodos de **2027 e seguintes**, a entregar em **2028 ou seguintes**. Mantêm-se separadas as obrigações atuais de arquivo, exportação e comunicação da faturação.

**PROPOSTA CONFIRMADA; NÃO CONFIRMADO COMO LEI em 11-11-2026:** a proposta de OE 2027, art. 77.º, n.os 2–3, prevê SAF-T contabilístico para períodos **2028**, entrega **2029**, e PDF até **31-12-2027**. Estes parâmetros só podem existir como cenário legislativo, sem substituir os da lei aprovada. O mesmo cuidado aplica-se a novos adiamentos de faturação pública.

**Fontes:** [OE 2026, art. 95.º](https://diariodarepublica.pt/dr/detalhe/lei/73-a-2025-993270096-994025875), [proposta OE 2027 no Parlamento](https://app.parlamento.pt/OE2027/HTML/OrcamentoEstado2027.html), [proposta publicada no DAR de 8-10-2026](https://debates.parlamento.pt/catalogo/r3/dar/s2a/17/02/015S1/2026-10-08?org=PLC&pgs=2-264&plcdf=true&sft=true).

#### FAT09 Conservação

**CONFIRMADO.** O art. 52.º do CIVA exige conservação durante os **dez anos civis seguintes**; bens de investimento podem exigir prazo contado da última regularização. DL 28/2019 regula integridade, localização, digitalização, arquivo original eletrónico, acessibilidade e cópias. A disponibilidade de documentos nas aplicações AT não dispensa validar o universo documental e as demais obrigações. Não apagar documentos de exercícios anteriores por o programa só mostrar imediatamente os mais recentes.

**Base:** [CIVA, art. 52.º, n.os 1–2](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva52.aspx); DL 28/2019, arts. 19.º–30.º; art. 4.º-A quanto às aplicações AT. [Regras gerais de arquivo](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2019-119662485).

#### FAT10 Bens em circulação

**CONFIRMADO.** O regime de transporte aplica-se à circulação de bens no território nacional, com exclusões próprias. O documento é emitido antes da circulação e, quando exigido, comunicado antes do início. A dispensa de comunicação para volume anterior **≤100.000** não é dispensa geral de documento. Existem regras próprias para a fatura usada como documento de transporte.

Desde o DL 35/2025, o art. 4.º, n.º 10, permite aos prestadores de serviços do art. 53.º usar, nas condições previstas, faturas de aquisição para os bens transportados. Documentos de transporte têm arquivo próprio de quatro anos, sem prejudicar os prazos superiores de conservação fiscal aplicáveis às faturas.

**Base:** DL 147/2003, regime anexo, arts. 1.º, 3.º, 4.º, n.º 10, 5.º, n.os 5–6 e 10–11, e 6.º. [Regime consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2003-114116775).

### Registos após a reforma de 2025

#### FAT11 Registos do CIRS e classificação eletrónica do IVA

**CONFIRMADO.** O CIRS 116.º/1/a remete para as alíneas a), b) e c) do CIVA 50.º/1. Desde **01-07-2025**, este último deixou de exigir a antiga estrutura de livros para as novas operações e passou a prever classificação de faturas diretamente no Portal das Finanças, distinguindo operações ativas, operações passivas com separação de inventários/despesas gerais, e operações sobre bens de investimento. O prazo IVA é o do artigo 45.º: até à apresentação tempestiva da DP ou, se não entregue tempestivamente, até ao termo do prazo legal.

Subsistem no CIRS o registo separado de reembolsos devidamente documentados em nome/por conta do cliente, a conta corrente de provisões para despesas do cliente, suporte documental e prazo de 60 dias para os lançamentos referidos no art. 116.º/4. Agricultura, silvicultura e pecuária têm ainda movimentos de produtos/gado/materiais e imobilizações, com alternativas de escrita admitidas nos n.os 2–3. Estes elementos não devem desaparecer do modelo só por existir classificação e-Fatura.

Dispensas do art. 116.º/5: atos isolados referidos nas alíneas h)/i) do art. 3.º/2; e, cumulativamente, enquadramento no art. 53.º do CIVA, ausência de contabilidade organizada obrigatória ou efetiva e emissão exclusiva através das aplicações AT. A alteração da alínea b) pelo DL 35/2025 vigora desde 29-03-2025; não se deve confundir essa data com a reforma de julho dos registos IVA. O n.º 6 admite sistema contabilístico adequado alternativo. A dispensa não abrange automaticamente todo o regime simplificado de IRS.

**Base e vigência:** CIRS 116.º/1–6, incluindo alteração do DL 35/2025; CIVA 50.º/1–2, na redação do DL 49/2025, arts. 4.º e 18.º/1–2, aplicável a operações ativas/passivas desde 01-07-2025. O art. 16.º do DL 49/2025 mantém a conservação dos livros anteriores até ao fim do prazo legal. **NÃO CONFIRMADO para automatização integral:** harmonização operacional do prazo IRS de 60 dias com a classificação IVA por remissão, sobretudo em perfis dispensados de DP mas não do art. 116.º; conservar ambos os fundamentos e validar o caso.

Fontes: [CIRS 116.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs116.aspx), [CIVA 50.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva50.aspx), [CIVA 45.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva45.aspx), [DL 49/2025](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Decreto_Lei_49_2025.pdf).

## 5 Segurança Social

#### SS001 Trabalhador independente abrangido

**CONFIRMADO.** Pessoa singular com atividade profissional sem contrato de trabalho ou contrato legalmente equiparado, incluindo quem se obriga a fornecer o resultado da sua atividade, desde que essa atividade não esteja abrangida pelo regime geral dos trabalhadores por conta de outrem. Inclui atividades profissionais/empresariais geradoras de rendimentos dos arts. 3.º e 4.º do CIRS, sócios de sociedades de profissionais, certas atividades agrícolas e cônjuges/unidos de facto que colaborem regular e permanentemente. Um único enquadramento cobre várias atividades autónomas simultâneas.

**Base/vigência:** CRC arts. 132.º e 133.º; RCRC art. 54.º. Regime desde 2011; extensão expressa a unidos de facto na redação de 2014. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

#### SS002 ENI EIRL não é sinónimo de qualquer titular de CAE

**CONFIRMADO** quanto à norma; **NÃO CONFIRMADO** qualquer automatismo «tem CAE → taxa de ENI». O CRC abrange especialmente ENI com rendimentos de atividade comercial ou industrial nos termos do CIRS art. 3.º/1/a e titulares de EIRL, além dos respetivos cônjuges colaboradores. A lei distingue esta categoria das prestações profissionais do CIRS art. 3.º/1/b. O código CAE, isoladamente, não substitui os factos da atividade, a forma de exercício e o enquadramento reconhecido pelo ISS.

**Base/vigência:** CRC art. 134.º/1/b, redação da Lei n.º 66-B/2012 desde 01-01-2013; RCRC arts. 54.º-A/2 e 54.º-B. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [CIRS 3.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx), [CIRS 4.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs4.aspx).

#### SS003 Taxas próprias

**CONFIRMADO.** Taxa geral dos TI: **21,4%**. ENI/EIRL e cônjuges: **25,2%**. A contabilidade organizada não altera, por si, a taxa. O art. 54.º-A/2 exige declaração da forma de exercício, no mês da alteração, aos ENI/EIRL com atividade exclusivamente industrial ou comercial; pelo art. 54.º-B, a taxa especial produz efeitos no mês da declaração e deixa de se aplicar no mês seguinte à declaração de mudança. Atividades mistas devem ser confirmadas com o ISS, sem escolher automaticamente a taxa pela atividade principal fiscal. A natureza de prestação de serviços também não determina, sozinha, a taxa de 21,4%: construção, transportes e outras atividades podem ser comerciais ou industriais nos termos do CIRS art. 4.º. Separar a classificação pessoal para a taxa da classificação de cada receita para os coeficientes de rendimento relevante.

**Base/vigência:** CRC art. 168.º/1 e /4; DL n.º 2/2018, art. 8.º/1, efeitos desde 01-01-2019. **Fontes:** [art. 168.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114488784), [DL 2/2018](https://diariodarepublica.pt/dr/detalhe/decreto-lei/2-2018-114484243), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [CIRS 4.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs4.aspx).

#### SS004 Principais exclusões do regime

**CONFIRMADO.** Excluem-se: advogados/solicitadores obrigatoriamente inscritos na respetiva caixa; atividade temporária em Portugal com proteção obrigatória estrangeira que cubra pelo menos invalidez, velhice e morte; proprietários de embarcações e determinados pescadores, submetidos a regime próprio; explorações predominantemente para autoconsumo com rendimentos anuais **≤4 IAS**; agricultores com subsídios PAC anuais **<4 IAS** sem outros rendimentos determinantes de enquadramento; titulares de categoria B **exclusivamente** de produção elétrica para autoconsumo/pequena produção renovável ou arrendamento/alojamento local em moradia/apartamento. Alojamento na modalidade de estabelecimento de hospedagem não beneficia desta última exclusão só por ser AL.

**Base/vigência:** CRC art. 139.º/1 a–g e /2–3, redação aplicável desde 01-01-2019. **Fontes:** [Título II consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-43555875), [Guia ISS 2026](https://www.seg-social.pt/storage1/files/1009---Novo-Regime-dos-Trabalhadores-Independentes-v1-09-8TvHiLaPC6okosI9oUn9_A.pdf).

#### SS005 Exclusão precisa de estar reconhecida

**CONFIRMADO.** Quando o ISS desconheça os factos da exclusão, o trabalhador deve requerê-la. A cessação dos factos que justificam a exclusão deve ser comunicada até ao final do mês em que ocorre. Ausência de rendimento, suspensão fiscal, isenção de contribuir e exclusão de enquadramento são situações diferentes e devem ter estados distintos.

**Base/vigência:** RCRC art. 54.º-C/1–2, aditado pelo DR n.º 6/2018, efeitos 01-01-2019. **Fonte:** [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

#### SS006 Serviços à própria entidade empregadora ou grupo

**CONFIRMADO.** A acumulação de trabalho dependente e independente para a mesma entidade empregadora ou empresa do mesmo agrupamento é enquadrada no regime geral com as especificidades dos arts. 129.º–131.º: os honorários ilíquidos integram a base e aplica-se a taxa do contrato de trabalho. Esses honorários não devem voltar a integrar o RR trimestral do TI. Se existem também clientes distintos, apenas a atividade para estes segue o regime TI.

**Base/vigência:** CRC arts. 129.º–131.º; redação do art. 129.º desde 01-01-2014. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575); [FAQ ISS 2026](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), perguntas 37–40, para tratamento declarativo.

### Inscrição primeiro enquadramento reinício e cessação

#### SS010 Comunicação AT ISS

**CONFIRMADO.** A AT comunica eletronicamente o início de atividade e elementos de identificação, incluindo NIF. O ISS identifica/atualiza, inscreve quando necessário, enquadra e notifica os efeitos. O enquadramento existe mesmo quando a pessoa reúne condições de isenção contributiva. Se os dados forem insuficientes, o ISS pode pedir prova; não assumir que uma abertura fiscal dispensou toda a verificação contributiva.

**Base/vigência:** CRC arts. 143.º, 144.º e 149.º; regime em vigor desde 2011. **Fonte:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575).

#### SS011 Data exata do primeiro enquadramento

**CONFIRMADO.** O primeiro enquadramento produz efeitos no **primeiro dia do 12.º mês posterior ao mês de início**. Exemplo: abertura a 10-01-2025 → efeitos a 01-01-2026. Não é uma contagem diária de 365 dias nem «primeiro dia do 13.º mês posterior». Também não é tecnicamente a isenção do art. 157.º: trata-se de diferimento inicial da produção de efeitos.

**Exceção:** o cônjuge ou unido de facto colaborador segue o art. 145.º/6–7: efeitos no mês seguinte ao requerimento, dependentes da produção de efeitos do enquadramento do TI principal. Não atribuir automaticamente um novo diferimento de 12 meses ao colaborador; ver SS036.

**Base/vigência:** CRC art. 145.º/1, 6–7, redação do DL n.º 2/2018 com efeitos em 01-01-2019 para o diferimento inicial. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [DL 2/2018](https://diariodarepublica.pt/dr/detalhe/decreto-lei/2-2018-114484243); confirmação explícita dos exemplos em [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), pergunta 1.

#### SS012 Cessação e reinício

**CONFIRMADO.** Reinício depois de o enquadramento ter produzido efeitos: obrigação desde o primeiro dia do mês do reinício. Se a atividade cessou durante os primeiros 12 meses, a contagem suspende-se e continua desde o primeiro dia do mês de reinício **quando este ocorre nos 12 meses posteriores à cessação**. Exemplo administrativo: início 16-03-2025, cessação 31-08-2025, reinício 15-04-2026 → efeitos 01-10-2026. Não atribuir automaticamente um novo período inicial completo a cada reabertura.

**Base/vigência:** CRC art. 145.º/3–4; mecanismo preexistente mantido no regime de 2019. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), pergunta 3. **NÃO CONFIRMADO:** cálculo automático dos reinícios fora daquela janela quando o primeiro enquadramento nunca chegou a produzir efeitos. A leitura conjugada aponta para a regra geral do art. 145.º/3 — efeitos no mês do reinício — porque a exceção do n.º 4 exige reinício nos 12 meses seguintes; esta é uma interpretação, não um procedimento específico confirmado pela FAQ para o caso. Não conceder novo diferimento por presunção; registar a decisão e a data comunicadas pelo ISS.

#### SS013 Antecipação voluntária

**CONFIRMADO.** Pode pedir-se antecipação nos momentos declarativos de janeiro, abril, julho ou outubro. Pela lei, efeitos no **primeiro dia do mês seguinte ao requerimento**. Não é permitida antecipação por DQ fora de prazo, segundo o ISS. O guia principal de janeiro de 2026 contém, na mesma página, uma passagem contraditória que faz retroagir ao primeiro dia do próprio mês; a FAQ de 14-01-2026 e o art. 146.º/2 confirmam o mês seguinte. Prevalece a norma; eventual data diferente num caso concreto deve ser suportada pela decisão ISS.

**Base/vigência:** CRC art. 146.º/1–2; RCRC art. 57.º-A, efeitos 01-01-2019. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), pergunta 2.

#### SS014 Fim de atividade e meses ainda devidos

**CONFIRMADO.** Cessação do enquadramento e da obrigação contributiva no primeiro dia do mês seguinte ao da cessação. A comunicação fiscal é transmitida ao ISS. O mês de cessação continua, em regra, devido; permanecem declarações e eventuais diferenças de revisão anual. Exemplo: cessar em 14 de maio não elimina a contribuição de maio, paga em junho.

**Base/vigência:** CRC arts. 147.º, 148.º e 161.º; ressalva da revisão anual desde 01-01-2019. **Fonte:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575).

#### SS015 Início reinício até à primeira declaração

**CONFIRMADO.** Até à primeira DQ, fixa-se a base correspondente à contribuição mínima do art. 163.º/2, salvo se já estiver fixada uma base aplicável ao período. O ISS publica o valor operacional de 20 €; a divergência de indexação está identificada em SS031. O mínimo não significa base de incidência de 20 €. As situações de isenção/exclusão têm de ser verificadas antes de liquidar esse mínimo.

**Base/vigência:** CRC art. 165.º/1–2 por remissão ao art. 163.º/2; efeitos 01-01-2019. **Fonte:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575).

### Rendimentos relevantes e sua classificação

#### SS020 Fórmula trimestral

**CONFIRMADO.** O RR resulta de **70% das prestações de serviços +20% da produção/venda de bens**, relativos aos três meses anteriores ao mês declarativo. Hotelaria e similares, restauração e bebidas têm coeficiente **20%**, desde que declaradas fiscalmente como tal. Cada parcela deve conservar a sua natureza; não aplicar o coeficiente de IRS do regime simplificado para calcular Segurança Social.

**Base/vigência:** CRC art. 162.º/1/a–b e /2, modelo trimestral com efeitos 01-01-2019. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [DL 2/2018](https://diariodarepublica.pt/dr/detalhe/decreto-lei/2-2018-114484243).

#### SS021 IVA momento declarativo e retenções

**DOUTRINA** quanto ao preenchimento. A DQ não inclui IVA. A FAQ ISS, pergunta 18, remete o momento dos rendimentos para a prestação e para a forma de declaração em IRS; a pergunta 19 exclui expressamente IVA. O CIRS art. 3.º/6 liga, em regra, a tributação à obrigação de faturação e, quando esta não exista, ao pagamento/disponibilização. Não escolher universalmente a data do recibo ou do crédito bancário. O valor de serviços é bruto de retenção de IRS; esta não reduz o rendimento da atividade. Comissões de plataformas não são deduções próprias da fórmula 70%/20%; esclarecer se a plataforma atua por conta do prestador ou em nome próprio.

**Base/vigência:** CRC arts. 151.º-A e 162.º/1; CIRS art. 3.º/6. **Fontes:** [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), perguntas 18–19; [CIRS 3.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx).

#### SS022 Rendimentos excluídos do RR

**CONFIRMADO.** Excluem-se os rendimentos de: produção elétrica para autoconsumo/pequena produção renovável; contratos de arrendamento e arrendamento urbano para AL em moradia/apartamento; subvenções/subsídios ao investimento; mais-valias; propriedade intelectual ou industrial. Nos três últimos grupos, o trabalhador **pode optar** por incluí-los, considerando-os na atividade que lhes deu origem. A opção não está prevista para os dois primeiros. Uma exclusão de rendimento pode existir mesmo que outras receitas mantenham a pessoa enquadrada.

**Base/vigência:** CRC art. 162.º/4; RCRC art. 62.º/3/a–e, /4 e /5, efeitos 01-01-2019 na redação atual. **Fonte:** [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

#### SS023 Subsídios de exploração e apoios

**DOUTRINA.** O ISS manda declarar os subsídios **à exploração** em campo próprio e considera **20%**, mesmo quando sejam a única receita. Não confundir com subsídios ao investimento, excluídos por defeito. O nome informal «apoio», «bolsa», «patrocínio» ou «donativo» não determina sozinho o enquadramento. Deve guardar-se o programa, ato/contrato de concessão, finalidade e classificação fiscal. A FAQ distingue os dois tipos e, para investimento voluntariamente incluído, relaciona os valores com os declarados em IRS.

**Base/vigência:** CRC art. 151.º-A/2 e art. 162.º; RCRC art. 62.º. Operação confirmada nas FAQs de 2025 e 2026; regime desde 2019. **Fonte:** [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), perguntas 25–26.

#### SS024 Recebimentos através de plataformas

**NÃO CONFIRMADO** um tratamento contributivo universal. Não foi localizada orientação oficial específica que permita transformar qualquer recebimento através de plataforma em serviço a 70%, donativo excluído ou direito de autor. A aplicação deve pedir natureza da receita, contrapartida, beneficiário contratual e eventual licença; permitir validação documentada. Uma subscrição remuneratória, apoio inteiramente gratuito e exploração de direitos podem produzir classificações diferentes. Ser pago por plataforma estrangeira não exclui, só por si, atividade abrangida em Portugal.

**Fundamento geral, sem qualificação automática:** CRC arts. 132.º, 133.º e 162.º; RCRC art. 62.º; CIRS art. 3.º. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [CIRS 3.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs3.aspx).

#### SS025 IRS Jovem não reduz a base contributiva

**DOUTRINA — conclusão jurídica conjugada.** O IRS Jovem é uma isenção parcial **de IRS** do CIRS art. 12.º-B, não uma exclusão contributiva do CRC. No modelo trimestral, usa-se o valor da atividade antes dessa isenção, aplicando os coeficientes contributivos normais. No modelo CO, usa-se o lucro tributável relevante comunicado para o regime; não multiplicar novamente pela percentagem tributada em IRS Jovem. Guardar a base contributiva separada da parte de rendimento sujeita a IRS.

**Base/vigência:** CRC art. 162.º; CIRS art. 12.º-B, redação da Lei n.º 45-A/2024 desde 01-01-2025 para o atual IRS Jovem. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [AT — IRS Jovem](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12b.aspx).

#### SS026 Transparência fiscal

**CONFIRMADO.** Para membros/sócios de sociedades de profissionais abrangidos pelo art. 133.º/1/b, a matéria coletável imputada, recebimentos e adiantamentos por conta são considerados prestação de serviços. Exige-se reconciliação para evitar contar duas vezes o mesmo valor em imputação e adiantamento.

**Base/vigência:** RCRC art. 62.º/2; redação de 2019. **Fonte:** [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

### Base de incidência limites e opções

#### SS030 Base mensal trimestral

**CONFIRMADO.** monthlyBase = quarterlyRelevantIncome / 3; efeitos no mês declarativo e nos dois seguintes. Janeiro usa outubro–dezembro para janeiro–março; abril usa janeiro–março para abril–junho; julho usa abril–junho para julho–setembro; outubro usa julho–setembro para outubro–dezembro. Não deslocar o trimestre por uma prorrogação legal da data de submissão para o início do mês seguinte.

**Base/vigência:** CRC art. 163.º/1; efeitos 01-01-2019. **Fonte:** [art. 163.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114431452).

#### SS031 Mínimo mensal de contribuição

**CONFIRMADO quanto à redação legal; DOUTRINA quanto ao valor operacional publicado pelo ISS.** O art. 163.º/2 estabelece o mínimo nominal de contribuição de **20 €**, por ausência de rendimentos ou cálculo inferior a esse valor, e o n.º 8 determina a sua atualização de acordo com o IAS. Os guias ISS de 2026 continuam a aplicar **20 €**. A base correspondente a esse valor operacional é 20/taxa: cerca de 93,46 € à taxa de 21,4%, ou 79,37 € à taxa de 25,2%. Não guardar 20 € como BIC. O mínimo geral não se aplica ao remanescente por acumulação.

**NÃO CONFIRMADO:** fundamento para a manutenção nominal dos 20 € apesar da indexação. Não foi localizada norma que suspenda especificamente o art. 163.º/8; a ausência de resultado na pesquisa não prova a inexistência de tal ato. O Despacho n.º 599/2019 não fixa nem congela este mínimo geral: regula a contribuição residual de 5 € na acumulação e a BIC mínima de 20 € na revisão anual. Conservar o valor operacional de 20 € com a ressalva documentada; não apresentar a indexação como revogada nem gerar autonomamente um novo mínimo sem confirmação do ano-base, primeira atualização e arredondamentos.

**Base/vigência:** CRC art. 163.º/2 e /8, redação do DL n.º 2/2018 com efeitos em 01-01-2019; o n.º 8 mantém-se no texto consolidado. **Fontes:** [art. 163.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114431452), [DL 2/2018](https://diariodarepublica.pt/dr/detalhe/decreto-lei/2-2018-114484243), [Guia ISS](https://www.seg-social.pt/storage1/files/1009---Novo-Regime-dos-Trabalhadores-Independentes-v1-09-8TvHiLaPC6okosI9oUn9_A.pdf), secções F 3/G; [Despacho 599/2019](https://files.diariodarepublica.pt/2s/2019/01/008000000/0152401524.pdf), n.os 1–3.

#### SS032 Ajuste de até 25 por cento

**CONFIRMADO.** Na DQ, o trabalhador pode escolher um rendimento entre −25% e +25%, em passos de 5%, sujeito aos limites aplicáveis. Trata-se de opção, que deve ser registada por declaração/trimestre, não presumida. Não está disponível para quem paga exclusivamente pelo lucro tributável CO, nem para o cálculo do remanescente por acumulação do art. 163.º/4. Os dados originais declarados devem manter-se separados do rendimento escolhido.

**Base/vigência:** CRC art. 164.º/1–2 e art. 163.º/4; DL n.º 2/2018 e Lei n.º 71/2018, efeitos 01-01-2019. **Fontes:** [art. 163.º e seguintes](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114431452), [Lei 71/2018](https://diariodarepublica.pt/dr/detalhe/lei/71-2018-117537583).

#### SS033 Teto de base

**CONFIRMADO.** A BIC mensal tem limite de **12 IAS**. O teto limita a base, não o valor da contribuição. Em 2026: 6 445,56 € de base; contribuição máxima aritmética de 1 379,35 € a 21,4%, ou 1 624,28 € a 25,2%. Aplicar o teto à base final relevante, incluindo quando esta é o excedente por acumulação; não subtrair 4 IAS de uma base previamente truncada a 12 IAS.

**Base/vigência:** CRC art. 163.º/5, modelo atual desde 2019; IAS anual. **Fontes:** [art. 163.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114431452), [IAS 2026](https://diariodarepublica.pt/dr/detalhe/portaria/480-a-2025-993056222). Última frase é interpretação conjunta dos n.os 4 e 5.

#### SS034 Contabilidade organizada lucro mínimo e calendário

**CONFIRMADO.** RR corresponde ao lucro tributável; BIC mensal é o duodécimo desse lucro, limitada inferiormente a **1,5 IAS** e superiormente a **12 IAS**. A base é fixada em outubro para o ano civil seguinte. Na sequência administrativa normal, contribuições do ano N refletem lucro do ano N−2, declarado fiscalmente em N−1 e fixado em outubro de N−1. Não confundir «ano anterior ao apuramento» com ano anterior ao pagamento. Prejuízo/ausência de lucro não elimina, por si, o mínimo CO.

**Base/vigência:** CRC art. 162.º/3 e art. 163.º/3 e /5; desde 01-01-2019. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [art. 163.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114431452), [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), perguntas 23, 27 e 42.

#### SS035 Transições de regime contributivo

**CONFIRMADO/DOUTRINA.** O trabalhador CO pode optar pelo apuramento trimestral no prazo da notificação; o ISS fixa operacionalmente **1–30 de novembro**, para DQ a partir de janeiro. Esta opção não muda o regime fiscal de IRS. No início/reinício CO, o modelo trimestral aplica-se até o ISS conhecer a declaração fiscal respetiva. Uma mudança fiscal simplificado↔CO não deve alterar retroativamente as contribuições na data de início fiscal. A FAQ documenta atrasos de transmissão e fixação anual.

**Base/vigência:** CRC art. 164.º/3; RCRC arts. 57.º-C e 57.º-D, desde 2019. **Fontes:** [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), perguntas 20–23/42; [notícia ISS de 06-11-2026](https://www.seg-social.pt/ptss/pssd/noticias/opcao-mudanca-regime).

#### SS036 Cônjuges unidos de facto colaboradores

**CONFIRMADO.** A base do colaborador enquadrado só nessa qualidade assenta em 70% do RR do TI, com os mínimos próprios do art. 163.º. Pode pedir redução até 20% do valor que lhe foi aplicado ou subida até ao RR do TI. Aplica-se a mesma taxa do TI. Opção trimestral ou anual segundo o regime. Se o TI fica isento, o colaborador não fica automaticamente isento: mantém-se o último RR; sem RR nos últimos 12 meses, considera-se 1,5 IAS, sem prejuízo da opção e limites legais.

**Base/vigência:** CRC art. 166.º; RCRC art. 64.º, desde 2019. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

### Isenções e interrupções da obrigação

#### SS040 Acumulação três condições e limiar estrito

**CONFIRMADO.** Isenção relativamente ao RR mensal médio **inferior a 4 IAS**, apurado trimestral ou anualmente, se cumulativamente: atividade independente e dependente para entidades distintas sem relação de domínio/grupo; enquadramento obrigatório pelo trabalho dependente em regime que cubra todas as eventualidades TI; remuneração média mensal nesse outro regime **≥1 IAS**. O teste é sobre RR, não sobre faturação mensal ou sobre rendimento líquido recebido.

**Base/vigência:** CRC art. 157.º/1/a/i–iii, redação desde 01-01-2019. **Fonte:** [art. 157.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-117658277).

#### SS041 Média salarial e CGA

**CONFIRMADO.** A remuneração dependente é a média do trimestre anterior à verificação das condições. No regime geral, o ISS verifica os registos; noutro regime, exige-se comprovativo com o requerimento. A CGA pode satisfazer esta condição; não a excluir apenas por não ser contribuição para o ISS. É necessário confirmar a proteção e apresentar a prova exigida. Se faltam dados, existe notificação para os apresentar em 10 dias, prorrogáveis por pedido fundamentado.

**Base/vigência:** RCRC art. 59.º/2/a–b e /3, desde 2019. **Fontes:** [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [Guia ISS](https://www.seg-social.pt/storage1/files/1009---Novo-Regime-dos-Trabalhadores-Independentes-v1-09-8TvHiLaPC6okosI9oUn9_A.pdf), E 1.

#### SS042 Exatamente 4 IAS excedente e mínimo de 5 euros

**CONFIRMADO quanto ao apuramento contributivo.** Para RR mensal **≥4 IAS**, cumpridas as condições pessoais, monthlyBase = max(0, monthlyRelevantIncome - 4 * ias), sujeita ao teto. Exatamente 4 IAS cai no regime do remanescente com base zero: não alterar o operador da isenção legal de < para ≤. Não há ajuste ±25%. Não releva base que origine contribuição **<5 €**; a exatamente 5 €, a norma já não exclui. Não impor o mínimo geral de 20 €. Exemplo 2026: RR de 2 180 € → excedente de 31,48 € → contribuição de 6,74 € a 21,4%.

**DOUTRINA — interpretação conjugada:** a obrigação de DQ na igualdade exata de 4 IAS resulta da leitura dos arts. 151.º-A/1, 157.º/1/a e 163.º/4; não é uma instrução expressa de preenchimento identificada para esse caso. O RCRC art. 59.º/1 emprega «ultrapasse» e algumas FAQs usam «superior». Conservar a distinção entre contribuição zero e isenção reconhecida, assinalando a necessidade de confirmação operacional do ISS na igualdade.

**NÃO CONFIRMADO:** ordem do teste de 5 € face ao arredondamento. Em 2026, RR mensal de 2 171,88 € dá remanescente de 23,36 € e contribuição matemática de **4,99904 €** a 21,4%. Não decidir automaticamente entre exclusão por valor inferior a 5 € e contribuição de 5 € após arredondamento sem regra operacional confirmada. Os casos de contribuição matemática exatamente 4,99 € e exatamente 5 € distinguem-se sem essa dúvida.

**Base/vigência:** CRC art. 163.º/4–5; RCRC art. 59.º/4–5; Despacho n.º 599/2019, n.º 1, efeitos em 01-01-2019. **Fontes:** [art. 163.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114431452), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [Despacho 599](https://files.diariodarepublica.pt/2s/2019/01/008000000/0152401524.pdf), [art. 151.º-A](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-105630590).

#### SS043 Pensões

**CONFIRMADO.** Isenção para pensionistas de invalidez/velhice de regime nacional ou estrangeiro, desde que o exercício profissional seja legalmente cumulável com a pensão. Separadamente, isenção para pensão por risco profissional com incapacidade para o trabalho **≥70%**. A incapacidade de 70% não é condição cumulativa para toda a pensão de velhice/invalidez.

**Base/vigência:** CRC art. 157.º/1/b–c, regime desde 2011; efeitos da isenção no RCRC art. 60.º/3–4. **Fontes:** [art. 157.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-117658277), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

#### SS044 Ano anterior sujeito ao mínimo

**CONFIRMADO.** Em janeiro do ano seguinte verifica-se se, no ano anterior, a obrigação resultou do art. 163.º/2, por ausência de rendimento ou cálculo inferior ao mínimo aplicável — 20 € nos documentos operacionais do ISS, com a ressalva de indexação de SS031. A isenção mantém-se enquanto persistirem essas condições e é reconhecida oficiosamente quando conhecidas. **Não é renunciável**, ao contrário de outras isenções. Ter transferido exatamente 20 € não prova, sozinho, que houve enquadramento no mínimo legal: pode ter existido uma contribuição normal de igual montante ou uma declaração omissa.

**Base/vigência:** CRC art. 157.º/1/d e /2; RCRC art. 61.º/3; desde 2019. **Fontes:** [art. 157.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-117658277), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), perguntas 47–48. **NÃO CONFIRMADO:** algoritmo de concessão para um ano apenas parcialmente abrangido; a FAQ 47 pergunta sobre o ano inteiro, mas a resposta repete a lei sem esclarecer inequivocamente quantos meses são exigidos. Não inventar uma exigência de 12 meses pagos nem conceder automaticamente a isenção após um único mês no mínimo. Exemplo a validar: enquadramento eficaz em outubro, mínimo de outubro a dezembro e verificação em janeiro seguinte. Usar o reconhecimento ISS e conservar o histórico mensal, o fundamento de cada base e as declarações.

#### SS045 Início e cessação de isenções

**CONFIRMADO.** Reconhecimento oficioso produz efeitos no mês seguinte ao facto; quando dependa de requerimento, no mês seguinte ao pedido. Para pensionistas, efeitos desde a atribuição da pensão. Cessadas as condições, deve comunicar-se ao ISS, salvo conhecimento oficioso, e volta a existir obrigação desde o mês seguinte. Ao ultrapassar o limiar de RR, declara-se a totalidade dos rendimentos na DQ imediatamente posterior. A renúncia voluntária permitida faz-se no momento declarativo e produz efeitos no próprio mês do pedido.

**Base/vigência:** CRC art. 158.º; RCRC arts. 59.º/1, 60.º e 61.º/1–2; redação atual desde 2019. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

#### SS046 Doença parentalidade e suspensão

**CONFIRMADO.** Não há obrigação nos períodos legalmente abrangidos de incapacidade/indisponibilidade por parentalidade, mesmo sem subsídio, e de doença depois do período de espera ou desde o início quando esse período não seja exigido. Pode requerer-se suspensão justificada; não basta não faturar e não há suspensão se a atividade continua através de empregado ou cônjuge colaborador. Quando só parte do mês não está abrangida por equivalência, a contribuição é proporcional a dias, com valor diário de 1/30.

**Base/vigência:** CRC arts. 156.º, 159.º/1–2 e 160.º; redação da doença desde 2019. **Fonte:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575). Não implementar todos os prazos de prestações sociais apenas com este módulo.

### Declarações trimestrais janeiro e Anexo SS

#### SS050 Obrigação e conteúdo da DQ

**CONFIRMADO.** TI sujeitos à obrigação contributiva declaram vendas/produção, serviços e outros elementos necessários ao RR, incluindo meses sem rendimento. O formulário discrimina as categorias para aplicar coeficientes e exclusões. Entrega até ao último dia de **janeiro, abril, julho e outubro**, relativamente aos três meses anteriores. A submissão é eletrónica e vale a data de submissão com sucesso; guardar comprovativo e versão.

**Base/vigência:** CRC art. 151.º-A/1–3; RCRC art. 57.º-B/1; desde 2019. **Fontes:** [art. 151.º-A](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-105630590), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

#### SS051 Dispensas e primeira declaração

**CONFIRMADO/DOUTRINA.** Quem é isento de contribuir não tem a obrigação trimestral geral enquanto se mantiver a isenção; os excluídos não estão no regime. CO com RR pelo lucro tributável fica fora do art. 151.º-A; CO que optou pelo trimestre entrega DQ. O ISS esclarece: enquadramento inicial com efeitos em março → primeira DQ em abril, com rendimentos de março. Não carregar automaticamente no cálculo inicial receitas anteriores à produção de efeitos.

**Base/vigência:** CRC art. 151.º-A/1 e /7; art. 164.º/3; desde 2019. **Fontes:** [art. 151.º-A](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-105630590), [FAQ ISS](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), perguntas 9, 17 e 20.

#### SS052 Correções feriados e atraso

**CONFIRMADO.** Pode substituir a declaração no mês próprio, prevalecendo a última, e até ao 15.º dia posterior ao termo do prazo legal. Se o prazo declarativo termina em sábado, domingo ou feriado, passa ao primeiro dia útil seguinte. Em 2026, os prazos gerais trimestrais resultam em **02-02, 30-04, 31-07 e 02-11**, salvo prorrogação específica. **DOUTRINA:** o portal permite DQ tardia até ao último dia anterior ao próximo período declarativo; mantém marcação de atraso e não permite antecipar enquadramento.

**Base/vigência:** RCRC art. 57.º-B/2–5, desde 2019; funcionalidade tardia documentada no guia 2026. **Fontes:** [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [Guia ISS](https://www.seg-social.pt/storage1/files/1009---Novo-Regime-dos-Trabalhadores-Independentes-v1-09-8TvHiLaPC6okosI9oUn9_A.pdf), E 2. Os quatro dias indicados são cálculo de calendário sobre a norma.

#### SS053 Cessação não apaga deveres declarativos

**CONFIRMADO.** Quem cessa/suspende atividade entrega a DQ no momento declarativo imediatamente seguinte. A obrigação anual de confirmação pode subsistir. Não gerar contribuições correntes para meses posteriores à cessação só porque existe DQ a apresentar; distinguir apuramento declarativo, dívida histórica e obrigação corrente.

**Base/vigência:** CRC art. 151.º-A/4–5 e art. 161.º; desde 2019. **Fonte:** [art. 151.º-A](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-105630590).

#### SS054 Confirmação anual de janeiro

**CONFIRMADO.** Em janeiro confirmam-se ou declaram-se os valores do ano civil anterior, mesmo que entretanto já não exista obrigação contributiva. O RCRC restringe este dever a quem tenha estado **obrigado a entregar pelo menos uma DQ relativa a rendimentos desse ano**, não apenas a quem efetivamente a entregou. Exceções legais: pensionistas nas alíneas b/c do art. 157.º/1 e CO cujo RR é lucro tributável. Janeiro contém duas operações diferentes: DQ de outubro–dezembro e confirmação/correção do ano anterior.

**Base/vigência:** CRC art. 151.º-A/5–7; RCRC art. 57.º-B/4; desde 2019. **Fontes:** [art. 151.º-A](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-105630590), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826).

#### SS055 Declaração anual de atividade Anexo SS

**CONFIRMADO.** Separada da confirmação de janeiro, a declaração anual do art. 152.º identifica vendas, serviços a particulares e serviços por pessoa coletiva/singular com atividade empresarial, além de dados não obtidos por interconexão. Faz-se pelo **Anexo SS do Modelo 3**, no prazo legal do IRS. Para cada adquirente relevante: NISS, NIF e serviços anuais, sem prejuízo das especificações do formulário. O antigo art. 153.º, que exigia declaração de serviços adquiridos às entidades contratantes, está revogado desde 2011.

**Três decisões distintas:** dispensa de DQ; obrigação de entregar dados no Anexo SS; identificação de adquirentes no quadro 6. As instruções da Portaria n.º 249/2021 mandam assinalar «Não» no quadro 6 para os isentos do art. 157.º. Isso não constitui uma dispensa universal de todo o anexo. Analisar os elementos complementares previstos no RCRC art. 54.º-A/1/b e art. 58.º separadamente da obrigação trimestral.

**NÃO CONFIRMADO — adaptação operacional do quadro 6 aos ENI/EIRL:** as instruções de 2021 ainda mandam assinalar «Não» para essas categorias, enquanto o CRC art. 140.º-A lhes estende o apuramento de entidades contratantes desde 01-05-2023. Não foi localizada portaria substitutiva na pesquisa dirigida a 2025–2026; tal resultado não certifica o conteúdo do formulário autenticado. Um parecer da OCC de 19-03-2026 reconhece o art. 140.º-A, mas recomenda «Não» para um ENI de construção. É orientação profissional conflitante, não fundamento para afastar a lei. Confirmar com o ISS, em coordenação com a AT, a versão aplicável e o modo de comunicar os adquirentes; ver SS071.

**Base/vigência:** CRC art. 152.º; RCRC arts. 54.º-A/1/b e 58.º, redação atual desde 2019. Portaria n.º 249/2021, arts. 1.º/2, 2.º e 4.º: modelo para rendimentos de 2021 e seguintes, entrada em vigor em 13-11-2021. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [Anexo SS e instruções, pp. 3–4](https://files.diariodarepublica.pt/1s/2021/11/22000/0002400027.pdf), [Portaria 249/2021](https://diariodarepublica.pt/dr/detalhe/portaria/249-2021-174244814), [parecer OCC de 19-03-2026 — orientação profissional](https://portal.occ.pt/en/node/2083417).

#### SS056 Revisão anual e limiar de correção

**CONFIRMADO.** O ISS revê declarações com os dados fiscais e notifica diferenças. O art. 164.º-A/2 considera os pagamentos decorrentes da revisão fora de prazo. Pelo RCRC art. 62.º-A, o acerto é apurado em janeiro seguinte e distribuído pela carreira contributiva do ano respetivo, com audiência do interessado. O Despacho n.º 599/2019 fixa **20 € de base de incidência mínima**, não 20 € de contribuição adicional: o guia de 2026 usa linguagem imprecisa nesta passagem. Guardar distinção entre correção voluntária em janeiro e revisão oficiosa posterior, com o vencimento concreto notificado.

**Base/vigência:** CRC art. 164.º-A; RCRC art. 62.º-A/1–4; Despacho n.º 599/2019/2; efeitos 2019. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [Despacho 599](https://files.diariodarepublica.pt/2s/2019/01/008000000/0152401524.pdf).

### Pagamento e incumprimento

#### SS060 Prazo legal TI verificado para 2026

**CONFIRMADO.** O art. 155.º/2 mantém o pagamento mensal **entre 10 e 20 do mês seguinte** ao mês contributivo. O guia ISS e FAQ de janeiro de 2026, e serviço gov.pt atualizado em maio de 2026, reproduzem o mesmo prazo. O DL n.º 127/2025 alterou o **art. 43.º**, para pagamento de entidades empregadoras entre 1 e 25; não alterou o art. 155.º. A Lei do OE 2026 consultada não revelou alteração desta regra dos TI. Uma entidade individual com trabalhadores pode ter simultaneamente obrigações como empregadora e como TI, com regimes separados.

**Base/vigência:** CRC art. 155.º/1–2, redação desde 2019; DL n.º 127/2025 arts. 2.º/6.º, desde 01-01-2026; Lei n.º 73-A/2025. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [DL 127/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/127-2025-967317021), [gov.pt TI](https://www.gov.pt/servicos/obter-informacoes-sobre-as-contribuicoes-para-a-seguranca-social-pagamento-de-trabalhador-independente), [OE 2026](https://diariodarepublica.pt/dr/detalhe/lei/73-a-2025-993270096).

#### SS061 Exceção de agosto e dias não úteis

**CONFIRMADO.** As obrigações contributivas cujo prazo termina em agosto podem ser cumpridas até ao **último dia de agosto, mesmo que não útil**, sem acréscimos/penalidades, ressalvadas exceções expressas. Abrange as contribuições TI de julho. Não aplicar automaticamente esta regra a todos os outros meses. **DOUTRINA:** fora desta exceção, o guia ISS de pagamentos permite pagar no dia útil seguinte quando o último dia coincida com sábado, domingo ou feriado.

**Base/vigência:** CRC art. 23.º-B/1, aditado pela Lei n.º 24-D/2022, efeitos 01-01-2023; art. 23.º-B/2 alterado em 2026 para outra obrigação. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [guia pagamentos](https://www.seg-social.pt/documents/10152/25521/pagamento_contribuicoes_seguran%C3%A7a_social/9b10b5b3-9096-4ec0-b0a2-e5a123182eb0).

#### SS062 Divergência informativa sobre dia 25

**NÃO CONFIRMADO como alteração da norma dos TI.** Notícias de agosto de 2026, incluindo reproduções autárquicas, anunciam prazo de 31 de agosto e afirmam regresso ao dia 25 em setembro para uma lista que inclui TI. A fonte indicada é uma notícia ISS que, na extração disponível, não devolve o texto. A legislação consolidada e as fontes próprias TI consultadas mantêm **10–20 como regra legal confirmada**, sem prejuízo da exceção de agosto. Não foi identificado diploma ou despacho que fundamente a aplicação geral do dia 25 às contribuições próprias dos TI.

Não substituir silenciosamente o calendário legal nem marcar todas as datas como indeterminadas. Quando o documento individual indicar vencimento diferente, guardar essa evidência e obter esclarecimento do ISS sobre o enquadramento e o ato aplicável. A data de uma cobrança individual não demonstra, por si, alteração geral do art. 155.º/2, nem deve ser apresentada como prevalecendo sempre sobre a norma. Distinguir ainda as contribuições próprias TI das obrigações que a mesma pessoa tenha como entidade empregadora.

**Fontes para auditoria:** [notícia ISS referida](https://www.seg-social.pt/ptss/pssd/noticias/prazo-alargado-em-agosto); [gov.pt distingue entidades empregadoras](https://www.gov.pt/noticias/prazo-de-pagamento-das-contribuicoes-das-entidades-empregadoras-alargado-ate-ao-dia-25); [gov.pt/ISS, pagar como TI, atualizado em 28-05-2026](https://www.gov.pt/servicos/obter-informacoes-sobre-as-contribuicoes-para-a-seguranca-social-pagamento-de-trabalhador-independente); [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575); [DL 127/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/127-2025-967317021). Não é evidência de que o OE 2026 tenha prorrogado todos os pagamentos TI para o fim do mês.

#### SS063 Atraso no pagamento e coimas

**CONFIRMADO.** Incumprimento sanado nos 30 dias posteriores ao prazo é contraordenação leve; depois, grave. Para pessoa singular: leve 50–250 € por negligência ou 100–500 € com dolo; grave 300–1 200 € por negligência ou 600–2 400 € com dolo. A omissão da DQ é contraordenação leve específica. Não liquidar uma coima automática na aplicação: graduação, culpa, dispensa/redução e procedimento são próprios da autoridade competente.

**Base/vigência:** CRC art. 155.º/4, art. 151.º-A/8, art. 233.º/1–2 e art. 234.º; sanções atuais mantidas no período 2023–2026. **Fonte:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575).

#### SS064 Juros de mora

**CONFIRMADO.** O atraso gera juros; não existe um período geral de 30 dias sem juros. O art. 211.º refere mês de calendário ou fração; art. 212.º remete para o regime dos juros de mora das dívidas públicas. Taxas anuais: 2023 **5,997%**, 2024 **8,876%**, 2025 **8,309%**, 2026 **7,221%**. O guia ISS apresenta também equivalentes mensais. Não transplantar sem validação uma fórmula de juros comerciais, civis ou fiscais diários; separar estimativa e valor efetivamente liquidado pelo ISS, sobretudo em execução/garantias/prestações.

**Base/vigência:** CRC arts. 211.º–212.º; DL n.º 73/99 e avisos anuais. Para 2026, Aviso n.º 18/2026/2, desde 01-01-2026. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [Aviso 2026](https://diariodarepublica.pt/dr/detalhe/aviso/18-2026-993315668), [guia pagamentos](https://www.seg-social.pt/documents/10152/25521/pagamento_contribuicoes_seguran%C3%A7a_social/9b10b5b3-9096-4ec0-b0a2-e5a123182eb0). O detalhe do motor de juros não ficou suficientemente fechado para certificação automática.

### Entidades contratantes

#### SS070 Quem é entidade contratante e limites exatos

**CONFIRMADO.** Pessoa coletiva ou pessoa singular com atividade empresarial que beneficia, no mesmo ano civil, de **mais de 50%** do valor total da atividade do TI. Apuramento apenas se o TI estiver sujeito à obrigação contributiva e tiver rendimento anual de prestação de serviços **≥6 IAS**. Serviços a empresas do mesmo agrupamento são agregados. Exatamente 50% não basta; exatamente 6 IAS satisfaz o limite de serviços. Quem só presta a particulares sem atividade empresarial não cria esta qualidade nesses clientes.

**Base/vigência:** CRC art. 140.º/1–3; limiar >50% desde 01-01-2018. **Fontes:** [arts. 140.º/140.º-A](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-43555975), [DL 2/2018](https://diariodarepublica.pt/dr/detalhe/decreto-lei/2-2018-114484243).

#### SS071 ENI EIRL desde maio de 2023

**CONFIRMADO.** O art. 140.º-A estende o apuramento a serviços de ENI/EIRL, para proteção no desemprego. Não utilizar sem atualização um guia que exclua sempre ENI das entidades contratantes. A aplicação deve versionar esta extensão em **01-05-2023**; o tratamento do ano de transição e repartição de prestações antes/depois dessa data merece validação quando material. As instruções do quadro 6 do Anexo SS aprovado em 2021 ainda contêm indicação de «Não» para ENI/EIRL; esta divergência de preenchimento exige confirmação operacional, sem afastar a extensão legal. Ver SS055.

**Base/vigência:** Lei n.º 13/2023, art. 16.º, adita CRC art. 140.º-A; em vigor desde 01-05-2023. **Fontes:** [Lei 13/2023](https://diariodarepublica.pt/dr/detalhe/lei/13-2023-211340863), [art. 140.º-A](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-43555975), [Anexo SS de 2021](https://files.diariodarepublica.pt/1s/2021/11/22000/0002400027.pdf), [Portaria 249/2021](https://diariodarepublica.pt/dr/detalhe/portaria/249-2021-174244814).

#### SS072 Base taxa e responsável

**CONFIRMADO.** A entidade contratante deve contribuição sobre o **valor total dos serviços que lhe foram prestados no ano**, não apenas a parte superior a 50% e não 70% desses serviços. Taxa **10% se dependência >80%**; **7% nos restantes casos abrangidos**, ou seja, >50% e ≤80%. É encargo da entidade contratante, distinto da contribuição mensal do TI; não o descontar automaticamente da fatura ou do rendimento líquido do trabalhador. A obrigação nasce com apuramento oficioso pelo ISS.

**Base/vigência:** CRC arts. 150.º/3, 154.º/2, 167.º e 168.º/7, taxas atuais desde 01-01-2018. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [art. 168.º](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575-114488784).

#### SS073 Vencimento e casos que não devem ser simplificados

**CONFIRMADO.** Contribuições da entidade contratante reportam-se ao ano anterior e vencem até dia 20 do mês seguinte à emissão do documento de cobrança, sem prejuízo da exceção de agosto. TI sem obrigação contributiva não cumpre a condição do art. 140.º/2. **NÃO CONFIRMADO:** exclusão automática do cliente só por ser estrangeiro; o art. 140.º não contém essa exclusão e o formulário anual admite identificação estrangeira. Atividades mistas exigem atenção ao denominador: a lei fala em valor total da atividade, enquanto alguns textos operacionais resumem «serviços». Conservar vendas e serviços separadamente e validar casos fronteira.

**Base/vigência:** CRC art. 155.º/3, redação desde 2011; art. 140.º; RCRC art. 58.º. **Fontes:** [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [Anexo SS oficial](https://files.diariodarepublica.pt/1s/2021/11/22000/0002400027.pdf).

### Divergências dos guias e exemplos

- Guia ISS de 12-01-2026, p. 7: uma passagem aponta antecipação no mês seguinte e outra retroação ao próprio mês. CRC 146.º/2 e FAQ de 14-01-2026, pergunta 2, sustentam mês seguinte.
- Guia, pp. 16–17: por vezes chama «base de incidência 20 €» ao mínimo de contribuição; CRC 163.º/2 define base que origina contribuição de 20 €.
- Guia, p. 17: um exemplo escreve teto de 6 445,66 €, embora a regra e a portaria deem 6 445,56 €.
- FAQ 2026, pergunta 44: exemplo de 6,74 € contém frase contraditória alusiva a 20 €. CRC 163.º/4 e Despacho 599 sustentam remanescente de 6,74 €, sem mínimo de 20 €.
- Guia, revisão anual: refere contribuição adicional >20 €, mas Despacho 599/2019/2 fixa 20 € de **base**. Não confundir unidades.
- Guia, pagamentos: pode dar a entender juros apenas após 30 dias; CRC 211.º determina juros por incumprimento do prazo, independentemente da classificação contraordenacional.
- Página gov.pt de obrigações gerais consultada, apesar de datada de 2026, contém taxa de 29,6% e referência a 2 090 €/trimestre; são incompatíveis com CRC 168.º/1, 157.º/1/a e IAS 2026. Não a usar para alimentar o motor de cálculo.
- O art. 163.º/8 mantém a indexação ao IAS, enquanto os guias de 2026 publicam 20 € como mínimo. Não foi localizada suspensão específica; o Despacho 599/2019 não resolve essa divergência. Ver SS031.
- As notícias de agosto de 2026 aludem ao dia 25 para TI; o art. 155.º/2 e a página gov.pt/ISS de maio de 2026 mantêm 10–20. Preservar a regra legal e o alerta operacional separadamente. Ver SS060–062.
- Portaria 249/2021, instruções do quadro 6: indicação de «Não» para ENI/EIRL não está conciliada com o art. 140.º-A, eficaz desde maio de 2023. O parecer OCC de março de 2026 é orientação profissional, não atualização da norma. Ver SS055/071.
- Na igualdade exata de 4 IAS, a quantia contributiva é zero; a obrigação de DQ decorre de interpretação conjugada. O caso de contribuição matemática de 4,99904 € depende da ordem do teste de 5 € e do arredondamento, não esclarecida nas fontes consultadas. Ver SS042.

## 6 Contabilidade organizada

#### CONT01 Obrigação de contabilidade e responsabilidade do contabilista

Os titulares da categoria B fora do regime simplificado devem manter contabilidade organizada que permita controlar o rendimento. Aplicam-se por remissão as obrigações contabilísticas do artigo 123.º do CIRC. A responsabilidade pela regularidade técnica, assinatura das demonstrações e declarações assentes em informação contabilística é ato reservado a contabilista certificado. A AT exige que o CC entregue o início quando existe obrigação ou opção por contabilidade organizada.

Base: CIRS, artigo 117.º, n.os 1 e 2; Estatuto da OCC, aprovado pelo Decreto-Lei n.º 452/99, republicado pela Lei n.º 139/2015, artigo 10.º, n.º 1, alínea b), na redação da Lei n.º 68/2023. Vigência: CIRS republicado com efeitos em 01-01-2015; redação indicada do Estatuto desde 01-03-2024. Estado: CONFIRMADO para a obrigação e competência; DOUTRINA para o procedimento do Portal.

Fontes: [CIRS 117](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs117.aspx); [Estatuto OCC consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2015-186086322); [AT FAQ 4560 e 4558](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/questoes_frequentes/pages/faqs-00312.aspx).

#### CONT02 Registos cronológicos e atraso máximo

Cada lançamento deve ter documento justificativo datado; as operações são registadas cronologicamente e os erros corrigidos contabilisticamente. Não são admitidos atrasos superiores a 90 dias, contados do último dia do mês das operações. Deve existir capacidade de exportar ficheiros nos formatos regulamentados; esta obrigação de capacidade não equivale à entrega automática do SAF-T contabilístico.

Base: CIRS, artigo 117.º, n.º 2; CIRC, artigo 123.º, n.º 2, alíneas a) e b), n.os 3 e 8. Vigência: texto atual verificado em 2026; n.º 8 na redação da Lei n.º 42/2016, efeitos 01-01-2017. Estado: CONFIRMADO. Fonte: [CIRC 123](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc123.aspx).

#### CONT03 Enquadramento no SNC

O SNC abrange empresas individuais reguladas pelo Código Comercial e EIRL. A dispensa contabilística do SNC para certas pessoas singulares usa a média do volume de negócios líquido dos últimos três anos, não superior a 200 000 €, e não deve ser confundida com o teste fiscal do artigo 28.º do CIRS. No início, aquele teste SNC usa estimativa anualizada. A obrigação fiscal de contabilidade organizada tem de ser avaliada autonomamente. A aplicação concreta do SNC a profissional liberal não abrangido diretamente pelo artigo 3.º requer enquadramento por CC à luz da remissão fiscal; não se deve decidir apenas com o rótulo «TI».

Base: Decreto-Lei n.º 158/2009, artigos 3.º, n.º 1, alíneas b) e c), e 10.º, n.os 1, 3 e 5; CIRS, artigo 117.º. Vigência: estrutura atual desde 01-01-2016, com outras alterações posteriores identificadas no consolidado. Estado: CONFIRMADO para os testes legais; NÃO CONFIRMADO para uma regra automática universal que aplique a dispensa SNC a qualquer profissional liberal.

Fonte: [SNC consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2009-34517175).

#### CONT04 Categorias contabilísticas e normas

Nos exercícios iniciados em ou após 01-01-2026, microentidade é a que não ultrapassa dois dos três limites: balanço 450 000 €, volume de negócios líquido 900 000 €, média de 10 empregados. Pequena entidade, excluídas as micro: 5 000 000 €, 10 000 000 € e 50 empregados. Média: 25 000 000 €, 50 000 000 € e 250 empregados. O teste de mudança de categoria observa dois períodos consecutivos e produz efeitos no terceiro, nos termos do artigo 9.º-A. Não confundir estes limites com os da definição europeia de PME usada noutras obrigações.

Base: Decreto-Lei n.º 158/2009, artigos 9.º e 9.º-A; Decreto-Lei n.º 126-B/2025, artigos 2.º e 3.º. Vigência formal do último diploma: 06-12-2025; efeitos nas demonstrações financeiras de exercícios iniciados desde 01-01-2026. Estado: CONFIRMADO. Fontes: [alteração de 2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/126-b-2025-964514221); [SNC consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2009-34517175).

Microentidades aplicam NC-ME, podendo optar por NCRF-PE ou NCRF; pequenas entidades podem aplicar NCRF-PE. A NC-ME integra o SNC desde 2016; não usar como regime autónomo atual o antigo NCM de 2011. Base: artigos 9.º-C e 9.º-D do Decreto-Lei n.º 158/2009; Aviso n.º 8255/2015, pontos 1.1, 2.1 e 19.1 a 19.3. Vigência: 01-01-2016. Estado: CONFIRMADO. Fonte: [NC-ME](https://diariodarepublica.pt/dr/detalhe/aviso/8255-2015-69889535).

**Transição de 2026 — DOUTRINA profissional OCC.** O parecer de 31-03-2026 aplica os novos limites na classificação de 2026 ao avaliar os dados dos períodos antecedentes. Não altera retroativamente a categoria de 2025. Conservar balanço, volume de negócios e trabalhadores dos dois anos, em vez de guardar apenas a categoria anterior. Por exemplo, uma pequena entidade com balanço de 4,1/4,8 milhões, volume de negócios de 7,1/7,3 milhões e 52/55 empregados em 2024/2025 permanece pequena em 2026 segundo essa orientação. O artigo 9.º-A mantém o teste de dois períodos; a leitura da transição é orientação profissional, não informação vinculativa da AT. Fonte: [OCC, Normalização contabilística, 31-03-2026](https://www.occ.pt/index.php/pt-pt/noticias/normalizacao-contabilistica-0).

#### CONT05 Determinação fiscal e existência material de contabilidade

Ter um sistema de contabilidade organizada não basta para exercer a opção fiscal pelo regime de determinação do rendimento com base na contabilidade. A OCC distingue o campo que informa a existência de contabilidade e nomeia CC do campo de opção pelo regime de tributação. A aplicação deve guardar separadamente has_organized_accounts e irs_income_determination_regime.

Base: CIRS, artigos 28.º, 31.º, 32.º e 117.º. Vigência: regime atual verificado em 2026. Estado: DOUTRINA profissional OCC, não interpretação vinculativa da AT. Fonte: [OCC IRS regime simplificado, 2026](https://portal.occ.pt/pt-pt/noticias/irs-regime-simplificado-4).

#### CONT06 IES e Anexo I

Quem possua ou deva possuir contabilidade organizada entrega a declaração anual de informação contabilística e fiscal; também pode haver obrigação por um anexo específico. Prazo legal do IRS: 15 de julho, expressamente independentemente de ser dia útil. Em 2026, a agenda AT confirma 15-07-2026. O anexo empresarial de IRS é o I; anexos L, M, N, P ou outros dependem dos factos e das instruções aplicáveis. Os antigos anexos O e Q foram eliminados pelo DL 49/2025: não os manter por reprodução de manuais anteriores. Um ENI comum não deve ser tratado automaticamente como sociedade obrigada a registo comercial de prestação de contas.

Base: CIRS, artigo 113.º, n.os 1 e 2; Decreto-Lei n.º 8/2007, artigo 2.º; DL 49/2025, artigos 4.º, 5.º, 12.º, 17.º e 18.º, na eliminação dos deveres ligados aos anexos O/Q; modelos e instruções IES em vigor. Vigência: prazo na redação consolidada verificada; simplificação desde 01-07-2025; formulário de cada exercício deve ser versionado. Estado: CONFIRMADO para obrigação/prazo e eliminação legal; DOUTRINA profissional para a síntese dos restantes anexos, sujeita à validação do formulário do exercício.

Fontes: [CIRS 113](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs113.aspx); [DL 8/2007](https://diariodarepublica.pt/dr/detalhe/decreto-lei/8-2007-522813); [DL 49/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/49-2025-912653926); [manual OCC IES 2026](https://www.occ.pt/sites/default/files/public/2026-03/Essencial_IES2026-DIG.pdf).

#### CONT07 Inventário permanente e comunicação anual são obrigações diferentes

O SNC impõe inventário permanente com contagem física e identificação de natureza, quantidade e custos, mas dispensa microentidades. Há dispensas setoriais e para certas atividades predominantemente de serviços. Estas dispensas não dispensam automaticamente a comunicação anual de inventários à AT.

Base: Decreto-Lei n.º 158/2009, artigo 12.º, n.os 1, 2 e 4 a 7. Vigência: redação com efeitos desde 01-01-2016. Estado: CONFIRMADO. Fonte: [artigo 12 no SNC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2009-34517175).

A comunicação anual abrange pessoas com domicílio/sede/estabelecimento em Portugal, contabilidade organizada e obrigação de inventário. Prazo: 31 de janeiro seguinte, ou final do primeiro mês seguinte ao período de tributação diferente. O regime simplificado de IRS ou IRC está dispensado. A regra atual não contém a antiga dispensa por volume de negócios até 100 000 €.

Base: Decreto-Lei n.º 198/2012, artigo 3.º-A, n.os 1 a 3, na redação do artigo 41.º do Decreto-Lei n.º 28/2019. Vigência da redação: 16-02-2019, sem prejuízo de regimes transitórios relativos à valorização. Estado: CONFIRMADO. Fonte: [DL 198/2012 consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2012-106458692).

#### CONT08 Inventários de 2025 e 2026

Para inventários de 31-12-2025 comunicados em 2026 não é obrigatória valorização. Para os de 31-12-2026, a dispensa mantém-se para não obrigados a inventário permanente; para quem esteja obrigado, a comunicação valorizada é devida em janeiro de 2027, salvo alteração legislativa posterior. Em 2026 o prazo de 31 de janeiro passou para 2 de fevereiro. Se não houver existências, a AT orienta a comunicar essa inexistência; a ausência de stocks não deve ser confundida com a dispensa do simplificado.

Base: Lei n.º 73-A/2025, artigo 95.º, n.º 1, alíneas a) e b); Decreto-Lei n.º 198/2012, artigo 3.º-A; FAQ AT 5120, 5121 e 5766 a 5769. Vigência: 01-01-2026. Estado: CONFIRMADO quanto à lei; DOUTRINA quanto ao procedimento de inexistência. Fontes: [OE 2026](https://diariodarepublica.pt/dr/detalhe/lei/73-a-2025-993270096); [FAQ AT inventários](https://info.portaldasfinancas.gov.pt/pt/faturas/Pages/faqs-00981.aspx).

#### CONT09 Dossier fiscal

Titulares de IRS que possuam ou devam possuir contabilidade organizada constituem o processo de documentação fiscal até ao prazo da declaração do artigo 113.º. Deve conter os documentos efetivamente aplicáveis, definidos pela portaria, incluindo mapas e elementos justificativos das correções fiscais. Não é uma obrigação reservada às sociedades ou a contribuintes de grande dimensão.

Base: CIRS, artigo 129.º, n.os 1 e 2, remissões para os artigos 113.º e 118.º; Portaria n.º 51/2018, artigo 2.º e Anexo I, que substituem o Anexo I da Portaria n.º 92-A/2011. A Portaria n.º 51/2018 entrou em vigor em 17-02-2018 e aplica a lista aos períodos iniciados desde 01-01-2017, nos termos do artigo 6.º. Estado: CONFIRMADO para obrigação, prazo, lista base e vigência. A aplicabilidade de cada documento condicional depende das operações e dos benefícios efetivos.

| **Número do Anexo I** | **Documento assinalado para IRS** | **Condição** |
| --- | --- | --- |
| 2 | Documentação dos créditos e regularizações do artigo 78.º do CIVA | Existência de regularizações abrangidas |
| 3 | Provisões, imparidades em créditos e ajustamentos de inventários; modelo 30 do dossier | Existência de valores a controlar |
| 4 | Mapa de mais-valias e menos-valias; modelo 31 | Operações abrangidas |
| 5 | Mapa de depreciações e amortizações; modelo 32 | Ativos abrangidos |
| 6 e 7 | Mapas de reavaliações fiscais | Aplicação efetiva dos regimes de reavaliação |
| 8 | Apuramento do lucro por regimes de tributação | Necessidade dessa discriminação |
| 10 | Controlo da dedução de prejuízos | Existência de prejuízos e regras de reporte aplicáveis |
| 11 | Reporte de gastos de financiamento líquidos | Aplicabilidade do regime material |
| 12 | Outros elementos exigidos por legislação ou benefícios fiscais | Lista aberta; verificar os factos e o benefício usado |

Os números 1 e 9 não estão assinalados na coluna IRS. O mapa modelo 30 do dossier não é a declaração Modelo 30 de pagamentos a não residentes. Os modelos 30 e 31 continuam regulados pela Portaria n.º 92-A/2011; o modelo 32, pela Portaria n.º 94/2013; os modelos 34.7 e 34.8, pela Portaria n.º 51/2018. Base adicional: artigos 4.º e 5.º desta última portaria. Não gerar mapas de reavaliação ou de outros regimes apenas por existir contabilidade organizada.

Fontes: [CIRS 129.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs129.aspx); [Portaria 51/2018](https://diariodarepublica.pt/dr/detalhe/portaria/51-2018-114706488); [Anexo I no original, página 984 do DR](https://files.diariodarepublica.pt/1s/2018/02/03400/0098300990.pdf); [Portaria 92-A/2011](https://diariodarepublica.pt/dr/detalhe/portaria/92-a-2011-406326); [Portaria 94/2013](https://diariodarepublica.pt/dr/detalhe/portaria/94-2013-259264).

#### CONT10 Conta bancária e pagamentos em numerário

Quem disponha ou deva dispor de contabilidade organizada deve ter pelo menos uma conta exclusivamente usada para recebimentos e pagamentos da atividade. Pagamentos relativos a faturas de valor igual ou superior a 1 000 € por esses contribuintes exigem meio que identifique o destinatário. Em geral, é proibido pagar ou receber em numerário em transações de montante igual ou superior a 3 000 €; pagamentos fracionados somam-se. Para particular não residente que não atue como empresário/comerciante, o limite geral é 10 000 €. Impostos acima de 500 € não podem ser pagos em numerário. Ver as exceções legais para operações financeiras e decisões judiciais.

Base: LGT, artigos 63.º-C, n.os 1 e 2, e 63.º-E, n.os 1 a 6; Lei n.º 92/2017, artigo 2.º. Vigência dos limites em numerário: 23-08-2017. Estado: CONFIRMADO. Fontes: [LGT 63-C](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/lgt/Pages/lgt63c.aspx); [LGT 63-E](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/1998-34438775-108042206).

#### CONT11 Não aplicar impostos exclusivos das sociedades

O ENI pessoa singular é tributado em IRS; o apuramento do lucro com remissões para o CIRC não o transforma em sujeito passivo de IRC. Os pagamentos por conta relevantes são os do CIRS, artigo 102.º. Não configurar PEC ou derramas de IRC para o ENI por existir contabilidade organizada. Acresce que o artigo 106.º do CIRC, relativo ao PEC, foi revogado pela Lei n.º 12/2022, artigo 329.º.

Base: CIRS, artigos 3.º, 32.º e 102.º; CIRC, artigo 106.º revogado. Vigência: distinção estrutural; revogação do PEC em 28-06-2022. Estado: CONFIRMADO. Fonte: [CIRC com histórico do artigo 106](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2014-64205634-902104187).

## 7 Obrigações periódicas calendário e infrações

### Modelo 3 e registos

#### OBR001 Prazo do Modelo 3

Modelo 3 de 1 abril a 30 junho, mesmo se o último dia não útil; prazos especiais para factos supervenientes e crédito estrangeiro não determinado.

**Base e fonte:** CIRS 60.º, n.os 1–3, [C 60](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs60.aspx)

**Vigência e estado:** Atual, **CONFIRMADO**. Despachos anuais excecionais exigem consulta própria.

#### OBR002 Anexos do Modelo 3

Simplificado/atos isolados usam Anexo B; CO usa Anexo C; imputações podem exigir D; fonte estrangeira exige análise do Anexo J; Anexo SS depende do enquadramento contributivo.

**Base e fonte:** Instruções oficiais Modelo 3, v 2026, [M 3](https://irs.portaldasfinancas.gov.pt/app/formularioEletronico/v2026); Ofício 20291/2026, de 17-03, pontos 2.2 e 3.2 [OF 20291](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio-circulado-20291-2026.pdf); CIRS 57/60/117

**Vigência e estado:** B/C e opções IRS Jovem confirmadas para rendimentos de 2025 e seguintes; casos detalhados J/SS **NÃO CONFIRMADOS** integralmente nesta pesquisa.

#### OBR003 Declaração anual contabilística

Declaração anual contabilística/fiscal do art 113.º para CO e outros casos legalmente previstos, até 15 julho independentemente de útil.

**Base e fonte:** CIRS 113.º, n.os 1–2, [C 113](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs113.aspx)

**Vigência e estado:** Atual, **CONFIRMADO** para prazo-base; IES/dispensas/anexos tratados em módulo próprio.

#### OBR004 Documentos de recebimento

Emissão de fatura/recibo/fatura-recibo por importâncias legalmente abrangidas, pelos meios e condições do 115.º; não confundir documento de faturação com retenção efetivamente entregue.

**Base e fonte:** CIRS 115.º, n.º 1, [C 115](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs115.aspx)

**Vigência e estado:** Redação DL 49/2025 desde 01-07-2025, **CONFIRMADO**.

#### OBR005 Registos sem contabilidade organizada

Sem CO, registos previstos no 116.º; reembolsos em nome/por conta de cliente separados; lançamentos até 60 dias. Dispensa para atos isolados e para condições cumulativas de IVA 53, sem CO e faturação exclusiva AT. Ver FAT11 para a alteração dos registos IVA e respetiva articulação.

**Base e fonte:** CIRS 116.º, n.os 1–6, [C 116](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs116.aspx)

**Vigência e estado:** Texto atual, alteração DL 35/2025 desde 29-03-2025. **CONFIRMADO**; CIVA 50.º alterado desde 01-07-2025.

#### OBR006 Registos contabilísticos

Fora do simplificado, CO obrigatória; CIRS 117.º remete para CIRC 123.º. Registos suportados, cronológicos, sem atraso superior 90 dias e com capacidade de exportação nos termos aplicáveis.

**Base e fonte:** CIRS 117.º,[C 117](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs117.aspx); CIRC 123.º, n.os 1,3,8,[IRC 123](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/CIRC_2R/Pages/irc123.aspx)

**Vigência e estado:** Atual, **CONFIRMADO**.

As instruções v 2026 referem-se à campanha declarativa correspondente e já contemplam opções IRS Jovem de 2025. Não se certifica o formulário que será publicado para entregar rendimentos 2026 em 2027. O sistema deve versionar o modelo de exportação separadamente das regras substantivas.

### Outras obrigações e calendário

#### OBR01 Obrigações enquanto pagador de rendimentos

O TI/ENI que paga rendimentos e se enquadra como entidade obrigada a reter deve manter registo dos beneficiários, datas, valores e retenções, mesmo quando exista dispensa de retenção no pagamento concreto. Entrega ao beneficiário declaração anual até 20 de janeiro. Para rendimentos dependentes, a DMR é enviada até dia 10 do mês seguinte. Para os restantes rendimentos abrangidos, a Modelo 10 passa a ter prazo no final de fevereiro; em 2026, 2 de março. A obrigação não nasce por o sujeito passivo emitir os seus próprios recibos verdes: nasce dos pagamentos a terceiros que caibam na norma.

Base: CIRS, artigo 119.º, n.º 1, alíneas a), b), c), subalíneas i) e ii), e n.º 6. Redação do prazo Modelo 10: Decreto-Lei n.º 49/2025, artigo 2.º, desde 01-07-2025. Estado: CONFIRMADO; Ofício-circulado n.º 20286/2026 confirma administrativamente a harmonização. Fontes: [CIRS 119](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs119.aspx); [Ofício 20286/2026](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio_circulado_20286_2026.pdf).

#### OBR02 Pagamentos a não residentes

Quando a entidade devedora esteja abrangida, a Modelo 30 é entregue até ao fim do segundo mês seguinte ao evento relevante — pagamento, vencimento, colocação à disposição, liquidação ou apuramento, conforme o caso. A existência de convenção para evitar dupla tributação e de documentação de dispensa não elimina automaticamente todas as obrigações declarativas. A aplicação deve identificar país, natureza do rendimento, fonte portuguesa e documento convencional; não equiparar cada compra de software estrangeiro a retenção obrigatória sem análise.

Base: CIRS, artigo 119.º, n.º 7, alíneas a) e b), articulado com os artigos 18.º, 71.º e 101.º-C e convenção aplicável. Vigência: texto consolidado verificado em 2026. Estado: CONFIRMADO para prazo; NÃO CONFIRMADO para decisão por país/tipo sem o tratado e documentação específicos. Fonte: [CIRS 119](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs119.aspx).

#### OBR03 Fins de semana feriados e agosto

A contagem dos prazos do procedimento tributário é contínua e segue o artigo 279.º do Código Civil; o CPPT transfere o termo que coincida com encerramento dos serviços/tribunais para o primeiro dia útil. A agenda AT aplica transferência por fim de semana/feriado a diversas obrigações declarativas e de pagamento. Há, contudo, regras expressas especiais: o artigo 113.º, n.º 2, do CIRS fixa a IES até 15 de julho independentemente de dia útil, e o artigo 57.º-A, n.º 1, da LGT permite cumprir obrigações tributárias de agosto até ao último dia desse mês independentemente de ser útil. Os prazos de procedimento e defesa previstos no n.º 2 têm tratamento diferente: primeiro dia útil de setembro.

Base: CPPT, artigo 20.º, n.º 1, redação Lei n.º 7/2021; Código Civil, artigo 279.º, alíneas b), c) e e); LGT, artigo 57.º-A, n.os 1 e 2, Lei n.º 7/2021 e Lei n.º 12/2022; CIRS, artigo 113.º, n.º 2. Vigência: regras de agosto desde 2021; redação do n.º 2 desde 2022. Estado: CONFIRMADO. A regra técnica deve ser específica por obrigação e permitir despachos de prorrogação com prioridade sobre o calendário de base.

Fontes: [CPPT 20](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cppt/Pages/cppt20.aspx); [CC 279](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/1966-34509075-49960375); [LGT 57-A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/lgt/Pages/lgt57a.aspx).

#### OBR04 Agenda operacional de 2026

As seguintes datas são as publicadas pela AT e não devem ser extrapoladas automaticamente para 2027. A aplicabilidade de cada coluna depende do enquadramento. «DP/pagamento mensal» refere-se ao IVA do período correspondente. Em setembro vencem-se as declarações mensais de junho e de julho, separadamente, porque junho beneficia de diferimento especial. A declaração trimestral então entregue respeita ao segundo trimestre. As declarações de IRS entregues em 2026 respeitam normalmente a rendimentos de 2025; não usar nelas os parâmetros do ano de rendimentos 2026.

| **Mês 2026** | **Comunicação faturas do mês anterior** | **Entrega retenções IRS** | **IVA mensal DP / pagamento** | **IVA trimestral DP / pagamento** |
| --- | --- | --- | --- | --- |
| Janeiro | 9 | 20 | 20 / 26 | — |
| Fevereiro | 5 | 20 | 20 / 25 | 20 / 25 |
| Março | 5 | 20 | 20 / 25 | — |
| Abril | 8 | 20 | 20 / 27 | — |
| Maio | 8 | 20 | 20 / 25 | 20 / 25 |
| Junho | 5 | 22 | 22 / 25 | — |
| Julho | 6 | 20 | 20 / 27 | — |
| Agosto | 31 | 31 | — | — |
| Setembro | 7 | 21 | 21 / 25 | 21 / 25 |
| Outubro | 6 | 20 | 20 / 26 | — |
| Novembro | 5 | 20 | 20 / 25 | 20 / 25 |
| Dezembro | 7 | 21 | 21 / 28 | — |

Estado: DOUTRINA/PRÁTICA administrativa verificada na agenda oficial, apoiada nos artigos materiais de cada obrigação. Fontes: [Agenda declarativa AT 2026](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/Quadro_res_Decl_2026.aspx); [Agenda pagamentos AT 2026](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/Quadro_res_Pag_2026.aspx). Prorrogações e-Fatura: Despachos n.os 166/2025-XXV, de 22-12, 40/2026-XXV, de 26-03, e 55/2026-XXV, de 28-04, ligados na agenda. Conservar os despachos no registo da regra, não apenas o dia final.

| **Momento anual ou evento** | **Obrigação e condição** | **Base e estado** |
| --- | --- | --- |
| 20 janeiro | Declaração de rendimentos/retenções aos beneficiários, enquanto pagador abrangido | CIRS 119.º/1/b; CONFIRMADO |
| 22 janeiro 2026 | Alteração por saída do art. 53.º por excesso no ano anterior | CIVA 58.º/2/a, 4/a e 5/a; a agenda mantém uma referência antiga a 58.º/1/a |
| 2 fevereiro 2026 | Inventário de 2025 ou inexistência, se abrangido | DL 198/2012 3.º-A; agenda AT |
| Final janeiro, abril, julho e outubro | Declaração trimestral SS, se obrigado; janeiro inclui revisão anual | Código Contributivo 151.º-A e regulamentação; ver capítulo SS |
| 2 março 2026 | Modelo 10 de 2025; comunicações IRS harmonizadas e classificação de despesas para 2025 nos termos aplicáveis | CIRS 119.º/1/c/ii; DL 49/2025; Ofício 20286/2026 |
| 31 março | Opção pelo regime de tributação com base na contabilidade para o próprio ano | CIRS 28.º/4/b |
| 1 abril a 30 junho | Modelo 3 e anexos do ano anterior | CIRS 60.º/1 |
| 15 julho | IES/DA; constituição do dossier fiscal, quando exigíveis | CIRS 113.º e 129.º |
| 20 julho, 21 setembro e 21 dezembro de 2026 | Pagamentos por conta de IRS, se apurados | CIRS 102.º; agenda AT |
| 31 agosto | Pagamento de IRS liquidado no ciclo normal após entrega no prazo | CIRS 97.º/1/a, condicionado à liquidação; confirmar documento de cobrança |
| Até ao final do segundo mês seguinte | Modelo 30, nos pagamentos abrangidos a não residentes | CIRS 119.º/7/a |
| Antes de iniciar | Declaração de início de atividade | CIRS 112.º/1 e CIVA 31.º |
| Regra geral 15 dias após alteração | Declaração de alterações; prevalece prazo especial | CIRS 112.º/2 e CIVA 32.º |
| 30 dias após cessação | Declaração de cessação | CIRS 112.º/3 e CIVA 33.º |
| Antes da utilização de série / antes de transporte | Comunicação de série / documento de transporte quando exigível | Portaria 195/2020 e regime bens circulação; ver faturação |

### Infrações e juros

#### INF01 Coimas abstratas para pessoas singulares

Os intervalos seguintes são os tipos legais de base; não são uma previsão automática da coima concreta. Dependem da infração, culpa, concurso, regularização e possíveis dispensas/reduções. Não duplicar os limites só porque o sujeito passivo é ENI: o agravamento do artigo 26.º, n.º 4, respeita a pessoas coletivas/equiparadas.

| **Infração** | **Coima legal de base** | **Artigo RGIT** | **Vigência da redação consultada** |
| --- | --- | --- | --- |
| Falta ou atraso de declaração que permita determinar/comprovar matéria coletável | 150 a 3 750 € | 116.º/1 | Lei 64-B/2011, desde 2012 |
| Falta/atraso de início, alteração ou cessação | 300 a 7 500 € | 117.º/2 | Texto atual confirmado em 2026; origem exata da redação do n.º 2 não reconstituída |
| Falta/atraso de documentos ou comunicações autónomas abrangidas | 150 a 3 750 € | 117.º/1 | Lei 119/2019, desde 01-10-2019 |
| Omissões/inexatidões declarativas sem crime | 375 a 22 500 €; limites reduzidos a 1/4 sem imposto a liquidar | 119.º/1 e 2 | n.º 1 Lei 119/2019; n.º 2 Lei 67-A/2007 |
| Falta/emissão tardia de fatura ou recibo | 150 a 3 750 € | 123.º/1 | Lei 64-B/2011, desde 2012 |
| Falta de exigência/conservação de fatura nos termos da norma | 75 a 2 000 € | 123.º/2 | Lei 64-B/2011, desde 2012 |
| Contabilidade sem observância das normas | 500 a 10 000 € | 121.º/1 | Lei 114/2017, desde 2018 |
| Atraso da contabilidade ou ficheiro fora da estrutura legal | 250 a 5 000 € | 121.º/2 e 3 | Lei 114/2017, desde 2018 |
| Prestação tributária não entregue, negligência | 15% a 50% do imposto, sujeito aos limites legais | 114.º/2 e 5 | Lei 64-B/2011, desde 2012 |

Estado: CONFIRMADO nos valores e tipos; a data histórica expressamente não reconstruída permanece NÃO CONFIRMADA. Fontes oficiais: [RGIT 116](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit116.aspx); [117](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit117.aspx); [119](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit119.aspx); [121](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit121.aspx); [123](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit123.aspx); [114](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit114.aspx); [26](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit26.aspx).

#### INF02 Dispensa redução e atenuação

Desde 01-01-2022, o artigo 29.º trata da dispensa, e o artigo 30.º da redução. A redução pode ser de 12,5% do mínimo legal antes de auto/participação/denúncia/inspeção, ou 50% nos termos da fase de inspeção, exigindo regularização e pagamento nos prazos. O mínimo reduzido é 25 €. A dispensa exige testar o historial dos cinco anos e/ou as condições específicas de regularização e ausência de prejuízo efetivo; não equivale a «uma primeira multa sempre grátis». A atenuação especial do artigo 32.º depende de pedido e pressupostos. As páginas históricas AT com os antigos artigos não podem alimentar a regra atual.

Base: RGIT, artigos 26.º/3, 29.º, 30.º e 32.º; Lei n.º 7/2021, artigo 17.º, n.º 5. Vigência: 01-01-2022. Estado: CONFIRMADO. Fontes: [RGIT 29 atual](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit29.aspx); [30](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit30.aspx); [32](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/rgit/Pages/rgit32.aspx).

#### INF03 Juros

O pagamento de imposto fora do prazo pode gerar juros de mora, além de coima e execução fiscal. A taxa anual das dívidas ao Estado é diferente dos juros comerciais de uma fatura a cliente. Em 2026 a taxa é 7,221%, desde 1 de janeiro. Juros compensatórios por atraso imputável na liquidação têm fundamento e período próprios: não substituir um pelo outro nem somá-los sem verificar sobreposição e norma aplicável.

Base: LGT, artigos 44.º, n.os 1 a 4, e 35.º, n.os 1 a 10; Decreto-Lei n.º 73/99 e aviso anual IGCP. Vigência das taxas: ano civil indicado. Estado: CONFIRMADO para taxas e distinção; o algoritmo integral de juros em execução/prestações/garantias exige validação específica.

| **Ano** | **Juros de mora ao Estado** | **Fonte oficial** |
| --- | --- | --- |
| 2023 | 5,997% | [Aviso 177/2023](https://diariodarepublica.pt/dr/detalhe/aviso/177-2023-205694979) |
| 2024 | 8,876% | [Aviso 678/2024](https://diariodarepublica.pt/dr/detalhe/aviso/678-2024-836494152) |
| 2025 | 8,309% | [Aviso 29181/2024/2](https://diariodarepublica.pt/dr/detalhe/aviso/29181-2024-901403554) |
| 2026 | 7,221% | [Aviso 18/2026/2](https://diariodarepublica.pt/dr/detalhe/aviso/18-2026-993315668) |

Fontes de incidência: [LGT 44](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/lgt/Pages/lgt44.aspx); [LGT 35](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/lgt/Pages/lgt35.aspx).

#### INF04 Limites de cobertura das obrigações transversais

O perfil mínimo indicado no pedido não determina, por si, todos os impostos possíveis. Empregados, imóveis, veículos, importações, pagamentos sujeitos a selo, setor de atividade e benefícios fiscais podem gerar obrigações adicionais. Para prometer cálculo integral, a aplicação necessita de módulos específicos para retenções salariais e TSU patronal, IMI/IMT/AIMI, IUC/ISV, imposto do selo, aduaneiro, regimes setoriais e convenções internacionais. Estes módulos não estão integralmente parametrizados nesta memória. As suas regras não devem ser preenchidas por analogia com o regime geral da categoria B.

Esta é uma limitação declarada da cobertura, não uma nova regra fiscal. Uma regra ainda não confirmada deve bloquear o resultado afetado ou solicitar revisão profissional, em vez de produzir uma estimativa apresentada como imposto legal exato.

## 8 Parâmetros anuais de 2023 a 2026

#### Parâmetros gerais B

| **ID: HIST001 — parâmetro** | **2023** | **2024** | **2025** | **2026 em 11-11** |
| --- | --- | --- | --- | --- |
| IAS | 480,43 € | 509,26 € | 522,50 € | 537,13 € |
| Limite simplificado | 200.000 € | 200.000 € | 200.000 € | 200.000 € |
| CO no ano seguinte por excesso num ano | >250.000 € | >250.000 € | >250.000 € | >250.000 € |
| Coeficiente serviços específicos 151 | 0,75 | 0,75 | 0,75 | 0,75 |
| Outros serviços | 0,35 | 0,35 | 0,35 | 0,35 |
| Vendas/restauração/hotelaria elegível | 0,15 | 0,15 | 0,15 | 0,15 |
| Outros coeficientes 31.º | 0,95; 0,30; 0,10; 1; 0,50 | iguais | iguais | iguais |
| Redução início/ano seguinte b, c, f | 50%/25% | 50%/25% | 50%/25% | 50%/25% |
| Verificação despesas b+c | 15% brutos | 15% brutos | 15% brutos | 15% brutos |
| Parcela automática referência | 4.104,00 € | 4.350,24 € | 4.462,15 € | 4.587,09 € |
| Retenção profissão 151 | 25% | 25% | 23% | 23% |
| Retenção outros serviços /PI | 11,5%/16,5% | 11,5%/16,5% | 11,5%/16,5% | 11,5%/16,5% |
| Limite dispensa retenção | 13.500 € | 14.500 € | 15.000 € | 15.000 € |
| Dispensa retenção individual B<25 € | Não | Não | Desde 01-07 | Sim |
| Fator PPC | 76,5% | 76,5% | 65% | 65% |
| Mínimo por prestação PPC exigível | 50 € | 50 € | 50 € | 50 € |
| Valor referência mínimo de existência | 10.640 € | 11.480 € | 12.180 € | 12.880 € |
| Limiar custo viatura TA IRS | 20.000 € | 20.000 € | 30.000 € | 30.000 € |
| Dedução desgaste rápido 5 IAS | 2.402,15 € | 2.546,30 € | 2.612,50 € | 2.685,65 € |

**Fontes/estado:** valores e regras das secções anteriores; IAS portarias 298/2022,421/2023,6-B/2025/1,480-A/2025/1, arts 2.º e 4.º [IAS 23](https://diariodarepublica.pt/dr/detalhe/portaria/298-2022-204841038), [IAS 24](https://diariodarepublica.pt/dr/detalhe/portaria/421-2023-229857697), [IAS 25](https://files.diariodarepublica.pt/gratuitos/1s/2025/01/00301.pdf), [IAS 26](https://diariodarepublica.pt/dr/detalhe/portaria/480-a-2025-993056222); 200.000 €/coeficientes: CIRS 28/31; dedução: C 25/L 32/OE 25; retenções/PPC: versões históricas 101/102; dispensa: OE 23 art 282/101 B; TA: C 73-24/OE 25. **CONFIRMADO** nos anos indicados para os parâmetros gerais; não pressupõe idêntica elegibilidade pessoal nem ausência de alterações na classificação da atividade.

#### Escalões nacionais limites superiores e taxa marginal

| **Escalão** | **2023 limite /taxa** | **2024 final limite /taxa** | **2025 final limite /taxa** | **2026 após DL 212 limite /taxa** |
| --- | --- | --- | --- | --- |
| 1 | 7.479 €/14,5% | 7.703 €/13% | 8.059 €/12,5% | 8.342 €/12,2% |
| 2 | 11.284 €/21% | 11.623 €/16,5% | 12.160 €/16% | 12.587 €/15,2% |
| 3 | 15.992 €/26,5% | 16.472 €/22% | 17.233 €/21,5% | 17.838 €/20,7% |
| 4 | 20.700 €/28,5% | 21.321 €/25% | 22.306 €/24,4% | 23.089 €/23,6% |
| 5 | 26.355 €/35% | 27.146 €/32% | 28.400 €/31,4% | 29.397 €/30,6% |
| 6 | 38.632 €/37% | 39.791 €/35,5% | 41.629 €/34,9% | 43.090 €/34,6% |
| 7 | 50.483 €/43,5% | 43.000 €/43,5% | 44.987 €/43,1% | 46.566 €/43,1% |
| 8 | 78.834 €/45% | 80.000 €/45% | 83.696 €/44,6% | 86.634 €/44,6% |
| 9 | Acima/48% | Acima/48% | Acima/48% | Acima/48% |

#### HIST002 Fontes e versão

2023: art 68.º redação Lei 24-D/2022 [T 23](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs68ra_202312.aspx). 2024: Lei 33/2024, art 2.º, publicação 07-08, entrada 08-08 [L 33](https://diariodarepublica.pt/dr/detalhe/lei/33-2024-875716577), e página AT rendimentos 2024 [AT 24](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/IRS/IRS_2024/Paginas/5_Deducoes_beneficios_taxas.aspx). 2025: art 68.º redação Lei 55-A/2025,22-07 [T 25](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs68ra_202512.aspx). 2026: DL 212/2026, arts 2.º–3.º [DL 212](https://diariodarepublica.pt/dr/detalhe/decreto-lei/212-2026-1190825214), [T 26](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs68.aspx). **CONFIRMADO** para as tabelas publicadas. A tabela 2026 inicial do OE 73-A/2025 tinha taxas 12,5%; 15,7%; 21,2%; 24,1%; 31,1%; 34,9%; 43,1%; 44,6%; 48%, mantendo os limites: guardar essa versão como histórica [T 26 ANT](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs68ra-202611.aspx).

#### HIST003 Precisão temporal 2026

DL 212/2026: publicação em 04-11 e entrada em vigor em 05-11, por artigo 3.º. A tabela deve ser considerada na estimativa da liquidação anual de 2026: o preâmbulo afirma aplicação desde 01-01, em consonância com o enquadramento anual e a versão atual do artigo 68.º publicada pela AT. A reserva documental é delimitada: não existe cláusula autónoma de produção de efeitos no dispositivo, nem na Lei 64-B/2026 de autorização. **CONFIRMADO** para as datas e o texto; a aplicação anual é interpretação sustentada nesses elementos, não transcrição do artigo 3.º. Na Madeira, o artigo 3.º do DLR 17/2026/M prevê expressamente efeitos desde 01-01-2026, com entrada em vigor em 06-11. Fontes: [DL 212](https://diariodarepublica.pt/dr/detalhe/decreto-lei/212-2026-1190825214), [AUT 64](https://diariodarepublica.pt/dr/detalhe/lei/64-b-2026-1184484177), [T 26](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs68.aspx), [MADEIRA 26](https://diariodarepublica.pt/dr/detalhe/decreto-legislativo-regional/17-2026-1191346240).

#### HIST004 Taxas médias e arredondamento

A tabela legal contém taxas médias com três decimais; a página AT 2025 publica também parcelas a abater. Um motor deve definir claramente método e arredondamento e conferir com a liquidação AT. Não presumir que misturar médias arredondadas, parcelas arredondadas e integração exata de faixas produz sempre os mesmos cêntimos. Em 2026, o n.º 2 do artigo 68.º na página AT ainda menciona 8.059 €, apesar do n.º 1 já ter 8.342 €; a incongruência consta também da versão anterior a novembro e não foi localizada retificação até 11-11-2026. O limite da tabela é 8.342 €: não criar um escalão adicional a partir de 8.059 €. A aplicação da taxa do primeiro escalão até 8.342 € segue o n.º 1; a harmonização com a referência residual do n.º 2 é interpretação, não correção formal do diploma. Fontes [T 26](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs68.aspx), [T 26 ANT](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs68ra-202611.aspx), [DL 212](https://diariodarepublica.pt/dr/detalhe/decreto-lei/212-2026-1190825214).

### IAS e múltiplos anuais

#### SS080 IAS e múltiplos legais

**CONFIRMADO.**

As portarias abaixo fixam o IAS com efeitos a 1 de janeiro do respetivo ano. Os múltiplos são cálculo aritmético; 1,5 IAS é apresentado com arredondamento monetário a duas casas, preservando o produto exato no cálculo interno até à política de arredondamento validada.

| **Ano** | **IAS** | **4 IAS** | **6 IAS** | **12 IAS** | **1,5 IAS, exibido** | **Diploma, art. 2.º / art. 4.º** |
| --- | --- | --- | --- | --- | --- | --- |
| 2023 | 480,43 € | 1 921,72 € | 2 882,58 € | 5 765,16 € | 720,65 € | [Portaria 298/2022](https://diariodarepublica.pt/dr/detalhe/portaria/298-2022-204841038) |
| 2024 | 509,26 € | 2 037,04 € | 3 055,56 € | 6 111,12 € | 763,89 € | [Portaria 421/2023](https://diariodarepublica.pt/dr/detalhe/portaria/421-2023-229857697) |
| 2025 | 522,50 € | 2 090,00 € | 3 135,00 € | 6 270,00 € | 783,75 € | [Portaria 6-B/2025/1](https://diariodarepublica.pt/dr/detalhe/portaria/6-b-2025-902111932) |
| 2026 | 537,13 € | 2 148,52 € | 3 222,78 € | 6 445,56 € | 805,70 € | [Portaria 480-A/2025/1](https://diariodarepublica.pt/dr/detalhe/portaria/480-a-2025-993056222) |

Parâmetros comuns de 2023–2026: taxas TI 21,4%; ENI/EIRL 25,2%; coeficientes serviços 70%, bens/hotelaria/restauração 20%; subsídio exploração 20% segundo ISS; mínimo mensal de 20 € publicado pelo ISS para o apuramento trimestral, com a divergência de indexação identificada em SS031; remanescente não considerado se contribuição <5 €; teto BIC 12 IAS; ajuste de −25% a +25%, passos de 5%. O teto deve usar o IAS do período contributivo, não o ano da fatura que alimenta o trimestre. Para CO, guardar ano do lucro, ano da declaração fiscal, data de fixação e período da base, com atualização dos limites anuais.

#### Taxas médias nacionais de IRS em 2026

Complemento à tabela histórica de taxas normais. O artigo 68.º, n.º 2, manda aplicar a taxa média ao limite do maior escalão inteiramente contido no rendimento e a taxa normal do escalão seguinte ao excedente. A referência residual a 8 059 € nesse n.º 2 diverge do primeiro limite de 8 342 € publicado no n.º 1; ver HIST004.

| **Limite superior do escalão em euros** | **Taxa normal** | **Taxa média legal** |
| --- | --- | --- |
| 8 342 | 12,20% | 12,200% |
| 12 587 | 15,20% | 13,212% |
| 17 838 | 20,70% | 15,416% |
| 23 089 | 23,60% | 17,277% |
| 29 397 | 30,60% | 20,136% |
| 43 090 | 34,60% | 24,732% |
| 46 566 | 43,10% | 26,103% |
| 86 634 | 44,60% | 34,658% |
| Superior a 86 634 | 48,00% | Não prevista |

**CONFIRMADO:** CIRS 68.º, n.º 1, na redação do DL 212/2026, arts. 2.º–3.º, em vigor desde 05-11-2026, com a nota temporal de HIST003. Fonte: [tabela oficial AT](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs68.aspx). Não substituir sem validação estas taxas médias, arredondadas pelo legislador a três casas, por uma parcela a abater calculada a partir de outro método.

#### Taxas médias nacionais de IRS de 2023 a 2025

Complemento às taxas normais e limites de HIST002. Cada taxa média corresponde ao limite superior do mesmo escalão e ano; os limites não são iguais entre anos. As taxas são as publicadas, com três casas decimais, sem reconstrução a partir das taxas normais.

| **Escalão** | **Média 2023** | **Média 2024** | **Média 2025** |
| --- | --- | --- | --- |
| 1 | 14,500% | 13,000% | 12,500% |
| 2 | 16,692% | 14,180% | 13,680% |
| 3 | 19,579% | 16,482% | 15,982% |
| 4 | 21,608% | 18,419% | 17,897% |
| 5 | 24,482% | 21,334% | 20,794% |
| 6 | 28,460% | 25,835% | 25,277% |
| 7 | 31,991% | 27,154% | 26,607% |
| 8 | 36,669% | 35,408% | 34,929% |
| 9 | Não prevista | Não prevista | Não prevista |

**CONFIRMADO:** CIRS 68.º/1–2. Em 2023, redação da Lei 24-D/2022, para esse exercício; em 2024, tabela final da Lei 33/2024, arts. 2.º–3.º, em vigor desde 08-08-2024; em 2025, tabela final da Lei 55-A/2025, de 22 de julho. Fontes: [CIRS 68.º em 2023](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs68ra_202312.aspx), [Lei 33/2024](https://diariodarepublica.pt/dr/detalhe/lei/33-2024-875716577), [CIRS 68.º em 2025](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/ra/Pages/irs68ra_202512.aspx). Usar a versão final do ano na liquidação anual; conservar as versões anteriores para auditoria.

#### Taxas gerais de IRS da Madeira de 2023 a 2026

Limites e taxas normais e médias das tabelas gerais finais de cada ano, aplicáveis aos residentes fiscais abrangidos. As deduções e os benefícios regionais exigem a sua própria análise. O ano do rendimento e o da entrega da declaração são campos distintos.

##### Madeira 2023

Base: artigo 17.º do Decreto Legislativo Regional n.º 26/2022/M, que altera o artigo 2.º do Decreto Legislativo Regional n.º 3/2001/M. Entrada em vigor em 30-12-2022; efeitos em 01-01-2023, artigo 106.º. A Retificação n.º 1/2023/M refere o anexo do artigo 83.º, sem alterar a tabela de IRS. Estado: **CONFIRMADO**.

| **Limite superior em euros** | **Taxa normal por cento** | **Taxa média por cento** |
| --- | --- | --- |
| 7 479 | 10,15 | 10,150 |
| 11 284 | 14,70 | 11,684 |
| 15 992 | 18,55 | 13,706 |
| 20 700 | 19,95 | 15,126 |
| 26 355 | 29,75 | 18,264 |
| 38 632 | 33,67 | 23,160 |
| 50 483 | 42,20 | 27,629 |
| 78 834 | 43,65 | 33,391 |
| Superior a 78 834 | 47,52 | — |

Fontes: [DLR 26/2022/M, artigos 17.º e 106.º](https://diariodarepublica.pt/dr/detalhe/decreto-legislativo-regional/26-2022-205450899); [original, página 33 do DR](https://files.diariodarepublica.pt/1s/2022/12/25000/0002700169.pdf); [Retificação 1/2023/M](https://diariodarepublica.pt/dr/detalhe/declaracao-retificacao/1-2023-206056782). A tabela foi ainda confrontada com a página 1 do [folheto da AT Madeira](https://at.madeira.gov.pt/ficheiros/RAM-TaxasBeneficios.pdf); outras páginas desse folheto contêm informação de IRS Jovem de 2022 e não servem para fixar esse benefício em 2023.

##### Madeira 2024

Base: artigo 2.º do Decreto Legislativo Regional n.º 7/2024/M, que altera o artigo 2.º do Decreto Legislativo Regional n.º 3/2001/M. Entrada em vigor em 24-10-2024; efeitos expressos em 01-01-2024, artigo 3.º. Estado: **CONFIRMADO** para a tabela final do ano.

| **Limite superior em euros** | **Taxa normal por cento** | **Taxa média por cento** |
| --- | --- | --- |
| 7 703 | 9,10 | 9,100 |
| 11 623 | 11,55 | 9,926 |
| 16 472 | 15,40 | 11,538 |
| 21 321 | 17,50 | 12,894 |
| 27 146 | 22,40 | 14,934 |
| 39 791 | 32,31 | 20,454 |
| 43 000 | 42,20 | 22,076 |
| 80 000 | 43,65 | 32,054 |
| Superior a 80 000 | 47,52 | — |

Fonte: [DLR 7/2024/M, artigos 2.º e 3.º](https://diariodarepublica.pt/dr/detalhe/decreto-legislativo-regional/7-2024-892301185). As versões publicadas antes desta alteração devem ser conservadas para auditoria, sem as usar como tabela final de rendimentos de 2024.

##### Madeira 2025

Base: artigo 2.º do Decreto Legislativo Regional n.º 3/2025/M, que altera o artigo 2.º do Decreto Legislativo Regional n.º 3/2001/M. Entrada em vigor em 29-07-2025; efeitos expressos em 01-01-2025, artigo 3.º. Estado: **CONFIRMADO** para a tabela final do ano.

| **Limite superior em euros** | **Taxa normal por cento** | **Taxa média por cento** |
| --- | --- | --- |
| 8 059 | 8,75 | 8,750 |
| 12 160 | 11,20 | 9,576 |
| 17 233 | 15,05 | 11,188 |
| 22 306 | 17,08 | 12,528 |
| 28 400 | 21,98 | 14,556 |
| 41 629 | 24,43 | 17,694 |
| 44 987 | 36,64 | 19,108 |
| 83 696 | 40,59 | 29,041 |
| Superior a 83 696 | 46,56 | — |

Fonte: [DLR 3/2025/M, artigos 2.º e 3.º](https://diariodarepublica.pt/dr/detalhe/decreto-legislativo-regional/3-2025-926481290).

Não aplicar um multiplicador regional uniforme às taxas de 2023–2025. As taxas normais e médias publicadas devem ser guardadas como parâmetros próprios; uma taxa média arredondada não deve ser substituída silenciosamente por uma taxa média reconstruída.

##### Madeira 2026

Base: artigo 2.º do Decreto Legislativo Regional n.º 17/2026/M, que altera o artigo 2.º do Decreto Legislativo Regional n.º 3/2001/M. Entrada em vigor em 06-11-2026; efeitos expressos em 01-01-2026, artigo 3.º. Estado: **CONFIRMADO** para a tabela publicada em novembro e aplicável ao ano de 2026.

| **Limite superior em euros** | **Taxa normal por cento** | **Taxa média por cento** |
| --- | --- | --- |
| 8 342 | 8,54 | 8,540 |
| 12 587 | 10,64 | 9,248 |
| 17 838 | 14,49 | 10,791 |
| 23 089 | 16,52 | 12,094 |
| 29 397 | 21,42 | 14,095 |
| 43 090 | 24,22 | 17,313 |
| 46 566 | 30,17 | 18,272 |
| 86 634 | 31,22 | 24,261 |
| Superior a 86 634 | 33,60 | — |

Fonte: [DLR 17/2026/M, artigos 2.º e 3.º](https://diariodarepublica.pt/dr/detalhe/decreto-legislativo-regional/17-2026-1191346240). As taxas normais correspondem à redução regional de 30%, mas as taxas médias são os valores específicos publicados. Por exemplo, a média legal de 17,313% não deve ser substituída pelo produto 24,732% × 70% = 17,3124%. Não confundir uma relação entre taxas normais com autorização para reconstruir todas as médias ou multiplicar a liquidação final por 70%.

#### Parâmetros de IVA e faturação

Valores monetários em euros, sem IVA. «Igual» significa continuidade do parâmetro indicado, sem dispensar as condições da regra. Os limites são distintos: entrada num regime, saída, volume nacional, volume União, compras e vendas não devem partilhar um único contador.

| **Parâmetro** | **2023** | **2024** | **2025** | **2026** |
| --- | --- | --- | --- | --- |
| Limite nacional art. 53.º | 13 500 | 14 500 | 15 000 | 15 000 |
| Saída imediata por excesso art. 58.º | Regra nova não aplicável | Regra nova não aplicável | >18 750; transição de julho | >18 750 |
| Limite União para isenção EX | Regime não aplicável | Regime não aplicável | ≤100 000 | ≤100 000 |
| Mensalidade normal obrigatória | ≥650 000 | ≥650 000 | ≥650 000 | ≥650 000 |
| Vínculo da opção mensal | 3 anos | 3 anos | Removido desde julho | Alteração em janeiro |
| Teto do IVA de caixa | 500 000 | 500 000 | 2 000 000 desde julho | 2 000 000 |
| Vínculo da opção de caixa | 2 anos civis | Igual | Igual | Igual |
| Diferimento geral de caixa | 12 meses | Igual | Igual | Igual |
| Vínculo da opção normal art. 55.º | 5 anos | Igual | Igual | Igual |
| Gatilho de software por volume | >50 000 | >50 000 | >50 000 | >50 000 |
| Aplicação AT como alternativa geral | Ver regime anterior | Ver regime anterior | Art. 4.º-A desde julho | Permitida |
| Derrogação de compras de bens UE | 10 000 | 10 000 | 10 000 | 10 000 |
| Serviços estrangeiros B2B | Sem franquia geral | Igual | Igual | Igual |
| B2C digital e vendas à distância UE | 10 000 conjuntos | Igual | Igual | Igual |
| IOSS por remessa | ≤150 | ≤150 | ≤150 | ≤150 |
| Recapitulativa mensal por bens | >50 000 por trimestre | Igual | Igual | Igual |
| Simplificada em certas vendas B2C | ≤1 000 | ≤1 000 | ≤1 000 | ≤1 000 |
| Simplificada nas restantes operações | ≤100 | ≤100 | ≤100 | ≤100 |
| Simplificada do art. 53.º | Teto anterior | Teto anterior | Sem teto na regra nova | Sem teto na regra própria |
| QR em programas certificados | Obrigatório | Igual | Igual | Igual |
| ATCUD em documentos abrangidos | Desde janeiro | Obrigatório | Obrigatório | Obrigatório |
| Comunicação e-Fatura base | Dia 5 seguinte | Igual | Igual | Igual |
| Comunicação de séries | Antes de utilizar | Igual | Igual | Igual |
| Dispensa de comunicação transporte | Volume ≤100 000 | Igual | Igual | Igual |
| Conservação fiscal geral | 10 anos civis seguintes | Igual | Igual | Igual |

**Base e estado:** CONFIRMADO com as condições e datas das regras IVA07–IVA29 e FAT02–FAT10. A reforma de 2025 entrou em vigor em 29 de março, com transição específica do DL 35/2025 para julho. No novo art. 53.º, 15 000 € exatos não excedem o teto e 18 750 € exatos não acionam a saída imediata; acima de 15 000 € sem exceder 18 750 € a tributação começa em janeiro seguinte. A previsão de início deixou de ser anualizada para o art. 53.º, mas a anualização mantém-se nos testes de periodicidade e software. O limiar da recapitulativa diz respeito aos bens indicados na norma, no trimestre em curso ou nos quatro anteriores.

Fontes: [CIVA art. 53.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/artigo-53-o-do-civa.aspx), [histórico até março de 2025](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/ra/Pages/iva53ra_202503.aspx), [DL 35/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/35-2025-912066244), [CIVA 41.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva41.aspx), [DL 34/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/34-2025-912066243), [DL 49/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/49-2025-912653926), [RITI 5.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti005.aspx), [CIVA 6.º-A](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva6a.aspx), [RITI 30.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti030.aspx), [DL 28/2019](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-lei/2019-119662485), [Portaria 195/2020](https://diariodarepublica.pt/dr/detalhe/portaria/195-2020-140210523).

#### Taxas territoriais de IVA

| **Território** | **2023** | **2024** | **2025** | **2026** |
| --- | --- | --- | --- | --- |
| Continente | 6% / 13% / 23% | Igual | Igual | Igual |
| Açores | 4% / 9% / 16% | Igual | Igual | Igual |
| Madeira | 5% / 12% / 22% | 4% / 12% / 22% desde outubro | 4% / 12% / 22% | 4% / 12% / 22% |

Ordem: reduzida, intermédia e normal. A tabela não substitui a classificação nas listas nem cobre todas as isenções temporárias por produto. **CONFIRMADO:** CIVA 18.º e DLR 6/2024/M, arts. 21.º e 121.º/2; ver IVA06 e respetivas fontes oficiais.

#### Parâmetros contributivos comuns aos quatro anos

| **Parâmetro** | **Valor 2023 a 2026** | **Base legal** |
| --- | --- | --- |
| Taxa TI geral | 21,4% | CRC 168.º/1 |
| Taxa ENI/EIRL | 25,2% | CRC 168.º/4 |
| RR de serviços | 70% | CRC 162.º/1/a |
| RR de bens e atividades equiparadas | 20% | CRC 162.º/1/b e 2 |
| Mínimo contributivo trimestral publicado pelo ISS | 20 € por mês | CRC 163.º/2; divergência não resolvida com n.º 8 |
| Ajuste opcional | −25% a +25%, passos de 5% | CRC 164.º |
| Teto mensal | 12 IAS | CRC 163.º/5 |
| Mínimo mensal no lucro CO | 1,5 IAS | CRC 163.º/3 |
| Isenção por acumulação | RR mensal <4 IAS | CRC 157.º/1/a |
| Remanescente por acumulação | RR mensal menos 4 IAS | CRC 163.º/4 |
| Remanescente que não releva | Contribuição <5 € | Despacho 599/2019/1 |
| Serviços mínimos para entidade contratante | ≥6 IAS por ano | CRC 140.º/2 |
| Entidade contratante | 7% se >50% e ≤80%; 10% se >80% | CRC 140.º e 168.º/7 |

**CONFIRMADO**, com as condições e exceções dos blocos SS. A extensão das entidades contratantes aos serviços ENI/EIRL produz efeitos desde 01-05-2023, não desde o início desse ano. A contribuição mínima de 20 € mantém a divergência normativa/operacional assinalada em SS031. Fontes: [CRC](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [Despacho 599/2019](https://files.diariodarepublica.pt/2s/2019/01/008000000/0152401524.pdf), [Lei 13/2023](https://diariodarepublica.pt/dr/detalhe/lei/13-2023-211340863).

#### Contabilidade juros e regras de prazo

As categorias SNC atuais e a data de mudança constam de CONT04. O quadro seguinte apresenta os limites gerais, pela ordem balanço, volume de negócios líquido e número médio de empregados. Aplicam-se os testes de dois dos três limites e as regras de transição do artigo 9.º-A. Outras definições de PME têm finalidades e limites próprios.

| **Categoria SNC** | **2023** | **2024** | **2025** | **2026** |
| --- | --- | --- | --- | --- |
| Microentidade | 350 000 € / 700 000 € / 10 | Igual | Igual | 450 000 € / 900 000 € / 10 |
| Pequena entidade | 4 000 000 € / 8 000 000 € / 50 | Igual | Igual | 5 000 000 € / 10 000 000 € / 50 |
| Média entidade | 20 000 000 € / 40 000 000 € / 250 | Igual | Igual | 25 000 000 € / 50 000 000 € / 250 |

**CONFIRMADO:** DL 158/2009, artigo 9.º, na redação do [DL 98/2015](https://diariodarepublica.pt/dr/detalhe/decreto-lei/98-2015-67356342), aplicável desde 2016; valores de 2026 pelo [DL 126-B/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/126-b-2025-964514221), artigos 2.º–3.º, para exercícios iniciados em ou após 01-01-2026. As taxas anuais de juros de mora e os avisos estão em INF03; não se confundem com juros comerciais ou compensatórios.

| **Parâmetro** | **2023** | **2024** | **2025** | **2026** |
| --- | --- | --- | --- | --- |
| Juros de mora das dívidas ao Estado | 5,997% | 8,876% | 8,309% | 7,221% |
| Limite geral IRS simplificado | 200 000 € | 200 000 € | 200 000 € | 200 000 € |
| Excesso de um ano que força CO seguinte | >250 000 € | >250 000 € | >250 000 € | >250 000 € |
| DP normal IVA | Dia 20 do 2.º mês | Igual | Igual | Igual |
| Pagamento normal IVA | Dia 25 do 2.º mês | Igual | Igual | Igual |
| DQ SS | Janeiro abril julho outubro | Igual | Igual | Igual |
| Pagamento TI CRC 155.º | Dias 10–20 seguintes | Igual | Igual | Igual, com divergência informativa |

**CONFIRMADO**, sem prejuízo dos calendários e prorrogações anuais e das condições de cada obrigação. Fontes: [CIRS 28.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx), [CIVA 27.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva27.aspx), [CIVA 41.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva41.aspx), CRC e avisos anuais ligados em INF03.

## 9 Árvore de decisão de enquadramento

Esta árvore é uma síntese das regras citadas nos capítulos anteriores. As suas folhas são resultados parciais que se acumulam: a opção de IRS não decide o IVA, e uma isenção de Segurança Social não elimina as obrigações fiscais. Em cada ramo, conservar a evidência dos factos e a opção efetivamente exercida.

#### Identificar a pessoa e a atividade

**A. A pessoa exerce atividade independente habitual?** Se sim, identificar residência fiscal, estabelecimento e datas de início/reinício; apresentar declaração antes de iniciar. Se a operação for efetivamente ocasional, aplicar a análise de ato isolado e não atribuir automaticamente a isenção do art. 53.º do CIVA. Para não residentes, destacamento ou atividade transfronteiriça permanente, obter primeiro o enquadramento territorial.

**B. Qual a natureza de cada receita?** Separar venda de bens, profissão especificamente prevista na tabela do art. 151.º, outros serviços, restauração/hotelaria, AL, subsídios, direitos e ganhos patrimoniais. Registar CAE e CIRS, mas decidir a natureza com base nos factos. Uma atividade mista pode exigir vários coeficientes e tratamentos na mesma declaração.

**C. Há enquadramento contributivo especial?** Se CPAS, proteção estrangeira aplicável, profissão cultural ou outra exclusão legal, encaminhar para o regime respetivo. Caso contrário, confirmar com o ISS se a forma de exercício cai no TI geral a 21,4% ou em ENI/EIRL a 25,2%. Ter um CAE não basta para decidir a taxa.

#### Determinar o regime de IRS

**D. Existe opção válida por CO ou ultrapassagem obrigatória?** Se houve opção no início ou até 31 de março, aplicar CO desde o período legal. Na ausência de opção, testar o art. 28.º: limite de 200 000 €, dois anos consecutivos de excesso ou um ano com excesso superior a 25%. A passagem obrigatória produz efeitos no ano seguinte. Preservar o histórico dos dois anos, e não apenas a faturação corrente.

**E. Simplificado:** aplicar o coeficiente de cada receita. Para as alíneas b), c) e f) do art. 31.º/1, verificar a redução inicial de 50%/25%, a ausência de rendimentos A/H no ano e a barreira de cessação nos cinco anos anteriores. Para b)/c), executar a comprovação de despesas de 15%, a dedução legal automática e as regras de contribuições; não deduzir simplesmente todas as despesas reais.

**F. CO:** apurar lucro contabilístico e correções fiscais, observando CIRS 32.º/33.º e as remissões aplicáveis ao CIRC. Determinar separadamente tributações autónomas, prejuízos e obrigações de CC, IES, dossier e inventários. A existência material de contabilidade e a opção pelo regime fiscal são campos distintos.

**G. Há IRS Jovem ou outro benefício?** Verificar idade, dependência, anos relevantes, residência, benefícios incompatíveis e situação tributária. A admissão do benefício também em CO está confirmada. Manter o rendimento isento sem deduções para a determinação das taxas, nos termos do artigo 22.º/7/a; a referência ao bruto na PIV 28155 corresponde ao caso de serviços em regime simplificado aí analisado. Não duplicar o teto por existirem A e B. Rateio entre categorias e interações com coeficientes, despesas, contribuições e gastos CO ainda não fechados exigem revisão.

**H. Quem paga está obrigado a reter?** Se sim, classificar a natureza do rendimento e testar dispensa facultativa, rendimentos anteriores, limiar atingido e data do pagamento. A retenção é um adiantamento; não é a taxa final de IRS. Avaliar PPC com os dados do penúltimo ano e englobar com as restantes categorias na liquidação anual.

#### Determinar o IVA de cada operação

**I. A operação beneficia do art. 9.º?** Se sim, testar os requisitos materiais e eventual renúncia admitida. Se não, calcular a elegibilidade do art. 53.º com o volume nacional relevante e restantes condições, independentemente de IRS simplificado ou CO.

**J. Art. 53.º aplicável:** faturar com a menção legal, sem dedução nas operações isentas. Até 15 000 € mantém-se o requisito de volume; acima de 15 000 € até 18 750 € prepara-se a saída no início do ano seguinte; acima de 18 750 € há saída imediata, com o tratamento da operação de ultrapassagem indicado pela AT. Não aplicar retroativamente este mecanismo aos anos anteriores à reforma.

Uma exportação de bens ou atividade conexa incompatível provoca saída imediata mesmo com volume baixo e mesmo ocorrendo uma só vez. A exportação pode ter a sua própria isenção do artigo 14.º. Um serviço B2B não localizado em Portugal não deve ser classificado como exportação de bens apenas por o cliente ser estrangeiro; ver IVA08 e os casos VAT05/VAT06.

**K. Regime normal:** escolher periodicidade por volume do art. 41.º, opção e causas especiais; mensal a partir de 650 000 €. Determinar taxa por localização e bem/serviço, direito à dedução, DP e pagamento. Se existe opção válida pelo IVA de caixa, ativar as suas regras próprias de exigibilidade e dedução.

**L. Há cliente estrangeiro?** B2B: identificar o estabelecimento destinatário e verificar a regra geral ou exceção; se UE, validar estatuto, VIES e recapitulativa quando devida. B2C: verificar regras de localização, serviços digitais e vendas à distância; testar o limiar conjunto de 10 000 €, OSS/IOSS e eventual isenção EX por país. Um país do cliente, isoladamente, não determina isenção.

**M. Há compras ao estrangeiro?** Testar sempre, mesmo com art. 9.º/53.º. Serviços B2B podem exigir autoliquidação desde a primeira compra; bens intracomunitários têm o seu próprio limite de 10 000 €. Identificar declaração e pagamento de acordo com a versão legal da data, incluindo a alteração de julho de 2026 para os sujeitos abrangidos pelo art. 27.º, n.º 3, que não estejam obrigados à declaração periódica do art. 41.º.

#### Determinar a Segurança Social

**N. O primeiro enquadramento já produz efeitos?** Se não, determinar o primeiro dia do 12.º mês posterior ao início, eventuais interrupções/reinícios e antecipação pedida. Usar a data notificada quando a combinação factual não estiver fechada. Se já produziu efeitos, reinício gera obrigação desde o início do mês do reinício, ressalvadas isenções.

**O. Há pensão compatível ou outra isenção?** Se sim, reconhecer o motivo e a data próprios. Se há trabalho dependente, testar simultaneamente entidades distintas sem grupo/domínio, proteção completa e remuneração média pelo menos igual ao IAS. Sem todas as condições, o emprego não afasta por si as contribuições TI.

**P. Cumpridas as condições de acumulação:** RR mensal inferior a 4 IAS → isenção; RR igual ou superior a 4 IAS → base correspondente ao excedente, sem ajuste de 25% e sem mínimo de 20 €. Contribuição inferior a 5 € não releva; na igualdade de 4 IAS a base é zero. A obrigação de DQ na igualdade decorre de interpretação conjugada e mantém ressalva operacional. Uma contribuição matemática de 4,99904 € exige confirmação do passo de arredondamento antes de decidir a fronteira de 5 €.

**Q. Sem isenção:** modelo trimestral → 70% dos serviços e 20% dos bens/atividades equiparadas, dividido por três, com opção de ajuste e limites; modelo CO → lucro tributável de referência dividido por doze, mínimo de 1,5 IAS e máximo de 12 IAS. Verificar opção de CO para apuramento trimestral. Aplicar a taxa do enquadramento pessoal e guardar a notificação da base.

**R. Há concentração de clientes?** Testar serviços anuais pelo menos iguais a 6 IAS, sujeição contributiva, participação superior a 50% e agregação de empresas do grupo. A entidade contratante paga 7% ou 10% sobre os serviços relevantes; este encargo não é uma retenção dedutível da contribuição do prestador.

#### Produzir as obrigações resultantes

No fim, gerar uma lista de obrigações com fundamento individual: documentos por operação; comunicação mensal; DP, recapitulativa, EX ou OSS/IOSS quando devidos; Modelo 3 e anexos; DQ e confirmação anual; pagamentos SS e PPC; IES, dossier e inventários na CO; obrigações enquanto pagador de rendimentos. Para cada obrigação, guardar o motivo de incidência ou de dispensa. Uma folha da árvore com valor zero não é prova de inexistência de declaração.

## 10 Alterações recentes e futuras

As datas e normas completas constam dos blocos de regras. A tabela funciona como índice de atualização; proposta confirmada não equivale a lei aprovada.

| **Alteração** | **Efeito relevante** | **Situação no corte** |
| --- | --- | --- |
| Lei 24-D/2022 | Escada do limite art. 53.º em 2023–2025; férias contributivas de agosto | Lei aplicável nos respetivos períodos |
| Lei 13/2023 | Extensão das entidades contratantes a ENI/EIRL desde maio de 2023 | Lei |
| Leis 32/2024, 33/2024 e 34/2024 | Dedução específica, escalões e mínimo de existência de 2024, respetivamente | Leis distintas; não intercambiar referências |
| DLR 6/2024/M | IVA reduzido Madeira de 5% para 4% desde outubro de 2024 | Lei |
| Lei 45-A/2024 | IRS Jovem de dez anos, retenção profissional 23%, PPC 65%, alterações a TA e parâmetros de 2025 | Lei com efeitos próprios desde 2025 |
| DL 33/2025 | Localização de eventos virtuais | Lei |
| DL 34/2025 | Limite de IVA de caixa 2 milhões desde julho de 2025 | Lei |
| DL 35/2025 | Reforma art. 53.º, isenção EX, volumes e transição de 2025 | Lei; aplicação temporal por artigo |
| DL 49/2025 | Simplificação de obrigações, Modelo 10, dispensa de retenção inferior a 25 €, aplicações AT, registos do CIVA 50.º, eliminação dos anexos O/Q e opção de IVA mensal | Lei; efeitos relevantes em julho de 2025 |
| DL 126-B/2025 | Novos limites de categorias SNC para exercícios iniciados desde janeiro de 2026 | Lei |
| DL 127/2025 | Pagamentos de entidades empregadoras até dia 25 | Lei; não é alteração localizada do art. 155.º dos TI |
| Lei 73-A/2025 e Portaria 480-A/2025/1 | OE 2026 e IAS 537,13 €; inventários e calendário digital | Lei |
| DL 97/2026 | Habitação, dedução de rendas e declaração/autoliquidação nas condições do art. 27.º/3 do CIVA | Lei; efeitos distintos em janeiro, julho e setembro |
| DL 212/2026 e DLR 17/2026/M | Novas taxas IRS publicadas em novembro; Madeira com efeitos expressos a janeiro | Lei; ver nota sobre redação temporal nacional |
| Lei 65/2026 e Retificação 39-B/2026/1 | Construção civil e mensalidade de revendedores de combustíveis a partir de janeiro de 2027 | Lei aprovada com efeitos futuros |
| OE 2026 art. 95.º/2 | SAF-T contabilístico de 2027, entrega em 2028 | Lei em vigor no corte |
| OE 2026 art. 95.º/3 | Aceitação fiscal de PDF até dezembro de 2026 | Lei em vigor no corte |
| Proposta OE 2027 art. 77.º/2–3 | SAF-T de 2028, entrega em 2029; prolongamento PDF até dezembro de 2027 | PROPOSTA; NÃO CONFIRMADO como direito aprovado |
| Portaria 298/2026/1, art. 4.º/2–3, e Ofício 25119/2026 | Modelos IVA: aplicação geral a períodos desde julho de 2027; identificação de grupos IVA e regularizações da verba 2.42 desde julho de 2026 | Datas confirmadas; integração técnica por verificar |

Fontes principais de alterações futuras: [OE 2026](https://diariodarepublica.pt/dr/detalhe/lei/73-a-2025-993270096), [Lei 65/2026](https://diariodarepublica.pt/dr/detalhe/lei/65-2026-1191346238), [retificação](https://diariodarepublica.pt/dr/detalhe/declaracao-retificacao/39-b-2026-1192129277), [proposta OE 2027](https://app.parlamento.pt/OE2027/HTML/OrcamentoEstado2027.html), [Ofício 25119/2026](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio-Circulado-25119-2026.pdf).

## 11 Divergências e matérias a validar

#### Interpretação e aplicação a perfis concretos

1. **CAE e CIRS:** profissão efetivamente exercida, atividade comercial/industrial, enquadramento ENI de atividades mistas e correspondências da CAE Rev.4. Não existe uma equivalência universal entre código fiscal e taxa contributiva.
1. **IRS Jovem:** ordem operacional de isenção, coeficientes, despesas de 15%, contribuições e reduções iniciais quando coexistem. A ficha AT confirma o rendimento bruto como referência no caso analisado, mas não resolve todas as combinações.
1. **Rendimentos por plataformas:** contrapartida, atuação em nome próprio ou por conta de terceiro, comissões, subsídios, direitos de autor e momento relevante de declaração. O nome comercial da plataforma não qualifica o rendimento.
1. **Atividades internacionais:** estatuto e estabelecimento do cliente, serviços automatizados versus personalizados, utilização efetiva, retenção na fonte estrangeira, convenções, proteção social aplicável e registo de clientes estrangeiros como entidades contratantes.
1. **Organização contabilística:** aplicação concreta do SNC ao profissional liberal, existência de contabilidade versus opção fiscal, transições para micro/pequena entidade e desfasamento do regime contributivo CO.
1. **Tributação final de IRS:** agregado, opção conjunta, deduções pessoais e globais, mínimo de existência, perdas, deficiência, residência parcial, RNH/IFICI/ex-residentes e regimes regionais. Os módulos não integralmente parametrizados não podem ser substituídos por uma taxa efetiva estimada apresentada como liquidação legal.

#### Divergências documentais identificadas

1. **Exportações e art. 53.º — resolvido quanto à regra:** a FAQ AT emprega «exclusivamente», mas o articulado e o exemplo 7 do Ofício 25062/2025 confirmam que uma única exportação de bens faz cessar a isenção. A formulação da FAQ permanece registada como divergência documental; deixou de justificar um bloqueio geral de cálculo.
1. **Pagamentos SS até dia 25:** o art. 155.º/2 mantém 10–20 e a página gov.pt da responsabilidade do ISS, atualizada em maio de 2026, confirma esse intervalo. A alteração do art. 43.º respeita às entidades empregadoras. A notícia de 2026 sobre dia 25 não tem fundamento normativo localizado para TI. Guardar o vencimento da cobrança e pedir o fundamento de eventual divergência; esse documento não prova uma mudança geral da lei.
1. **Mínimo SS de 20 €:** confirmado como valor operacional publicado pelo ISS; a compatibilidade com a atualização prevista no art. 163.º/8 continua por explicar. O Despacho 599/2019 regula outros limiares e não congela o mínimo geral. Não criar uma atualização por cálculo histórico autónomo nem declarar a indexação legal inexistente.
1. **Erros de exemplos ISS:** antecipação no próprio mês versus mês seguinte; BIC versus contribuição de 20 €; teto aritmético de 12 IAS; mínimo indevido de 20 € no remanescente; limiar de revisão anual de 20 € de base. As regras SS identificam a norma e a passagem divergente.
1. **Reinício e isenção do mínimo:** os casos de reinício fora da janela legal descrita e o reconhecimento da isenção após um ano apenas parcialmente abrangido pelo mínimo não estão fechados para decisão automática universal.
1. **Consolidações e efeitos futuros:** a AT já incorpora a Lei 65/2026 com efeitos em 2027. A aplicação anual de 2026 da tabela nacional IRS de novembro é sustentada no preâmbulo e no sistema de liquidação anual; o dispositivo não contém cláusula autónoma de efeitos a janeiro. A Madeira contém essa cláusula. Preservar a diferença sem transformar toda a tabela nacional numa regra indeterminada. A referência residual a 8 059 € no artigo 68.º/2 também não substitui o primeiro limite de 8 342 € do n.º 1.
1. **Anexo SS dos ENI/EIRL:** as instruções do quadro 6 da Portaria 249/2021 mandam assinalar «Não», enquanto o CRC 140.º-A estende as entidades contratantes a estes prestadores desde maio de 2023. A OCC reproduz a orientação do formulário num parecer de março de 2026. A regra legal da entidade contratante está confirmada; o preenchimento operacional coerente com a lei exige esclarecimento ISS/AT. Dispensa da DQ, resposta ao quadro 6 e dispensa do anexo inteiro são questões distintas.

#### Cobertura que permanece incompleta

1. **Histórico integral:** as tabelas gerais nacionais e da Madeira de 2023–2026 estão documentadas, incluindo taxas médias. A memória não certifica todos os parâmetros setoriais, todas as deduções ou benefícios regionais, nem a data inaugural de cada disposição estrutural.
1. **Regimes especiais de IVA:** listas por produto, usados/arte, agências, pequenos retalhistas, forfetário agrícola, grupos, créditos incobráveis/duvidosos e certas obras/imóveis exigem módulos próprios. O mapa de aplicabilidade não substitui as respetivas regras completas.
1. **Formulários e dossier:** a lista base do dossier fiscal está confirmada pela Portaria 51/2018 e discriminada em CONT09. O CC deve selecionar os elementos condicionais aplicáveis. Confirmar a versão e o esquema técnico de cada declaração no ano de submissão; números de campos não devem ser perpetuados. As datas dos modelos IVA da Portaria 298/2026/1 já estão identificadas, sem equivaler a ensaio da submissão eletrónica.
1. **Obrigações adicionais:** património, veículos, empregados, seguros profissionais, imposto do selo, importações e autorizações setoriais podem gerar outros deveres. Não foram integralmente parametrizados nesta memória os impostos patrimoniais, o processamento salarial, a CPAS, o regime dos profissionais da cultura ou a coordenação internacional de Segurança Social.
1. **Juros e sanções:** as taxas e os tipos legais estão documentados, mas a liquidação concreta depende de períodos, notificações, culpa, regularização, garantias, execução e regras de arredondamento. A aplicação deve distinguir uma estimativa do montante liquidado pela autoridade.

#### Requisitos técnicos derivados das fontes

Estas recomendações são de implementação. Cada resultado deve conter rule_id, tax_year, effective_from, effective_to, source_url, legal_reference, evidence_type, verified_at, factos de entrada, opção do contribuinte e memória do cálculo. Preservar versões anteriores e notificações em vez de substituir o histórico.

Os testes de fronteira devem cobrir igualdade e ultrapassagem dos limites: 15 000/18 750 € de IVA, 200 000/250 000 € de IRS, 4/6/12 IAS, quotas de cliente de 50%/80%, contribuição remanescente de 5 €, retenção de 25 €, passagem de ano e normas com efeitos a meio do ano. Testar também CO com art. 53.º, isento que compra serviços estrangeiros, acumulação com salário, pensão, reinício e atividade mista.

Nos casos marcados **NÃO CONFIRMADO**, apresentar o motivo concreto e impedir que a parcela afetada seja rotulada como cálculo fiscal exato. As partes independentes e confirmadas podem continuar a ser calculadas com a sua própria fundamentação.

## 12 Validação adicional e casos de aceitação

### Resultado da segunda verificação

Revisão dirigida às dúvidas identificadas na primeira versão, com corte em **11-11-2026**. Os capítulos anteriores já incorporam as correções; este capítulo conserva a decisão, os casos de fronteira e as perguntas que ainda precisam de resposta. Uma orientação administrativa ou profissional mantém esse estatuto mesmo quando permite uma decisão operacional.

| **Matéria** | **Resultado da validação** | **Consequência para a aplicação** |
| --- | --- | --- |
| Exportação ocasional e artigo 53.º | CONFIRMADO: uma exportação de bens faz perder a isenção, sem exceção geral de frequência ou valor | Resolver a elegibilidade pela lei; tratar a eventual isenção da própria exportação pelo artigo 14.º |
| Limites de IVA, início e CO | CONFIRMADO: limites de 15 000/18 750 €, previsão sem anualização e compatibilidade com CO na redação atual | Contadores e regras por versão legal; tributar integralmente a operação de ultrapassagem quando devida |
| IRS Jovem em CO | Admissão CONFIRMADA; sequência completa de cálculo NÃO CONFIRMADA | Permitir o perfil; não extrapolar a PIV do regime simplificado para o lucro CO |
| Tabelas gerais de IRS | CONFIRMADAS as tabelas finais nacionais e da Madeira de 2023–2026, incluindo médias | Guardar parâmetros publicados por território e ano; preservar a nota textual do artigo 68.º/2 |
| Dossier fiscal | CONFIRMADA a lista base do Anexo I da Portaria 51/2018 | Selecionar mapas por operação; submeter os elementos condicionais à classificação do CC |
| Novos limites SNC | Datas e limites CONFIRMADOS; transição concretizada por DOUTRINA profissional OCC | Avaliar dados dos períodos anteriores para a classificação de 2026; não reclassificar 2025 |
| Pagamento SS e mínimo | Prazo legal TI 10–20 CONFIRMADO; mínimo de 20 € publicado pelo ISS; dúvidas localizadas no dia 25 e na indexação | Manter fundamento legal e evidência operacional separados |
| Anexo SS ENI/EIRL | Extensão legal CONFIRMADA; instruções de 2021 conflitantes | Não afastar entidades contratantes pela etiqueta ENI; rever o preenchimento do quadro 6 |
| Novos formulários IVA | Datas da Portaria 298/2026/1 CONFIRMADAS | Selecionar a versão pelo período; verificar o esquema e o canal ao desenvolver a submissão |

As fontes e a vigência estão em IVA08–IVA12, IVA30, ENG 02, JOV004–JOV008, HIST002–HIST004, CONT04/CONT09, SS031/SS055/SS060–SS062 e nas tabelas do capítulo 8. Não é necessário pedir esclarecimento genérico para reabrir uma regra já confirmada, como a perda do artigo 53.º por exportação de bens. Contratos ou operações sem factos suficientes continuam a exigir classificação própria.

### Como utilizar os casos de aceitação

Os **44 casos** seguintes especificam resultados parciais, datas ou a necessidade de revisão. Não representam 44 liquidações finais de IRS nem uma execução de testes sobre uma aplicação concreta. As variantes dentro de uma linha testam a mesma fronteira. O resultado de um caso não confirma matérias expressamente excluídas dos seus pressupostos.

Salvo indicação contrária: ano de rendimentos 2026; pessoa singular residente no continente; atividade habitual; valores em euros e líquidos de IVA; sem benefícios ou exceções adicionais; classificação factual indicada já confirmada. Os casos de IRS Jovem pressupõem os restantes requisitos de acesso preenchidos. Os cálculos usam aritmética decimal sem arredondamentos intermédios; o valor a cêntimos é apresentação aritmética. Nos casos em que a própria fronteira depende do arredondamento, o resultado fica pendente.

Um caso marcado **NÃO CONFIRMADO** deve produzir um estado de revisão, por exemplo requires_review, acompanhado do motivo e da fonte. Não deve ser convertido silenciosamente em zero, isenção ou valor fiscal definitivo. A aplicação pode continuar a calcular as partes independentes que estejam confirmadas.

### IRS e IRS Jovem

Fontes comuns: [CIRS 12.º-B](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs12b.aspx), [CIRS 22.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs22.aspx), [CIRS 25.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs25.aspx), [CIRS 31.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs31.aspx), [PIV 28155](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/informacoes_vinculativas/rendimento/cirs/Documents/PIV_28155.pdf), [Ofício 20291/2026](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio-circulado-20291-2026.pdf), [folheto IRS Jovem 2025](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/Folhetos_informativos/Documents/Folheto_IRS_jovem_2025.pdf), [DL 212/2026](https://diariodarepublica.pt/dr/detalhe/decreto-lei/212-2026-1190825214), [IAS 2026](https://diariodarepublica.pt/dr/detalhe/portaria/480-a-2025-993056222).

| **Caso** | **Factos de entrada** | **Resultado esperado e fundamento** |
| --- | --- | --- |
| IRS01 | 36 anos em 31 de dezembro; primeiro ano de rendimentos A/B | IRS Jovem não elegível. Idade máxima: CIRS 12.º-B/1, CONFIRMADO. Aferição em 31 de dezembro: DOUTRINA do folheto AT. |
| IRS02 | 35 anos em 31 de dezembro; décimo ano relevante | Percentagem de isenção de 25%; teto de 29 542,15 €. CIRS 12.º-B/1 e 5; 55 × 537,13. CONFIRMADO, com a aferição etária administrativa indicada. |
| IRS03 | Décimo primeiro ano relevante, mesmo sem opção pelo benefício nos anos anteriores | Benefício terminado. A ausência de opção não cria dez anos novos. CIRS 12.º-B e regime transitório da Lei 45-A/2024; DOUTRINA AT sobre a contagem. |
| IRS04 | Apenas serviços B no simplificado; primeiro ano elegível, percentagem de 100%; bruto de 29 542,15 € ou 29 542,16 € | Parcela bruta isenta de 29 542,15 € nos dois casos; remanescente bruto de 0 € ou 0,01 €. DOUTRINA da PIV 28155 aplicada à redação de 2026 e respetivo IAS. Não concluir um valor final de IRS nem resolver automaticamente despesas e contribuições. |
| IRS05 | Sem IRS Jovem nem redução inicial; serviços com coeficiente 0,75; bruto 30 000 €; contribuições obrigatórias 3 000 €; restantes despesas zero | Dedução por contribuições que excedam 10%: zero. Os 4 500 € do teste de 15% estão cobertos pela parcela automática de 4 587,09 €. Rendimento B parcial: 22 500 €. CIRS 31.º/1/b, 2 e 13; CONFIRMADO como derivação. |
| IRS06 | Mesmo perfil de IRS05; contribuições de 3 000,01 € | Dedução adicional de 0,01 €, sem dupla utilização; acréscimo de despesas zero. Rendimento B parcial: 22 499,99 €. CIRS 31.º/2 e 13; CONFIRMADO como derivação. |
| IRS07 | Primeiro ano de atividade; sem IRS Jovem; sem A/H e sem cessação nos cinco anos anteriores; bruto 40 000 € de serviços 0,75; contribuições e outras despesas zero | Coeficiente reduzido 0,375. Base 15 000 €; acréscimo por despesas 1 412,91 €; resultado parcial 16 412,91 €. CIRS 31.º/10, 11 e 13. A ordem e manutenção do teste são apoiadas pelo manual AT de liquidação: DOUTRINA, ver INI/DES. |
| IRS08 | Mesmo perfil de IRS07, mas com 1 € de rendimento da categoria A no ano | Falha a condição de ausência de A/H; coeficiente 0,75. Resultado B parcial: 31 412,91 €, antes de englobar A e restantes mecanismos. CIRS 31.º/10; CONFIRMADO para a exclusão da redução. |
| IRS09 | IRS Jovem de 75%; rendimentos A de 40 000 € e B simplificado de 40 000 € | Teto global de 29 542,15 €, sem duplicação por categoria. Critério de rateio A/B e rendimento líquido final: NÃO CONFIRMADO. CIRS 12.º-B/5, 22.º/7/a; não devolver 59 084,30 € de teto. |
| IRS10 | Apenas B em CO; receitas de 60 000 €, gastos de 20 000 €, lucro fiscal de 40 000 €; IRS Jovem de 50% | Admissibilidade em CO confirmada pelo Ofício 20291/2026, ponto 3.2. Base da isenção, imputação dos gastos e sequência matemática: NÃO CONFIRMADO. Não assumir que a regra bruta da PIV 28155 resolve o lucro CO. |
| IRS11 | Rendimento coletável 8 342 €; tabela nacional publicada em novembro | Cálculo parcial de escalões: 8 342 × 12,2% = 1 017,724 €; apresentação 1 017,72 €. CIRS 68.º/1 e DL 212/2026, art. 2.º. CONFIRMADO como cálculo parcial, com a nota temporal HIST003. |
| IRS12 | Rendimento coletável 8 342,01 € | 1 017,724 + 0,01 × 15,2% = 1 017,72552 €; apresentação 1 017,73 €. CIRS 68.º/1–2. Não aplicar a segunda taxa a todo o rendimento. |
| IRS13 | Rendimento coletável 8 059,01 € | Pela tabela do n.º 1: 983,19922 €; apresentação 983,20 €. Não criar escalão entre 8 059 e 8 342 €. O tratamento da referência residual do n.º 2 é interpretação conjugada; a gralha não foi objeto de retificação localizada. |
| IRS14 | Ano 2024; parcela automática na comprovação de despesas de 15% | 4 350,24 €, e não 4 349,08 €. A fórmula de 8,54 IAS não se retroaplica a 2024. CIRS 25.º na redação da Lei 32/2024, art. 2.º, e parâmetros AT de 2024; CONFIRMADO. |

Fontes específicas: [manual AT de liquidação de 2021, nota 5.3](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/Manuais/Documents/Regras_Liquidacao_IRS_2021_Residentes.pdf), apoio administrativo histórico para a sequência ainda relevante; [Lei 32/2024](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Lei_32_2024.pdf); [parâmetros AT de 2024](https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/IRS/IRS_2024/Paginas/5_Deducoes_beneficios_taxas.aspx). Os resultados IRS05–IRS08 são rendimentos parciais da categoria B, não imposto a pagar.

### IVA e operações estrangeiras

Nas operações internas desta tabela, usar a taxa normal de 23% quando a operação seja tributável; não há outra isenção. Nas compras estrangeiras, o adquirente atua como empresário, o serviço ou bem é tributável em Portugal e não existe isenção própria do fornecedor/operação, salvo indicação. A classificação B2B e o estabelecimento destinatário estão comprovados.

Fontes: [CIVA 53.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/artigo-53-o-do-civa.aspx), [CIVA 58.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva58.aspx), [CIVA 59.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva59.aspx), [CIVA 6.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva6.aspx), [CIVA 14.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva14.aspx), [CIVA 27.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/civa_rep/Pages/iva27.aspx), [Ofício 25062/2025](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/instrucoes_administrativas/Documents/Oficio_circulado_25062_2025.pdf), [RITI 5.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti005.aspx), [RITI 25.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti025.aspx), [RITI 29.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti029.aspx), [RITI 22.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/RITI_2021/Paginas/riti022.aspx).

| **Caso** | **Factos de entrada** | **Resultado esperado e fundamento** |
| --- | --- | --- |
| VAT01 | Artigo 53.º; volume nacional de 2026 exatamente 15 000 €; demais condições mantidas | Mantém elegibilidade em 2027. Se o valor fosse 15 000,01 €, saída em 01-01-2027. CIVA 53.º/1 e 58.º/2/a e 4/a; CONFIRMADO. |
| VAT02 | Acumulado nacional 18 500 €; operação de 250 € | Total de 18 750 €: ainda não ocorre a saída imediata. A operação mantém o artigo 53.º; saída em janeiro seguinte se o ano terminar com esse total. CIVA 58.º/2/a–b e 4/a–b; CONFIRMADO. |
| VAT03 | Acumulado nacional 18 500 €; operação de 500 € | Total de 19 000 €: operação inteira no regime normal. IVA 115 €; total da fatura 615 €. Declaração de alterações em 15 dias úteis. CIVA 58.º/2/b, 4/b e 5/b; DOUTRINA explícita no ponto 21/b e exemplo 6 do Ofício 25062. |
| VAT04 | Início em 01-10-2026; previsão de 12 000 € até dezembro; contabilidade organizada | Pode iniciar no artigo 53.º se as demais condições se verificarem. Não comparar 48 000 € anualizados com 15 000 €. Se o total real terminar em 16 000 €, saída em janeiro seguinte. CIVA 53.º/1 e 5, 58.º; CONFIRMADO na redação do DL 35/2025. |
| VAT05 | Vendas internas de 8 000 €; uma exportação de bem de 200 € em setembro; venda interna posterior de 100 € | Sai do artigo 53.º no momento da exportação. A exportação pode beneficiar do artigo 14.º se houver requisitos e prova; a venda interna posterior suporta 23 € de IVA. CIVA 53.º/1 e 58.º/2/c, 4/c e 5/c; CONFIRMADO, com exemplo 7 do Ofício 25062. |
| VAT06 | 12 000 € de operações internas e 30 000 € de programação B2B geral para estabelecimento na Alemanha | Volume nacional relevante: 12 000 €. O serviço não localizado não causa perda por exportação. Dispensa da recapitulativa enquanto enquadrado no artigo 53.º/1. CIVA 6.º/6/a, 52.º-A/a e 59.º/1; CONFIRMADO, com ponto 28 do Ofício 25062. |
| VAT07 | Primeira aquisição de SaaS empresarial, 100 €, a fornecedor dos EUA; exigibilidade em outubro de 2026; adquirente no artigo 53.º | Autoliquidação de 23 €, sem dedução nas condições indicadas; declaração e pagamento até ao fim de novembro. Não aguardar por 10 000 €. A isenção das vendas mantém-se. CIVA 2.º/1/e e 5, 6.º/6/a e 27.º/3 e 5, redação com efeitos em 01-07-2026; CONFIRMADO. |
| VAT08 | Bens UE comuns: compras anteriores 9 900 €, depois 100 € e 200 €; ano anterior ≤10 000 €, sem opção | Em 10 000 € exatos mantém derrogação. A aquisição seguinte de 200 € provoca sujeição: 46 € de IVA. Cumprir os deveres dos RITI 25.º, 29.º e 22.º; não tributar retroativamente as primeiras compras. Uma aquisição individual inicial de 10 001 € exigiria declaração cadastral antes. CONFIRMADO. |

### Segurança Social

Fontes comuns: [CRC consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34514575), [RCRC consolidado](https://diariodarepublica.pt/dr/legislacao-consolidada/decreto-regulamentar/2011-56720826), [FAQ ISS de janeiro de 2026](https://www.seg-social.pt/storage1/files/Perguntas-Frequentes---Novo-regime-dos-Trabalhadores-Independentes--v09-eo5R_UXwpLhcDifan-xPgA.pdf), [Despacho 599/2019](https://files.diariodarepublica.pt/2s/2019/01/008000000/0152401524.pdf), [DL 127/2025](https://diariodarepublica.pt/dr/detalhe/decreto-lei/127-2025-967317021), [Portaria 249/2021 e Anexo SS](https://files.diariodarepublica.pt/1s/2021/11/22000/0002400027.pdf), [OCC sobre entidades contratantes, 19-03-2026](https://portal.occ.pt/en/node/2083417).

| **Caso** | **Factos de entrada** | **Resultado esperado e fundamento** |
| --- | --- | --- |
| SSC01 | Primeiro início em 10-01-2025, sem interrupção nem antecipação | Primeiro enquadramento com efeitos em 01-01-2026. CRC 145.º/1; CONFIRMADO. Não usar 10 de janeiro nem 1 de fevereiro. |
| SSC02 | Início em 16-03-2025; cessação em 31-08-2025; reinício em 15-04-2026 | Efeitos em 01-10-2026 segundo a FAQ ISS de 2026, exemplo da pergunta 3. DOUTRINA; conservar essa versão. Um exemplo antigo análogo contou os meses de outra forma. CRC 145.º/4. |
| SSC03 | Mesmo início e cessação, mas reinício em 15-10-2026 | Não atribuir um novo ano de diferimento. A conjugação do CRC 145.º/3–4 aponta para 01-10-2026; confirmar a decisão e notificação ISS. NÃO CONFIRMADO para automatização universal deste caso de reinício longo. |
| SSC04 | Primeiro enquadramento eficaz em outubro de 2025; outubro–dezembro fixados no mínimo do CRC 163.º/2; ausência de rendimentos mantida | Em janeiro de 2026, submeter a isenção do CRC 157.º/1/d a validação. Não conceder automaticamente nem impor doze meses pagos sem fundamento. NÃO CONFIRMADO para ano parcial; FAQ 47 não fecha a duração. |
| SSC05 | Acumulação válida; RR mensal de 2 148,51 €, 2 148,52 € ou 2 180 €; taxa 21,4% | Primeiro: isenção. Segundo: remanescente zero. Terceiro: base 31,48 €, contribuição 6,73672 €, apresentada 6,74 €. CRC 157.º/1/a e 163.º/4; CONFIRMADO para as quantias. A DQ na igualdade exata é interpretação conjugada com ressalva operacional. |
| SSC06 | Acumulação válida; contribuição matemática de 4,99 € ou 5,00 €; variante RR mensal de 2 171,88 € | 4,99 € não releva; 5,00 € releva. Na variante: 23,36 × 21,4% = 4,99904 €; NÃO CONFIRMADO se o teste ocorre antes ou depois do arredondamento. RCRC 59.º/5 e Despacho 599/2019/1. |
| SSC07 | Remuneração dependente média de 537,12 € ou 537,13 €; RR independente de 1 000 € | Primeiro falha a condição salarial da isenção por acumulação; segundo satisfaz essa condição. Continuam a ser necessários os restantes requisitos. CRC 157.º/1/a/iii e RCRC 59.º/2; CONFIRMADO. |
| SSC08 | Lucro CO de 2024 de 12 000 €, declarado em 2025 e base fixada em outubro de 2025; taxa 21,4% | Em 2026: base de 1 000 €/mês e contribuição de 214 €. Sem lucro e sem isenção: base mínima de 1,5 IAS = 805,695 €, contribuição apresentada de 172,42 €. CRC 163.º/3; desfasamento confirmado pela FAQ 23. Não usar o mínimo trimestral de 20 €. |
| SSC09 | Serviços de 6 000 €/trimestre; enquadramento pessoal confirmado como TI geral ou ENI comercial/industrial | RR de 4 200 € e base de 1 400 €. Contribuição de 299,60 € a 21,4% ou 352,80 € a 25,2%. CRC 162.º/1/a e 168.º/1 e 4; CONFIRMADO. Prestação de serviços não implica sempre 21,4%. |
| SSC10 | Contribuições próprias TI de julho e setembro de 2026 | Julho: pagamento até 31-08-2026. Setembro: prazo legal entre 10 e 20 de outubro. A notícia sobre dia 25 não altera automaticamente o calendário TI. CRC 23.º-B/1 e 155.º/2; CONFIRMADO. Obrigações como empregador seguem o artigo 43.º, com ajuste dos dias não úteis. |
| SSC11 | DQ de abril de 2025 obrigatória mas omitida; isenção desde outubro de 2025 | Continua a confirmação/correção anual em janeiro de 2026. Se esteve isento todo o ano e não houve DQ obrigatória, a mera atividade aberta não cria esse dever. CRC 151.º-A/5–7 e RCRC 57.º-B/4; CONFIRMADO. |
| SSC12 | ENI de construção; 50 000 € anuais em serviços a um cliente; obrigação contributiva existente | O CRC 140.º-A permite apurar entidade contratante; não excluir por ser ENI. Preenchimento do quadro 6: NÃO CONFIRMADO perante a Portaria 249/2021 e a orientação OCC conflitante. Separar incidência legal e procedimento declarativo. |

### Regime de IRS contabilidade e taxas regionais

Fontes: [CIRS 28.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs28.aspx), [CIRS 129.º](https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/codigos_tributarios/cirs_rep/Pages/irs129.aspx), [Portaria 51/2018](https://diariodarepublica.pt/dr/detalhe/portaria/51-2018-114706488), [Portaria 92-A/2011](https://diariodarepublica.pt/dr/detalhe/portaria/92-a-2011-406326), [OCC, transição SNC em 2026](https://www.occ.pt/index.php/pt-pt/noticias/normalizacao-contabilistica-0). As fontes das tabelas regionais estão ligadas por ano no capítulo 8.

| **Caso** | **Factos de entrada** | **Resultado esperado e fundamento** |
| --- | --- | --- |
| REG01 | Simplificado; ano anterior sem excesso; rendimento atual de 250 000 € exatos | Não há passagem obrigatória pelo teste de excesso de um ano superior a 25%; avaliar ainda a regra dos dois anos. CIRS 28.º/6; CONFIRMADO. |
| REG02 | Mesmo perfil; rendimento atual de 250 000,01 € | Passagem obrigatória a CO no ano seguinte. CIRS 28.º/6; CONFIRMADO. |
| REG03 | Dois anos consecutivos com 200 000,01 € | CO no período seguinte ao segundo ano. CIRS 28.º/6; CONFIRMADO. |
| DF01 | ENI em CO, ativos depreciáveis, sem reavaliação fiscal | Incluir o mapa de depreciações aplicável; não gerar mapas de reavaliação só por existir CO. CIRS 129.º e Portaria 51/2018, Anexo I, n.os 5–7; CONFIRMADO. |
| DF02 | Pagamento a fornecedor estrangeiro e existência de provisões | Avaliar separadamente a declaração Modelo 30 e o mapa modelo 30 do dossier; não são o mesmo documento nem têm os mesmos pressupostos. CIRS 119.º/7 e Portaria 92-A/2011, art. 3.º/1/a; CONFIRMADO. A compra estrangeira isoladamente não prova a obrigação de Modelo 30. |
| SNC01 | Pequena entidade; em 2024 balanço 4,1 milhões, VN 7,1 milhões e 52 empregados; em 2025 balanço 4,8 milhões, VN 7,3 milhões e 55 empregados | Em 2026 permanece pequena pelos novos limites; não reclassificar 2025. DOUTRINA profissional OCC de 31-03-2026 sobre DL 126-B/2025, art. 3.º, e SNC 9.º-A. |
| RAM01 | Madeira, 2023; rendimento coletável de 10 000 €; sem quociente ou outros mecanismos neste teste | Coleta parcial: 7 479 × 10,15% + 2 521 × 14,70% = 1 129,7055 €; apresentação 1 129,71 €. DLR 26/2022/M, art. 17.º; CONFIRMADO como derivação. |
| RAM02 | Madeira, 2024; rendimento coletável de 40 000 €; mesmo âmbito parcial | 39 791 × 20,454% + 209 × 42,20% = 8 227,04914 €; apresentação 8 227,05 €. DLR 7/2024/M, art. 2.º; CONFIRMADO como derivação. |
| RAM03 | Madeira, 2025; rendimento coletável de 40 000 €; mesmo âmbito parcial | 28 400 × 14,556% + 11 600 × 24,43% = 6 967,784 €; apresentação 6 967,78 €. DLR 3/2025/M, art. 2.º; CONFIRMADO como derivação. |
| RAM04 | Madeira, 2026; carregar a taxa média do escalão com limite superior de 43 090 € | Carregar 17,313%, tal como publicada. Rejeitar a substituição por 17,3124% calculada a partir da média nacional × 70%. DLR 17/2026/M, art. 2.º; CONFIRMADO. |

### Perguntas concretas para esclarecimento

As **13 minutas** seguintes não foram enviadas. Os pressupostos são exemplos técnicos, sem dados pessoais. Para obter uma resposta utilizável na aplicação, pedir a identificação do diploma, artigo, redação, data de efeitos, sequência de cálculo e arredondamentos. Uma resposta limitada a outro ano ou a factos diferentes deve ser guardada com esse alcance.

#### AT 1 IRS Jovem com simplificado e despesas

No ano de 2026, um sujeito passivo elegível ao artigo 12.º-B aufere exclusivamente 60 000 € de serviços do artigo 31.º/1/b, paga 6 000 € de contribuições obrigatórias e suporta 5 000 € de outras despesas elegíveis. A percentagem de isenção é 50%. Solicita-se o cálculo discriminado do rendimento isento e sujeito, da dedução do artigo 31.º/2 e do acréscimo do n.º 13. Os limiares de 10% e 15% incluem a parcela isenta? Há rateio das contribuições e despesas? O coeficiente incide apenas sobre o bruto remanescente? Que montante é considerado, sem deduções, para progressividade pelo artigo 22.º/7/a?

Referências: CIRS 12.º-B, 22.º/4 e 7, 31.º/1/b, 2 e 13; PIV 28155, que não apresenta esta sequência completa. Prioridade: necessária antes de qualificar este cálculo combinado como exato.

#### AT 2 IRS Jovem e redução de início de atividade

Para 2026, primeiro ano de atividade e de rendimentos elegíveis, sem A/H nem cessação nos cinco anos anteriores, bruto de 40 000 € de serviços com coeficiente normal 0,75, contribuições e outras despesas zero: como se articulam a isenção de 100% limitada a 55 IAS, o coeficiente reduzido de 0,375 e o teste de despesas de 15%? O acréscimo por despesas integra rendimento sujeito, isento ou ambos? Indicar a ordem de operações e a imputação à progressividade.

Referências: CIRS 12.º-B, 22.º/7/a e 31.º/10–13. Prioridade: necessária para automatizar a acumulação destes benefícios.

#### AT 3 Teto global do IRS Jovem com A e B

Em 2026, com 40 000 € da categoria A e 40 000 € de serviços B no simplificado, percentagem de isenção de 75% e teto único de 55 IAS, qual o critério de distribuição da isenção entre categorias? Como se imputam a dedução específica A e as despesas/contribuições B às parcelas isentas e sujeitas? Solicita-se um exemplo de liquidação discriminado, sem atribuir um teto por categoria.

Referências: CIRS 12.º-B/5, 22.º/7, 25.º e 31.º. Prioridade: necessária quando o teto global é atingido e coexistem A/B.

#### AT 4 Base e gastos no IRS Jovem em contabilidade organizada

Em 2026, um titular elegível tem apenas categoria B em CO, receitas de 60 000 €, gastos fiscalmente aceites de 20 000 €, lucro tributável de 40 000 € e percentagem de isenção de 50%. Qual a base sobre a qual se aplicam percentagem e teto? Como se imputam os gastos à parcela isenta e à sujeita e qual o montante usado para progressividade? A admissão em CO está confirmada no Anexo C; pede-se a fórmula, sem extrapolar automaticamente a PIV 28155 sobre regime simplificado.

Referências: CIRS 12.º-B, 22.º/7/a, 32.º e 33.º; Ofício 20291/2026, ponto 3.2. Prioridade: necessária para o cálculo deste benefício em CO.

#### AT 5 Tabela anual de 2026 e arredondamento

Confirma-se que a redação do artigo 68.º/1 dada pelo DL 212/2026 é utilizada integralmente na liquidação dos rendimentos de 2026, atendendo à referência a janeiro no preâmbulo? Como operacionaliza a AT o n.º 2 que mantém 8 059 €, quando o primeiro limite do n.º 1 é 8 342 €? Identificar eventual retificação ou orientação e o método de cálculo e arredondamento com taxas médias a três casas, incluindo resultados nos limites dos escalões.

Esta pergunta não altera os valores publicados nem pressupõe criar uma tabela adicional. Destina-se a documentar a aplicação administrativa e a reconciliação a cêntimos. Referências: DL 212/2026, arts. 2.º–3.º; CIRS 68.º/1–2; HIST003–HIST004.

#### ISS 1 Indexação do mínimo contributivo

Qual o valor mínimo aplicável em cada ano de 2019 a 2026 ao CRC 163.º/2 e qual o diploma ou instrução que concretiza ou suspende a atualização prevista no n.º 8? Os guias de 2026 mantêm 20 €. Solicita-se o ano-base, a primeira atualização, taxas e arredondamentos, ou o fundamento da manutenção nominal. O Despacho 599/2019 não trata desse mínimo geral.

Referências: CRC 163.º/2 e 8, DL 2/2018, guias ISS de 2026; Despacho 599/2019/1–2 apenas para distinguir os seus limiares. Prioridade: fechar a fundamentação jurídica do valor operacional.

#### ISS 2 Alegado prazo de pagamento até dia 25

Existe ato que permita pagar contribuições próprias de TI após o dia 20 e até ao dia 25, sem juros ou coima, em 2026? Indicar diploma ou despacho, publicação, data de efeitos e meses abrangidos, explicando a relação com o CRC 155.º/2 e a notícia de agosto de 2026. Separar contribuições próprias das obrigações enquanto entidade empregadora e da exceção de agosto.

Referências: CRC 155.º/2, 23.º-B e 43.º; DL 127/2025. Prioridade: resolver a divergência informativa; a regra legal geral 10–20 permanece identificada.

#### ISS 3 Isenção pelo mínimo num ano parcial

Com primeiro enquadramento eficaz em 01-10-2025, contribuições de outubro a dezembro fixadas no mínimo do CRC 163.º/2 e manutenção de ausência de rendimento, aplica-se a isenção do artigo 157.º/1/d em janeiro de 2026? Se não, qual o número de meses exigido e o respetivo fundamento? A resposta muda quando existem meses de isenção, doença, cessação ou reinício no ano anterior?

Referências: CRC 157.º/1/d e 2, 163.º/2; RCRC 61.º/3; FAQ ISS 47–48. Prioridade: impedir uma condição de doze meses ou uma concessão após um só mês sem suporte confirmado.

#### ISS 4 Reinício após interrupção superior a doze meses

Início em 16-03-2025, cessação em 31-08-2025 antes dos efeitos do primeiro enquadramento e reinício em 15-10-2026: confirmar se os efeitos ocorrem em 01-10-2026 ou noutra data e fundamentar a articulação dos n.os 3 e 4 do artigo 145.º. Para reinício na fronteira exata de doze meses, indicar a contagem do prazo e o tratamento dos meses parciais.

Referências: CRC 145.º/3–4 e FAQ ISS de 2026, pergunta 3. Prioridade: fechar o caso que o exemplo administrativo disponível não cobre.

#### ISS 5 Igualdade de quatro IAS e arredondamento de cinco euros

Cumpridos os demais requisitos de acumulação, com RR mensal exatamente igual a 4 IAS, a DQ é obrigatória apesar da base remanescente zero? Numa contribuição residual matemática de 4,99904 €, obtida em 2026 por RR mensal de 2 171,88 € à taxa de 21,4%, o teste de exclusão abaixo de 5 € é feito antes ou depois do arredondamento monetário? Identificar o passo e a regra de arredondamento.

Referências: CRC 151.º-A/1, 157.º/1/a, 163.º/4; RCRC 59.º/1 e 5; Despacho 599/2019/1. Prioridade: fechar uma fronteira declarativa e uma fronteira de cobrança distintas.

#### ISS 6 Anexo SS de ENI com coordenação da AT

Qual a versão e instrução do Modelo RC 3048 operacional para os rendimentos de 2025 declarados em 2026 e para os rendimentos de 2026? Como deve preencher o quadro 6 um ENI/EIRL abrangido pelo CRC 140.º-A se a Portaria 249/2021 manda assinalar «Não» para essas categorias? Existe instrução posterior ou recolha alternativa dos adquirentes? Confirmar também em que casos a dispensa da DQ por isenção do artigo 157.º afasta, ou não, os restantes dados do Anexo SS.

Referências: CRC 140.º, 140.º-A, 151.º-A e 157.º; RCRC 54.º-A/1/b e 58.º/3; Portaria 249/2021. Prioridade: necessária para preencher automaticamente o quadro 6 de ENI. O parecer OCC de 19-03-2026 deve acompanhar o pedido como evidência da divergência, não como revogação da lei.

#### ISS 7 Atividade profissional e comercial simultâneas

Para uma pessoa que exerce programação/consultoria profissional e comércio de equipamentos, que elementos determinam o enquadramento pessoal a 21,4% ou 25,2%? Como se aplica o RCRC 54.º-A/2 quando a atividade comercial/industrial não é exclusiva e desde que data produz efeitos a classificação? A resposta deve basear-se na descrição real da atividade, contratos e forma de exercício, e não apenas no CAE principal.

Referências: CRC 134.º/1/b e 168.º/1 e 4; RCRC 54.º-A/2 e 54.º-B; CIRS 3.º/1/a e 4.º. Prioridade: depende de a aplicação suportar este perfil misto; a percentagem de RR de cada receita é decisão separada da taxa pessoal.

#### CC 1 Perfil contabilístico e elementos condicionais

Para cada perfil de atividade efetivamente suportado, confirmar a forma de exercício, regime fiscal, referencial contabilístico, critério de transição de categoria e documentos condicionais do dossier. Para profissional liberal em CO, fundamentar a aplicação do SNC e da eventual dispensa do artigo 10.º, articulando o CIRS 117.º, a remissão para CIRC 123.º e os artigos 3.º e 10.º do DL 158/2009. Nos perfis de 2026, indicar se é seguida a orientação de transição OCC de 31-03-2026. Selecionar os mapas do Anexo I da Portaria 51/2018 por operação e benefício utilizado.

Esta validação é factual e contabilística. Não coloca em dúvida a obrigação fiscal de contabilidade organizada quando o CIRS a exige. A resposta deve identificar o fundamento de cada exceção e os documentos que comprovam os factos.

### Critério para integrar uma resposta posterior

Quando chegar um esclarecimento, ligar a resposta ao caso e à regra afetados, registar os factos do pedido, emissor, data, ano abrangido e alcance vinculativo. Atualizar apenas as versões e perfis cobertos. Rever os resultados esperados dos casos correspondentes e preservar a decisão anterior para auditoria. Uma resposta sobre o preenchimento de um formulário não altera, por si, a incidência legal; uma notificação individual também não constitui automaticamente uma regra geral.

Para submissão eletrónica de declarações, acresce uma verificação técnica da versão do formulário e do esquema em vigor. Esta revisão confirmou o calendário normativo dos novos modelos IVA; não ensaiou uma submissão autenticada nem certificou uma integração de software.
