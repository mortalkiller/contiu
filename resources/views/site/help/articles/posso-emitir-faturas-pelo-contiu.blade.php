{{-- The longer text of «Posso emitir faturas pelo Contiu?» (canvas SiteAjudaArtigo). --}}
@php($path = fn (string ...$steps): string => '<span class="ha-path">'.implode('<i>›</i>', array_map('e', $steps)).'</span>')
<p>O Contiu não emite documentos fiscais sozinho: liga-se ao teu programa de faturação certificado, o <strong>InvoiceXpress</strong>. O documento é emitido e certificado lá, com a numeração e o ATCUD do InvoiceXpress, e fica guardado no Contiu, ligado ao cliente.</p>
<h2>O que podes emitir</h2>
<ul><li>Faturas, faturas-recibo e faturas simplificadas.</li><li>Recibos de faturas já emitidas.</li><li>Notas de crédito e notas de débito.</li><li>Orçamentos e faturas pró-forma, que passam a fatura num clique.</li></ul>
<h2>Antes de começares</h2>
<ul><li>Uma conta ativa no InvoiceXpress.</li><li>A chave da API, que encontras nas definições da tua conta InvoiceXpress.</li><li>Seres o dono da conta no Contiu: só o dono pode ligar o programa de faturação.</li></ul>
<h2>Ligar o InvoiceXpress</h2>
<ol><li>No Contiu, abre {!! $path('Definições', 'Integrações') !!}.</li><li>Escolhe <strong>InvoiceXpress</strong> e escreve o nome da tua conta e a chave da API.</li><li>Carrega em <strong>Ligar</strong>. O Contiu confirma a ligação e mostra as séries de documentos.</li><li>Escolhe a série que queres usar.</li></ol>
<div class="st-callout"><svg class="c-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v6M12 7.5v.01"/></svg><span><b>A chave fica protegida</b>A chave da API é guardada encriptada e nunca aparece no ecrã depois de a gravares. Para a trocar, escreves uma nova.</span></div>
<h2>Emitir um documento</h2>
<ol><li>Abre {!! $path('Documentos fiscais', 'Novo documento') !!}.</li><li>Escolhe o tipo (por exemplo, fatura-recibo), o cliente e confirma as linhas, o IVA e a data.</li><li>Carrega em <strong>Emitir</strong>. O documento fica certificado e podes enviá-lo por email com o PDF em anexo.</li></ol>
<div class="ha-example"><b>Exemplo</b>Uma fatura-recibo de 615,00 € (500,00 € + IVA a 23%) para o cliente Atelier Lume. O recebimento fica registado e o documento aparece em Documentos fiscais.</div>
<h2>E se não usares o InvoiceXpress?</h2>
<p>Podes continuar a emitir no Portal das Finanças ou noutro programa e registar o documento no Contiu à mão. Vê <a href="{{ \App\Support\HelpArticles::all()->firstWhere('slug', 'e-se-eu-nao-tiver-programa-de-faturacao')['url'] ?? route('site.help') }}">E se eu não tiver programa de faturação?</a></p>
