@extends('site.layout', ['current' => 'pricing', 'navTone' => 'green'])


@section('content')
{{-- The top (canvas SiteTopoPrecosA): a green band curving into the price section. --}}
<section class="tp tp-pa"><div class="st-wrap"><div class="tp-head"><h1 class="st-h1">O <b>preço</b> que um independente merece.</h1><p class="st-lede">Um plano só, tudo incluído. {{ $trialDays }} dias grátis para experimentares.</p></div></div></section>
@include('site.partials.pricing')
<section class="st-sec st-sec--sunken"><div class="st-wrap">
<h2 class="st-h2" style="text-align: center; margin-bottom: 64px">Perguntas sobre <b>pagamentos</b></h2>
<div class="st-faq-cols"><div class="st-faq-side"><h3 class="st-h3">Ainda tens dúvidas?</h3><p class="st-muted">Se a tua pergunta não estiver aqui, escreve-nos.</p><a class="st-btn st-btn--primary" href="{{ route('site.help') }}#contacto">Falar connosco @include('site.partials.arrow')</a></div>
@include('site.partials.faq', ['items' => \App\Support\SiteFaq::for('pricing')])
</div></div></section>
@endsection
