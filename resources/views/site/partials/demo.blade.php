{{--
    The app window at the top of the home page (canvas board SiteHeroDemo): a cursor issues an
    invoice-receipt, opens the taxes and the fiscal calendar, and starts again. The figures are an
    example, written here with their "after" values; resources/js/site.js (data-site-demo) only
    moves the cursor and swaps classes and texts, and stops while the window is off screen.
--}}
@php($navIcons = [
    'hoje' => ['Hoje', 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
    'impostos' => ['Impostos', 'M19 5 5 19M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z'],
    'calendario' => ['Calendário fiscal', 'M7 3v4M17 3v4M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z'],
    'documentos' => ['Documentos', 'M14 3H7a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V7l-4-4zM14 3v4h4M9 13h6M9 17h6'],
    'despesas' => ['Despesas', 'M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7zm0 0 12-3v3M16 13h1'],
    'clientes' => ['Clientes', 'M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM21 20v-1a4 4 0 0 0-3-3.87M16 4.13a4 4 0 0 1 0 7.75'],
    'vendas' => ['Vendas', 'M3 3v18h18M7 15l4-4 3 3 5-6'],
])
<div class="st-frame"><div class="st-browser" aria-hidden="true"><div class="st-browser-bar"><i></i><i></i><i></i><span></span></div><div class="st-browser-body dm-body">
<div class="dm" data-site-demo>
<aside class="dm-side"><img src="{{ asset('brand/contiu/contiu-logo-white.svg') }}" alt="">@foreach ($navIcons as $key => [$label, $path])<span @class(['dm-nav', 'is-on' => $key === 'hoje']) data-demo="nav-{{ $key }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $path }}"/></svg>{{ $label }}</span>@endforeach<div class="dm-side-foot"><i>MS</i>Marta Silva</div></aside>
<div class="dm-main">
<section class="dm-view is-on" data-demo-view="hoje">
<div class="dm-head"><b>Hoje</b><span>novembro de 2026</span><span class="dm-btn" data-demo="nova">+ Nova fatura</span></div>
<div class="dm-cards">
<div class="dm-card"><span>Recebido este ano</span><b data-demo-swap="19 420,00 €" data-demo-flash>18 420,00 €</b><small><span data-demo-swap="13">12</span> faturas · 5 clientes</small></div>
<div class="dm-card"><span>A pôr de parte este mês</span><b data-demo-swap="992,20 €" data-demo-flash>612,40 €</b><small class="dm-split"><em data-demo-swap="IVA 460,00 €">IVA 230,00 €</em><em data-demo-swap="SS 299,60 €">SS 149,80 €</em><em>IRS 232,60 €</em></small></div>
<div class="dm-card"><span>Próximo prazo</span><b>20 nov</b><small>Segurança Social · 149,80 €</small></div>
</div>
<div class="dm-chart"><div class="dm-chart-head"><span><b>Recebido por mês</b></span><span>2026</span></div><div class="dm-bars">@foreach ([38, 52, 46, 64, 58, 72, 60, 40, 66, 70] as $height)<i style="height: {{ $height }}%"></i>@endforeach<i class="is-now" style="height: 30%" data-demo-bar="78%"></i><i style="height: 0%"></i></div><div class="dm-months">@foreach (['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'] as $month)<span>{{ $month }}</span>@endforeach</div></div>
</section>
<section class="dm-view" data-demo-view="impostos">
<div class="dm-head"><b>Impostos</b><span>estimativa de 2026, com as regras em vigor</span></div>
<div class="dm-taxes">
<div class="dm-tax"><i>IVA</i><div><b>IVA a entregar</b><small>4.º trimestre · declaração até 20 de fevereiro</small></div><b data-demo-swap="920,00 €">690,00 €</b><a data-demo="conta">Ver a conta ›</a>
<div class="dm-explain" data-demo-explain><div><p><span data-demo-swap="4 faturas · 4 000,00 € × 23% = 920,00 €" style="color: inherit">3 faturas · 3 000,00 € × 23% = 690,00 €</span> <span>· 23% de taxa normal</span></p></div></div></div>
<div class="dm-tax"><i>IRS</i><div><b>IRS a acertar em 2027</b><small>Coeficiente 0,75 · retenção de 23% já feita pelos clientes</small></div><b>1 140,00 €</b><a>Ver a conta ›</a></div>
<div class="dm-tax"><i>SS</i><div><b>Segurança Social</b><small>21,4% de 70% do que recebeste no trimestre</small></div><b>149,80 €/mês</b><a>Ver a conta ›</a></div>
</div>
</section>
<section class="dm-view" data-demo-view="calendario">
<div class="dm-head"><b>Calendário fiscal</b><span>novembro de 2026 · só os teus prazos</span></div>
<div class="dm-week"><span class="dm-day">seg<b>17</b></span><span class="dm-day">ter<b>18</b></span><span class="dm-day">qua<b>19</b></span><span class="dm-day is-due">qui<b>20</b></span><span class="dm-day">sex<b>21</b></span><span class="dm-day">seg<b>24</b></span><span class="dm-day is-due">ter<b>25</b></span></div>
<div class="dm-due">
<div class="dm-due-row" data-demo="prazo-1"><span>qui<b>20</b></span><div><b>Segurança Social</b><small>Pagamento da contribuição de outubro</small></div><b>149,80 €</b></div>
<div class="dm-due-row" data-demo="prazo-2"><span>qui<b>20</b></span><div><b>Declaração periódica do IVA</b><small>3.º trimestre · julho a setembro</small></div><b>690,00 €</b></div>
<div class="dm-due-row" data-demo="prazo-3"><span>ter<b>25</b></span><div><b>Pagamento do IVA</b><small>3.º trimestre, no Portal das Finanças</small></div><b>690,00 €</b></div>
</div>
</section>
<div class="dm-modal" data-demo-modal><div class="dm-form"><b>Nova fatura-recibo</b>
<div class="dm-field"><span>Cliente</span><span class="dm-input" data-demo="cliente"><span data-demo-swap="Estúdio Norte, Lda">Escolhe um cliente</span></span></div>
<div class="dm-field"><span>Descrição</span><span class="dm-input">Identidade visual · novembro</span></div>
<div class="dm-field"><span>Valor sem IVA</span><span class="dm-input" data-demo="valor"><span data-demo-typing>0,00</span><i class="dm-caret" hidden></i></span></div>
<div class="dm-total"><span>IVA 23% · <span data-demo-swap="230,00 €">0,00 €</span></span><b>Total <span data-demo-swap="1 230,00 €">0,00 €</span></b></div>
<div class="dm-actions"><span>Cancelar</span><span class="dm-btn" data-demo="emitir" data-demo-busy="A emitir…">Emitir pelo InvoiceXpress</span></div>
</div></div>
<div class="dm-toast" data-demo-toast><i>✓</i>FR 2026/43 emitida no InvoiceXpress</div>
</div>
<div class="dm-cursor" data-demo-cursor><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 2l15 11-6.5 1.2L16 21l-3 1.4-3.6-7L4 19z" fill="#fff" stroke="#0c0e1d" stroke-width="1.5" stroke-linejoin="round"/></svg><i class="dm-click"></i></div>
</div>
</div></div></div>
