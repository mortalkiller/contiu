<p>{{ __('The controller of the personal data of your Contiu account is :company (“:short”), the company that develops and provides Contiu.', ['company' => config('contiu.company.name'), 'short' => config('contiu.company.short_name')]) }}</p>
@include('legal.partials.company')
