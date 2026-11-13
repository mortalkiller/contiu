{{-- «Isenção do artigo 53.º: até onde vai» (Blog: isencao-do-artigo-53), from the SiteArtigo mock. --}}
@extends('site.blog-post')

@section('headline')Isenção do artigo 53.º: <b>até onde vai</b>@endsection

@section('lede')Se faturas pouco, podes não cobrar IVA. Explicamos quem tem direito, o que acontece quando passas os 15 000 € e porque é que os 18 750 € também contam.@endsection

@section('toc')<a href="#o-que-e" aria-current="true">O que é a isenção</a><a href="#quem-pode">Quem pode ficar isento</a><a href="#limites">Quando passas os 15 000 €</a><a href="#contiu">Como o Contiu te ajuda</a>@endsection

@section('aside-title')Sabe sempre quanto falta para os 15 000 €@endsection

@section('aside-text')O Contiu acompanha a tua faturação e os limites do IVA por ti.@endsection

@section('article')
<p>Quando começas a passar recibos verdes, uma das primeiras perguntas é se tens de cobrar IVA. Para quem fatura pouco, a resposta costuma ser não: é a <strong>isenção do artigo 53.º do Código do IVA</strong>.</p>
<h2 id="o-que-e">O que é a isenção</h2>
<p>Estar isento pelo artigo 53.º quer dizer que:</p>
<ul><li>não acrescentas IVA às tuas faturas, e o cliente paga só o valor do serviço;</li><li>não entregas declarações periódicas do IVA;</li><li>em contrapartida, não podes deduzir o IVA que pagas nas tuas compras.</li></ul>
<p>Nas faturas indicas o motivo da isenção, para o cliente e as Finanças saberem porque não há IVA.</p>
<h2 id="quem-pode">Quem pode ficar isento</h2>
<p>Desde 2025, a regra para quem tem a atividade em Portugal é esta:</p>
<ul><li>o <strong>volume de negócios em Portugal no ano anterior não passou dos 15 000 €</strong>;</li><li>não fazes exportações nem atividades ligadas a exportações.</li></ul>
<p>No ano em que começas a atividade, conta a tua <strong>previsão até ao fim do ano</strong>, sem a converter num ano inteiro. Se abrires atividade em setembro, prevês o que vais faturar de setembro a dezembro.</p>
<div class="st-callout"><svg class="c-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v6M12 7.5v.01"/></svg><span>Exatamente 15 000 € ainda cabe na isenção. O limite só se passa a partir do primeiro cêntimo acima.</span></div>
<h2 id="limites">O que acontece quando passas os 15 000 €</h2>
<p>Passar o limite não te tira logo a isenção. Há uma margem de 25%, até aos 18 750 €:</p>
<div class="st-limit" role="img" aria-label="Até 15 000 euros continuas isento; entre 15 000 e 18 750 euros sais a 1 de janeiro do ano seguinte; acima de 18 750 euros sais de imediato">
<div class="st-limit-bar"><i></i><i></i><i></i><span class="st-limit-pin" style="left: 65.6%">15 000 €</span><span class="st-limit-pin" style="left: 82%">18 750 €</span></div>
<div class="st-limit-scale"><span>Continuas isento</span><span>Sais a 1 de janeiro</span><span>Sais logo</span></div></div>
<table class="st-table"><thead><tr><th scope="col">Faturação no ano</th><th scope="col">O que acontece</th></tr></thead><tbody>
<tr><td>Até 15 000 €</td><td>Continuas isento no ano seguinte.</td></tr>
<tr><td>De 15 000 € a 18 750 €</td><td>Ficas isento até ao fim do ano e começas a cobrar IVA a <strong>1 de janeiro</strong> seguinte.</td></tr>
<tr><td>Acima de 18 750 €</td><td>Perdes a isenção <strong>de imediato</strong> e passas a cobrar IVA logo.</td></tr>
</tbody></table>
<p>Em qualquer dos casos em que sais, tens de entregar a <strong>declaração de alterações nas Finanças no prazo de 15 dias úteis</strong>, contados do fim do ano ou do momento em que passaste o limite.</p>
<h3>Um exemplo</h3>
<p>A Marta é designer e, em 2026, faturou 16 200 €. Passou os 15 000 €, mas ficou abaixo dos 18 750 €: continua sem cobrar IVA até 31 de dezembro e, a partir de 1 de janeiro de 2027, as faturas dela já levam IVA. Até 15 dias úteis depois do fim do ano, entrega a declaração de alterações.</p>
<p>Se em outubro ela já tivesse chegado aos 19 000 €, teria passado a margem dos 25%: saía da isenção nesse momento, sem esperar por janeiro.</p>
<h2 id="contiu">Como o Contiu te ajuda</h2>
<p>O Contiu soma o que faturas ao longo do ano e mostra-te quanto falta para os 15 000 € e para os 18 750 €. Quando te aproximas de um dos limites, avisa-te, para não seres apanhado de surpresa.</p>
<div class="st-callout st-callout--green"><svg class="c-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v6M12 7.5v.01"/></svg><span>Este artigo é informativo e segue as regras em vigor em novembro de 2026. Para o teu caso concreto, confirma no Portal das Finanças ou com um contabilista certificado.</span></div>
@endsection
