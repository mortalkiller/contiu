{{--
    Termos e Condições, in Portuguese (the text Pedro had written, 13/11/2026): each section is a
    partial in legal/terms. What is still to confirm shows as a highlighted note ("A confirmar"),
    and the page stays out of search engines (legal/layout) until there is none left. Prices,
    trial days and company details come from config, so the text follows what is charged.
--}}
@extends('legal.layout', ['sections' => [
    'Quem presta o serviço' => 'legal.terms.01-provider',
    'O serviço' => 'legal.terms.02-service',
    'Serviço de contabilista certificado' => 'legal.terms.03-accountant',
    'Conta e registo' => 'legal.terms.04-account',
    'Período de experiência e subscrição' => 'legal.terms.05-trial',
    'Pagamentos e faturação' => 'legal.terms.06-payments',
    'Cancelamento e fecho da conta' => 'legal.terms.07-cancellation',
    'Os teus dados e os dos teus clientes' => 'legal.terms.08-data',
    'Responsabilidade' => 'legal.terms.09-liability',
    'Alterações a estes termos' => 'legal.terms.10-changes',
    'Lei aplicável e contactos' => 'legal.terms.11-law',
    'Uso aceitável' => 'legal.terms.12-acceptable-use',
    'Estimativas não são aconselhamento fiscal' => 'legal.terms.13-estimates',
    'Integrações com serviços de terceiros' => 'legal.terms.14-integrations',
    'Disponibilidade e fase beta' => 'legal.terms.15-availability',
    'Propriedade intelectual' => 'legal.terms.16-intellectual-property',
    'Resolução alternativa de litígios e livro de reclamações' => 'legal.terms.17-disputes',
    'Disposições finais' => 'legal.terms.18-final',
]])

@section('title', 'Termos e Condições')
