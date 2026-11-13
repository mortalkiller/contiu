@extends('site.layout', ['current' => 'accountant', 'navTone' => 'login-night'])


@section('content')
{{-- The top (canvas SiteTopoContabB): the copy on night, the accountant on a green panel cut on a slant. --}}
<section class="tp tp-cb">
<div class="tp-copy"><h1 class="st-h1">Um contabilista <b>certificado</b> ao teu lado</h1><p class="st-lede">Entrega as tuas declarações e responde às tuas dúvidas, a trabalhar sobre os dados que já tens no Contiu.</p>
<div class="tp-cta"><a class="st-btn st-btn--pink" href="{{ $signUpUrl }}">Começar grátis @include('site.partials.arrow')</a><a class="st-btn tp-btn-ghostw" href="#precos">Ver preços</a></div></div>
<div class="tp-cb-art"><p class="tp-cb-note"><b>+{{ \App\Support\PublicSite::accountantPrice('simplified') }}/mês</b>no regime simplificado</p><img src="{{ asset('site/img/contabilista.webp') }}" alt=""></div>
</section>
<section class="st-sec"><div class="st-wrap">
<div class="st-head"><h2 class="st-h2">O que o contabilista faz por ti</h2></div>
<div class="st-grid-3">
<div class="st-plan"><h3 class="st-h3">Declarações do IVA</h3><p class="st-muted">Prepara e entrega as declarações periódicas dentro do prazo.</p></div>
<div class="st-plan"><h3 class="st-h3">Segurança Social</h3><p class="st-muted">Entrega a declaração trimestral com os teus rendimentos.</p></div>
<div class="st-plan"><h3 class="st-h3">IRS</h3><p class="st-muted">Trata da declaração anual, com o anexo da tua atividade.</p></div>
</div>
</div></section>
<section class="st-sec st-sec--sunken"><div class="st-wrap">
<div class="st-head"><h2 class="st-h2">Pedes no Contiu, o contabilista trata do resto</h2></div>
<div class="st-grid-3">
<div class="st-step"><span class="st-step-n">1</span><h3 class="st-h3">Pedes o serviço</h3><p class="st-muted">No registo ou, mais tarde, na página da tua subscrição.</p></div>
<div class="st-step"><span class="st-step-n">2</span><h3 class="st-h3">Conheces o teu contabilista</h3><p class="st-muted">Um contabilista certificado fica dedicado à tua conta e entra em contacto contigo.</p></div>
<div class="st-step"><span class="st-step-n">3</span><h3 class="st-h3">Ele entrega, tu acompanhas</h3><p class="st-muted">Com acesso à tua conta, trabalha sobre os teus dados. Tu vês tudo no Contiu.</p></div>
</div>
</div></section>
<section class="st-sec" id="precos"><div class="st-wrap"><div class="st-split">
<div class="st-split-copy"><h2 class="st-h2">Ao mês, por cima do teu plano</h2>
<div class="st-grid-2" style="width: 100%">
<div class="st-plan"><h3 class="st-h3">Regime simplificado</h3><p class="st-price"><b>{{ \App\Support\PublicSite::accountantPrice('simplified') }}</b><span>/ mês *</span></p></div>
<div class="st-plan"><h3 class="st-h3">Contabilidade organizada</h3><p class="st-price"><b>{{ \App\Support\PublicSite::accountantPrice('organised') }}</b><span>/ mês *</span></p></div>
</div>
<p class="st-small">Com contabilidade organizada, ter um contabilista certificado é obrigatório por lei.</p>
<p class="st-small">* Valores com IVA incluído.</p></div>
<div class="st-split-copy"><h3 class="st-h3">Quem presta o serviço</h3>
<p class="st-muted">O serviço de contabilista certificado é prestado por um contabilista certificado independente, externo ao Contiu.</p>
<p class="st-muted">O Contiu funciona apenas como intermediário: põe-te em contacto com o contabilista e dá-lhe acesso à tua conta para que possa fazer o seu trabalho.</p>
<p class="st-muted">O contabilista é o único responsável pelo trabalho de contabilidade, pelas declarações que entrega e pelos conselhos que dá.</p></div>
</div></div></section>
<section class="st-sec st-sec--sunken"><div class="st-wrap st-wrap--narrow">
<div class="st-head"><h2 class="st-h2">Perguntas sobre o serviço</h2></div>
@include('site.partials.faq', ['items' => \App\Support\SiteFaq::for('accountant')])
</div></section>
@include('site.partials.band')
@endsection
