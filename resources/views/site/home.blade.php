@extends('site.layout', ['current' => null])


@section('content')
<section class="st-hero3 st-hero3--anim">
<canvas class="st-pontos" data-site-dots aria-hidden="true"></canvas>
<div class="st-wrap st-hero3-in">
<h1 class="st-h1">Dos <b>recibos verdes</b> aos impostos em dia</h1>
<p class="st-lede">IRS, IVA e Segurança Social sem preocupações <b>desde {{ \App\Support\PublicSite::lowestMonthlyPrice() }}/mês</b>.</p>
<div class="st-hero-cta" style="justify-content: center"><a class="st-btn st-btn--white" href="{{ $signUpUrl }}">Experimenta grátis</a><a class="st-btn st-btn--ghostw" href="{{ route('site.features') }}">Vê como funciona @include('site.partials.arrow')</a></div>
@include('site.partials.demo')
</div></section>
<div style="background: var(--mist)"><div class="st-wrap"><div class="st-frame-foot"></div></div></div>

<section class="st-proof"><div class="st-wrap">
<p class="st-proof-n">IRS · IVA · Segurança Social</p>
<p class="st-proof-t">os três impostos de quem trabalha por conta própria, num só sítio</p>
<p class="st-logos-note">Segue as regras da</p><ul class="st-logos st-logos--2"><li><img src="{{ asset('brand/entidades/autoridade-tributaria.png') }}" alt="Autoridade Tributária e Aduaneira"></li><li><img src="{{ asset('brand/entidades/seguranca-social-direta.svg') }}" alt="Segurança Social Direta"></li></ul>
</div></section>

