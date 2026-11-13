{{-- Identification of the company behind Contiu (config contiu.company): empty details are left out. --}}
@php($company = config('contiu.company'))
<dl>
    <dt>{{ __('Company') }}</dt>
    <dd>{{ $company['name'] }}</dd>
    @if (filled($company['vat_number']))
        <dt>{{ __('Company number (NIPC)') }}</dt>
        <dd>{{ $company['vat_number'] }}</dd>
    @endif
    @if (filled($company['address']))
        <dt>{{ __('Registered office') }}</dt>
        <dd>{{ $company['address'] }}</dd>
    @endif
    @if (filled($company['email']))
        <dt>{{ __('Email') }}</dt>
        <dd><a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></dd>
    @endif
    <dt>{{ __('Website') }}</dt>
    <dd><a href="{{ $company['website'] }}" rel="noopener">{{ str($company['website'])->after('://') }}</a></dd>
</dl>
