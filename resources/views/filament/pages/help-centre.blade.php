@php
    $links = $this->links();
    $icons = collect($this->topics())->pluck('icon', 'id');
    $subscription = $this->subscription();
    $link = fn (string $key, string $label) => '<a href="'.e($links[$key]).'" class="font-medium text-primary-600 underline decoration-primary-600/30 underline-offset-2 hover:decoration-primary-600 dark:text-primary-400">'.e($label).'</a>';
@endphp

<x-filament-panels::page>
    <div class="fi-help">
        <nav aria-label="Tópicos" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->topics() as $topic)
                <a href="#{{ $topic['id'] }}" class="flex items-start gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-600/40 dark:bg-gray-900 dark:ring-white/10">
                    <x-filament::icon :icon="$topic['icon']" class="mt-0.5 size-5 shrink-0 text-primary-600 dark:text-primary-400" />
                    <span>
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $topic['title'] }}</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $topic['summary'] }}</span>
                    </span>
                </a>
            @endforeach
        </nav>

        <div class="fi-help-body mt-8 space-y-6 text-sm leading-6 text-gray-700 dark:text-gray-300">
            {{-- Do trabalho ao extrato --}}
            <x-filament::section class="scroll-mt-24" id="fluxo" :icon="$icons['fluxo']" heading="Do trabalho ao extrato" description="Exemplo: loja online para o cliente Gonçalo Teixeira, 2 000 €, paga em duas metades.">
                <ol class="list-decimal space-y-4 ps-5">
                    <li>
                        <strong>Criar o trabalho.</strong> {!! $link('newProject', 'Novo trabalho') !!} (ou "+ Novo" → Novo trabalho): cliente → trabalho (código, descrição, categoria, valor, IVA, data do orçamento) → plano de pagamento "50% / 50%" → resumo.
                        A app cria dois <em>valores a receber</em> de 1 000 € que aparecem em "A receber" no painel e no fecho do mês de cada vencimento.
                    </li>
                    <li>
                        <strong>O cliente paga 400 € na conta da atividade.</strong> Passa a fatura-recibo de 400 € no Portal das Finanças. Na app, uma de duas:
                        <ul class="mt-2 list-disc space-y-1 ps-5">
                            <li>regista logo: painel → "A receber" → <strong>Receber</strong> na 1.ª prestação → data, 400 €, método, n.º da fatura-recibo. A prestação fica "Parcial" (faltam 600 €);</li>
                            <li>ou espera pelo extrato: a linha "TRF GONCALO TEIXEIRA 400,00" aparece com a sugestão <strong>Recebimento parcial</strong> — aceitas e depois acrescentas o n.º da fatura-recibo em Recebimentos.</li>
                        </ul>
                        Se já registaste, o extrato mostra "Já registado" e é só aceitar — nunca fica duplicado.
                    </li>
                    <li>
                        <strong>O cliente paga 600 € por MB Way na tua conta pessoal.</strong> Passa a fatura-recibo e carrega em <strong>Receber</strong> com a data em que o cliente pagou. É essa data que conta para o rendimento. A 1.ª prestação fica paga.
                    </li>
                    <li>
                        <strong>Passas 900 € da conta pessoal para a conta da atividade.</strong> Na app não fazes nada agora.
                    </li>
                    <li>
                        <strong>O cliente paga os 1 000 € finais.</strong> Passa a fatura-recibo; registas logo ou deixas para o extrato.
                    </li>
                    <li>
                        <strong>Fim do mês: importar o extrato.</strong> {!! $link('bank', 'Extrato bancário') !!} → Carregar extrato → escolhe o Excel ou CSV do banco.
                        <div class="mt-3 overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                    <tr><th class="px-3 py-2 font-medium">Linha do extrato</th><th class="px-3 py-2 font-medium">Sugestão</th><th class="px-3 py-2 font-medium">O que fazes</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                                    <tr><td class="px-3 py-2">TRF DE ANA SILVA … · +900 €</td><td class="px-3 py-2">Transferência minha</td><td class="px-3 py-2">nada</td></tr>
                                    <tr><td class="px-3 py-2">TRF GONCALO TEIXEIRA · +1 000 €</td><td class="px-3 py-2">Recebimento · 2.ª prestação (ou "Já registado")</td><td class="px-3 py-2">nada</td></tr>
                                    <tr><td class="px-3 py-2">STRIPE TECHNOLOGY EUROPE · +4,32 €</td><td class="px-3 py-2">sem sugestão</td><td class="px-3 py-2"><strong>Criar receita</strong>: bruto 5,00 €, comissão 0,68 €</td></tr>
                                    <tr><td class="px-3 py-2">COMPRA OPENAI · −83,74 €</td><td class="px-3 py-2">Despesa recorrente · ChatGPT Pro</td><td class="px-3 py-2">nada</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3">Carrega em <strong>Aceitar sugestões</strong> (seleciona as linhas e usa a ação em massa) e trata à mão só as que sobram.</p>
                    </li>
                    <li>
                        <strong>Fecho do mês.</strong> No {!! $link('dashboard', 'painel') !!}, botão "Fecho do mês": mostra o que falta receber ou pagar nesse mês até aparecer "Mês fechado".
                    </li>
                </ol>
            </x-filament::section>

            {{-- Recebimentos --}}
            <x-filament::section class="scroll-mt-24" id="recebimentos" :icon="$icons['recebimentos']" heading="Recebimentos">
                <ul class="list-disc space-y-2 ps-5">
                    <li>Cada vez que recebes: passa a <strong>fatura-recibo</strong> no Portal das Finanças e regista o recebimento em "Receber" (painel, trabalho, avença, cliente ou {!! $link('payments', 'Recebimentos') !!}). O montante vem preenchido com o que falta.</li>
                    <li><strong>Parcial:</strong> regista só o que recebeste. O valor a receber fica "Parcial" até estar todo pago. Cada parte tem a sua fatura-recibo.</li>
                    <li><strong>Data:</strong> usa sempre a data em que o cliente pagou, mesmo que tenha sido na conta pessoal.</li>
                    <li><strong>Avenças</strong> ({!! $link('newRetainer', 'Nova avença') !!}) criam um valor a receber por período automaticamente.</li>
                    <li><strong>Isento de impostos:</strong> vem da avença ou das definições; o recebimento isento não conta na estimativa de impostos.</li>
                </ul>
            </x-filament::section>

            {{-- Despesas --}}
            <x-filament::section class="scroll-mt-24" id="despesas" :icon="$icons['despesas']" heading="Despesas">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{!! $link('newExpense', 'Nova despesa') !!}: fornecedor, categoria, valor, IVA, data e n.º da fatura. Pede sempre fatura com o teu NIF — conta para os 15% de despesas do regime simplificado.</li>
                    <li><strong>Diretas</strong> (de um trabalho ou avença) entram nas margens; <strong>de estrutura</strong> são custos gerais.</li>
                    <li>{!! $link('recurring', 'Despesas recorrentes') !!} (ex.: ChatGPT, OVH) são criadas sozinhas todos os meses e reconhecidas no extrato.</li>
                    <li>Pagaste com a conta pessoal? Regista normalmente com a data do pagamento; não há nada a fazer no extrato.</li>
                </ul>
            </x-filament::section>

            {{-- Receitas avulsas --}}
            <x-filament::section class="scroll-mt-24" id="receitas-avulsas" :icon="$icons['receitas-avulsas']" heading="Receitas avulsas" description="Donativos, reembolsos ou outras entradas sem trabalho nem avença.">
                <ul class="list-disc space-y-2 ps-5">
                    <li>"+ Novo" → <strong>Receita avulsa</strong>, ou <strong>Criar receita</strong> numa linha do extrato.</li>
                    <li>Indica o <strong>valor bruto</strong> (o que o apoiante pagou e o valor da fatura-recibo) e a <strong>comissão</strong> que a plataforma ficou. Ex.: Buy Me a Coffee → 5,00 € bruto, 0,68 € de comissão do Stripe, 4,32 € no banco.</li>
                    <li>A app regista a receita de 5,00 € e uma despesa de 0,68 € ("Comissões"). No extrato, a linha de 4,32 € fica explicada pelas duas. Em "Criar receita" só escreves o bruto: a comissão é calculada com o que chegou ao banco.</li>
                    <li>Conta no painel, margens ("Receitas avulsas") e impostos como qualquer receita.</li>
                </ul>
            </x-filament::section>

            {{-- Extrato --}}
            <x-filament::section class="scroll-mt-24" id="extrato" :icon="$icons['extrato']" heading="Extrato bancário" description="Só a conta da atividade. O objetivo é que cada linha fique explicada.">
                <ol class="list-decimal space-y-2 ps-5">
                    <li>No site do banco exporta os movimentos (Excel ou CSV) e carrega-os em {!! $link('bank', 'Extrato bancário') !!}. Linhas repetidas são ignoradas.</li>
                    <li>Cada linha traz uma sugestão. Seleciona as linhas e usa <strong>Aceitar sugestões</strong>; só são aceites as sugestões sem dúvidas.</li>
                    <li>Para as restantes:
                        <ul class="mt-1 list-disc space-y-1 ps-5">
                            <li><strong>Associar…</strong> — escolhe um ou vários registos (ex.: duas faturas pagas numa transferência, ou um recebimento menos uma despesa). Se a soma não bater, a app avisa. Normalmente a diferença não passou por esta conta e não fazes nada: fica no quadro <strong>Conta da empresa</strong>. Só dois casos pedem um registo novo, e podes criá-lo ali mesmo:
                                <ul class="mt-1 list-disc space-y-1 ps-5">
                                    <li><strong>Comissão bancária</strong> — chegou menos do que a fatura (ex.: 995 € de 1 000 €) ou saiu mais do que a despesa: cria a despesa da diferença.</li>
                                    <li><strong>Outra receita</strong> — entrou mais do que a fatura: cria uma receita avulsa da diferença.</li>
                                </ul>
                            </li>
                            <li><strong>Criar despesa</strong> / <strong>Criar receita</strong> — quando ainda não registaste.</li>
                            <li><strong>Transferência minha</strong> (setas) — dinheiro entre as tuas contas; não é receita nem despesa.</li>
                            <li><strong>Ignorar</strong> (olho) — tudo o resto que não interessa.</li>
                        </ul>
                    </li>
                    <li>Enganaste-te? Em "Tratados" ou "Ignorados" usa <strong>Voltar a tratar</strong>: o que foi criado a partir da linha é apagado, o que já existia fica.</li>
                </ol>
                <p class="mt-3">As transferências entre a conta da empresa e a tua são reconhecidas pelos nomes das tuas contas (o nome do titular e o nome da conta pessoal, ex.: "Conta pessoal"), que defines em {!! $link('settings', 'Definições financeiras') !!}.</p>
                <p class="mt-3"><strong>Reconciliar automaticamente</strong> (no topo do extrato) trata de uma vez todas as linhas com uma sugestão segura; as outras ficam para ti.</p>
                <p class="mt-3"><strong>Conta da empresa</strong> — no topo do extrato, no painel e no fecho do mês: o <em>saldo no banco</em> (do último extrato), o <em>saldo segundo a Contiu</em> (saldo inicial + recebido − despesas pagas) e a diferença:</p>
                <ul class="mt-2 list-disc space-y-1 ps-5">
                    <li><strong>Falta transferir para a conta da empresa</strong> — há recebimentos que ainda não chegaram ao banco; transfere (ou deposita) esse valor.</li>
                    <li><strong>A empresa deve-te</strong> — há despesas pagas que não saíram desta conta; podes transferir esse valor para ti.</li>
                    <li><strong>Bate certo</strong> — o banco tem exatamente o que a Contiu diz.</li>
                </ul>
                <p class="mt-3">Nas listas de <strong>Recebimentos</strong> e <strong>Despesas</strong>, a coluna <strong>Banco</strong> diz se cada um está <strong>Reconciliado</strong> ou <strong>Por reconciliar</strong> (com o filtro do mesmo nome). Está reconciliado quando está ligado a uma linha do extrato, ou quando uma transferência tua já o cobre: os mais antigos ficam cobertos primeiro. Com o extrato todo tratado, o que está por reconciliar soma o valor de "Falta transferir"; um parcial mostra quanto falta.</p>
                <p class="mt-3">Para ver <em>que</em> linhas do extrato cobrem um recebimento ou uma despesa: <strong>Ver no banco</strong> no menu ⋯ da linha, ou a secção <strong>Banco</strong> ao editá-lo. Também aparece nos separadores de recebimentos e despesas de cada trabalho e avença, e nas despesas do fecho do mês. Numa transferência, o ícone 👁 <strong>Ver detalhes</strong> mostra o contrário: que registos ela cobre.</p>
                <p class="mt-2">O número só é final com o extrato todo tratado: enquanto houver linhas "Por tratar" o quadro avisa.</p>
            </x-filament::section>

            {{-- Fecho do mês --}}
            <x-filament::section class="scroll-mt-24" id="fecho-do-mes" :icon="$icons['fecho-do-mes']" heading="Fecho do mês">
                <ul class="list-disc space-y-2 ps-5">
                    <li>Abre {!! $link('monthClose', 'Fecho do mês') !!} (botão no painel). Mostra os valores a receber e as despesas do mês que ainda faltam.</li>
                    <li>Regista o que entrou, marca as despesas como pagas, acrescenta o n.º da fatura e trata as linhas do extrato desse mês. Quando não falta nada aparece <strong>"Mês fechado"</strong>.</li>
                    <li>O quadro <strong>Conta da empresa</strong> mostra o saldo no fim do mês: se disser "Falta transferir", faz a transferência para a conta da empresa.</li>
                    <li>Ordem recomendada: importar o extrato → aceitar sugestões → fecho do mês.</li>
                </ul>
            </x-filament::section>

            {{-- Conta pessoal --}}
            <x-filament::section class="scroll-mt-24" id="conta-pessoal" :icon="$icons['conta-pessoal']" heading="Conta pessoal">
                <ul class="list-disc space-y-2 ps-5">
                    <li>Cliente pagou na conta pessoal: regista o recebimento com a data em que pagou (não vai aparecer no extrato da atividade).</li>
                    <li>Despesa paga com a conta pessoal: regista a despesa normalmente.</li>
                    <li>Quando passas dinheiro entre contas, no extrato a linha é <strong>Transferência minha</strong>. Não precisas de dizer a que recebimentos ou despesas corresponde: é dinheiro teu a mudar de conta.</li>
                    <li>Quanto falta transferir (ou a empresa te deve) está sempre no quadro <strong>Conta da empresa</strong>, e os recebimentos e despesas que ainda faltam aparecem como <strong>Por reconciliar</strong>.</li>
                    <li><strong>Exemplo com acerto:</strong> o cliente pagou 730 € na conta pessoal, a conta pessoal pagou o ChatGPT (83,74 €) e passaste só 646,26 €. Regista os dois quando acontecem; no extrato usa <strong>Associar…</strong> com o recebimento e a despesa (730 − 83,74 = 646,26, sem diferença), ou simplesmente <strong>Transferência minha</strong>.</li>
                </ul>
            </x-filament::section>

            {{-- Notas legais --}}
            <x-filament::section class="scroll-mt-24" id="notas-legais" :icon="$icons['notas-legais']" heading="Notas legais" description="Resumo para ENI no regime simplificado — confirma com o teu contabilista.">
                <ul class="list-disc space-y-2 ps-5">
                    <li>No regime simplificado não és obrigado a ter uma conta só para a atividade (a obrigação do art. 63.º-C da LGT é para quem tem contabilidade organizada). Separar é boa prática.</li>
                    <li>O rendimento conta quando o cliente paga, seja em que conta for. Transferências entre contas tuas não são rendimento nem gasto.</li>
                    <li>Para impostos contam as faturas e faturas-recibo que emites e as despesas com o teu NIF no e-fatura (regra dos 15% do art. 31.º do CIRS).</li>
                    <li>Cada recebimento, incluindo os parciais, deve ter a sua fatura-recibo.</li>
                    <li>Receitas por plataforma: o rendimento é o valor bruto; a comissão é um gasto.</li>
                    <li>Por confirmar: a quem passar a fatura-recibo de donativos de apoiantes anónimos ou estrangeiros, e se a tua isenção de IRS os abrange.</li>
                </ul>
            </x-filament::section>

            {{-- A tua conta e a assinatura --}}
            <x-filament::section class="scroll-mt-24" id="conta" :icon="$icons['conta']" heading="A tua conta e a assinatura" description="O que tens durante a experiência, quanto custa e o que acontece se a assinatura parar.">
                <ul class="list-disc space-y-2 ps-5">
                    <li><strong>Experiência de {{ $subscription['trialDays'] }} dias:</strong> uma conta nova pode usar tudo durante {{ $subscription['trialDays'] }} dias, sem cartão. O aviso no topo mostra quantos dias faltam.</li>
                    <li><strong>Assinatura:</strong> em Definições → <strong>Assinatura</strong> escolhes mensal ({{ $subscription['monthly'] }} por mês) ou anual ({{ $subscription['yearly'] }} por ano) e pagas com cartão na Stripe. Se subscreveres durante a experiência não perdes os dias que faltam: a primeira cobrança é no fim da experiência.</li>
                    <li><strong>Conta só de leitura:</strong> quando a experiência acaba sem assinatura, ou a assinatura deixa de estar paga, continuas a ver e a exportar tudo, mas não podes criar, alterar ou apagar registos, importar extratos nem enviar emails. Nada é apagado: ao subscrever, continuas onde ficaste.</li>
                    <li><strong>Cartão, recibos e cancelamento:</strong> na página Assinatura, o botão abre o portal de faturação da Stripe. Se cancelares, a conta funciona até ao fim do período já pago.</li>
                </ul>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