{{-- «Porque somos a opção certa para ti?»: o mosaico D5b do canvas com os tons da F5 (os valores são exemplos). --}}
<section class="st-night st-night--glow st-sec rz-d rz-d3 rz-d4 rz-d5 rz-f5" data-site-anim><div class="st-wrap">
<div class="st-head"><h2 class="st-h2">Porque somos a <b>opção certa para ti</b>?</h2><p class="st-lede" style="color: #fff; font-size: 18px; line-height: 28px">Fizemos o Contiu para quem passa recibos verdes e não quer perder tempo, nem dinheiro, com burocracia.</p><p class="rz-law-line"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Segue a legislação portuguesa · atualizado para 2026</p></div>
<div class="rz-bento">
<article class="rz-tile rz-glass rz-glass--pad rz-s7 rz-r2"><h3>Quanto pôr de parte, mês a mês</h3><div class="rz-sum"><b>389,20 €</b><span>a pôr de parte em novembro (exemplo)</span></div><ul class="rz-legend-line"><li><i class="st-dot" style="background: var(--indigo)"></i>IRS</li><li><i class="st-dot" style="background: var(--pink)"></i>IVA</li><li><i class="st-dot" style="background: var(--lav)"></i>Segurança Social</li></ul>
<div class="rz-chart" role="img" aria-label="Valor a pôr de parte por mês: junho 222,40 euros, julho 278 euros, agosto 177,90 euros, setembro 322,50 euros, outubro 255,80 euros e novembro 389,20 euros (IRS 180 euros, IVA 120 euros, Segurança Social 89,20 euros)"><div class="rz-col-m"><i style="height: 40%"><u style="height: 24%; background: var(--lav)"></u><u style="height: 31%; background: var(--pink)"></u><u style="height: 45%; background: var(--indigo)"></u></i><span>jun</span></div><div class="rz-col-m"><i style="height: 50%"><u style="height: 23%; background: var(--lav)"></u><u style="height: 31%; background: var(--pink)"></u><u style="height: 46%; background: var(--indigo)"></u></i><span>jul</span></div><div class="rz-col-m"><i style="height: 32%"><u style="height: 24%; background: var(--lav)"></u><u style="height: 31%; background: var(--pink)"></u><u style="height: 45%; background: var(--indigo)"></u></i><span>ago</span></div><div class="rz-col-m"><i style="height: 58%"><u style="height: 23%; background: var(--lav)"></u><u style="height: 30%; background: var(--pink)"></u><u style="height: 47%; background: var(--indigo)"></u></i><span>set</span></div><div class="rz-col-m"><i style="height: 46%"><u style="height: 23%; background: var(--lav)"></u><u style="height: 31%; background: var(--pink)"></u><u style="height: 45%; background: var(--indigo)"></u></i><span>out</span></div><div class="rz-col-m rz-col-m--on"><i style="height: 70%"><u style="height: 23%; background: var(--lav)"></u><u style="height: 31%; background: var(--pink)"></u><u style="height: 46%; background: var(--indigo)"></u></i><span>nov</span></div></div></article>
<article class="rz-tile rz-glass rz-glass--pad rz-s5 rz-acc"><h3>Contabilista <b>à tua escolha</b></h3><ul class="rz-acc-opts"><li><span class="rz-acc-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0"/></svg></span><span><b>Já tens contabilista? <em>Convida-o</em></b></span></li><li><span class="rz-acc-ic rz-acc-ic--pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span><span><b>Ainda não tens? <em>Desde {{ \App\Support\PublicSite::accountantPrice('simplified') }}/mês</em></b></span></li></ul>
<div class="rz-acc-foot"><p>Opcional</p><a href="{{ route('site.accountant') }}">Como funciona ›</a></div></article>
<article class="rz-tile rz-glass rz-glass--pad rz-s5"><h3>Faturas certificadas pelo InvoiceXpress</h3>
<div class="rz-link rz-link--anim" aria-hidden="true"><b class="rz-mark"><img src="{{ asset('brand/contiu/contiu-symbol-white.svg') }}" alt=""></b><i class="rz-track"><span class="rz-doc"></span></i><b class="rz-ix">iX<em class="rz-ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></em></b></div><p class="rz-status" aria-hidden="true"><span>A enviar a fatura…</span><span>Fatura emitida e certificada</span></p></article>
<article class="rz-tile rz-glass rz-glass--pad rz-s12 rz-pz rz-pz--wide"><h3>Os teus prazos</h3><div class="rz-pz-body"><div><p class="rz-strip-head">novembro</p><ul class="rz-strip" role="img" aria-label="Próximos 14 dias, de 13 a 26 de novembro: prazos a 20, Segurança Social e declaração do IVA, e a 25, pagamento do IVA"><li><b>S</b><i class="is-today">13</i></li><li><b>S</b><i>14</i></li><li><b>D</b><i>15</i></li><li><b>S</b><i>16</i></li><li><b>T</b><i>17</i></li><li><b>Q</b><i>18</i></li><li><b>Q</b><i>19</i></li><li><b>S</b><i class="rz-d20" data-n="20">20</i></li><li><b>S</b><i>21</i></li><li><b>D</b><i>22</i></li><li><b>S</b><i>23</i></li><li><b>T</b><i>24</i></li><li><b>Q</b><i class="rz-d25" data-n="25">25</i></li><li><b>Q</b><i>26</i></li></ul></div><ul class="rz-dues rz-dues--cycle"><li class="rz-due-1"><i class="st-dot" style="background: var(--pink)"></i><span><b>20 nov. · Segurança Social</b><small>Contribuição de outubro · <strong>89,20 €</strong></small></span><em>faltam <strong>7 dias</strong></em></li><li class="rz-due-2"><i class="st-dot" style="background: var(--indigo); box-shadow: 0 0 0 2px var(--lav)"></i><span><b>20 nov. · IVA</b><small>Declaração do 3.º trimestre (jul.–set.)</small></span><em>faltam <strong>7 dias</strong></em></li><li class="rz-due-3"><i class="st-dot" style="background: var(--indigo); box-shadow: 0 0 0 2px var(--lav)"></i><span><b>25 nov. · IVA</b><small>Pagamento do 3.º trimestre · <strong>360,00 €</strong></small></span><em>faltam <strong>12 dias</strong></em></li></ul></div></article>
</div>
</div></section>

@include('site.partials.pricing')

<section class="st-wave"><div class="st-wrap">
<p class="st-wave-n">{{ $trialDays }} dias</p><p class="st-wave-t">para experimentares sem pagar</p>
</div><figure class="st-ph " style="aspect-ratio: auto; max-width: none"><span>Fundo de ondas por gerar</span><b>img/site-ondas.webp</b><span>2400 × 900</span></figure></section>

<section class="st-sec st-sec--sunken"><div class="st-wrap">
<h2 class="st-h2" style="text-align: center; margin-bottom: 64px">Perguntas <b>frequentes</b></h2>
<div class="st-faq-cols"><div class="st-faq-side"><h3 class="st-h3">Ainda tens dúvidas?</h3><p class="st-muted">Juntámos as perguntas que mais nos fazem, com respostas rápidas.</p><a class="st-btn st-btn--primary" href="{{ route('site.help') }}">Ajuda @include('site.partials.arrow')</a></div>
@include('site.partials.faq', ['items' => \App\Support\SiteFaq::for('home')])
</div></div></section>
@endsection
