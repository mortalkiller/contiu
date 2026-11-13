{{-- The certified accountant service: prices as charged (App\Support\AccountantService). --}}
<p>O Contiu disponibiliza, como serviço adicional e opcional, o acompanhamento de um contabilista certificado independente.</p>
<p>Este serviço é prestado por um profissional externo à {{ config('contiu.company.short_name') }}.</p>
<p>A {{ config('contiu.company.short_name') }} atua apenas como intermediária: facilita o contacto entre ti e o contabilista e permite que este tenha acesso à tua conta Contiu para consultar e rever os dados de que precisa.</p>
<p>O contabilista certificado é o responsável pelos serviços profissionais que presta, incluindo a contabilidade, a preparação e entrega de declarações fiscais, quando contratadas, e os conselhos que te dá.</p>
<p>A {{ config('contiu.company.short_name') }} não executa esses serviços profissionais nem assume a responsabilidade técnica pelos atos do contabilista.</p>
<p>Os preços mensais deste serviço são:</p>
<table><thead><tr><th>Regime</th><th>Preço mensal</th></tr></thead><tbody><tr><td>Regime simplificado</td><td>{{ \App\Support\Money::format(\App\Support\AccountantService::priceWithVat('simplified')) }} (IVA incluído)</td></tr><tr><td>Contabilidade organizada</td><td>{{ \App\Support\Money::format(\App\Support\AccountantService::priceWithVat('organised')) }} (IVA incluído)</td></tr></tbody></table>
<p>Podes pedir o serviço no registo ou mais tarde, na aplicação, e cancelá-lo na página da subscrição.</p>
<p class="placeholder">A confirmar: identificação do prestador contratual do serviço de contabilidade, condições de contratação, faturação, cobrança, início e efeitos do cancelamento, e eventual compromisso mínimo.</p>
<p>Se estiveres no regime de contabilidade organizada, tens de assegurar a intervenção de um contabilista certificado, como a lei exige. Não és obrigado a contratar esse profissional através do Contiu.</p>
<p>Contratar este serviço não transfere para a {{ config('contiu.company.short_name') }} as obrigações fiscais ou contabilísticas que a lei te atribui.</p>
