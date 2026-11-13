<p>{{ __('Contiu is a service developed and provided by :company (“:short”), a company based in Portugal. Whenever these terms say “Contiu”, “we” or “us”, they mean :short.', ['company' => config('contiu.company.name'), 'short' => config('contiu.company.short_name')]) }}</p>
@include('legal.partials.company')
