{{-- Who provides the service: the company details come from config contiu.company. --}}
<p>O Contiu é um serviço disponibilizado pela {{ config('contiu.company.name') }} (doravante «{{ config('contiu.company.short_name') }}»), uma empresa com sede em Portugal.</p>
@include('legal.partials.company')
<p>Para questões sobre o Contiu, podes escrever-nos para <a href="mailto:{{ config('contiu.support_email') }}">{{ config('contiu.support_email') }}</a>.</p>
<p>Ao criares uma conta e aceitares estes Termos e Condições, celebras com a {{ config('contiu.company.short_name') }} um contrato de prestação de serviços digitais, nos termos aqui definidos.</p>
