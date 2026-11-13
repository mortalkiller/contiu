{{-- Trial and plan prices as charged (config contiu.trial_days, App\Filament\Pages\AccountSubscription). --}}
@php($plans = \App\Filament\Pages\AccountSubscription::PLANS)
<p>O Contiu tem um período de experiência gratuito de {{ config('contiu.trial_days') }} dias, com acesso a todas as funcionalidades do plano.</p>
<p>Durante a fase beta, não precisas de adicionar um cartão de pagamento para começar a experiência.</p>
<p>Quando o pagamento no registo estiver ativo, pedimos o cartão no registo e a primeira cobrança é feita automaticamente no fim da experiência, depois de te mostrarmos o preço e a data dessa cobrança. Se cancelares antes do fim da experiência, não és cobrado.</p>
<p>Se subscreveres um plano durante a experiência, não perdes os dias gratuitos que faltam: o período pago começa quando a experiência termina.</p>
<p>Há um único plano Contiu, com todas as funcionalidades incluídas.</p>
<table><thead><tr><th>Modalidade</th><th>Preço sem IVA</th><th>Preço com IVA ({{ (int) round(config('contiu.subscription_vat_rate') * 100) }}%)</th></tr></thead><tbody><tr><td>Mensal</td><td>{{ \App\Support\Money::format($plans['monthly']['amount']) }}</td><td>{{ \App\Support\Money::format(\App\Filament\Pages\AccountSubscription::priceWithVat('monthly')) }}</td></tr><tr><td>Anual</td><td>{{ \App\Support\Money::format($plans['yearly']['amount']) }}</td><td>{{ \App\Support\Money::format(\App\Filament\Pages\AccountSubscription::priceWithVat('yearly')) }}</td></tr></tbody></table>
<p>Os valores com IVA usam a taxa normal de {{ (int) round(config('contiu.subscription_vat_rate') * 100) }}% aplicável em Portugal continental. A tributação efetiva depende das regras fiscais aplicáveis à operação.</p>
<p class="placeholder">A confirmar: preços para clientes nas Regiões Autónomas, noutros países ou isentos de IVA, e o arredondamento dos valores apresentados.</p>
<p>A subscrição renova-se automaticamente no fim de cada período, mensal ou anual, até ser cancelada.</p>
<p>Antes de confirmares uma subscrição, mostramos-te o preço, a periodicidade, as condições de renovação e as restantes informações exigidas por lei.</p>
<p>O serviço de contabilista certificado, quando contratado, é pago à parte e não está incluído no preço do plano Contiu.</p>
