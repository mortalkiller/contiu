{{--
    Política de Privacidade, in Portuguese (the text Pedro had written, 13/11/2026), with what the
    code already answers filled in (logs, backups, cookies, lookups). Each section is a partial in
    legal/privacy; what is still to confirm shows highlighted ("A confirmar"), and the page stays
    out of search engines (legal/layout) until there is none left.
--}}
@extends('legal.layout', ['sections' => [
    'Responsável pelo tratamento' => 'legal.privacy.01',
    'Dados que recolhemos' => 'legal.privacy.02',
    'Para que usamos os dados e com que fundamento' => 'legal.privacy.03',
    'Os dados dos teus clientes e fornecedores (tratamento por tua conta)' => 'legal.privacy.04',
    'Subcontratantes' => 'legal.privacy.05',
    'Conservação dos dados' => 'legal.privacy.06',
    'Os teus direitos' => 'legal.privacy.07',
    'Contactos e reclamações' => 'legal.privacy.08',
    'Transferências para fora do Espaço Económico Europeu' => 'legal.privacy.09',
    'Segurança' => 'legal.privacy.10',
    'Cookies' => 'legal.privacy.11',
    'Contabilista com acesso à tua conta' => 'legal.privacy.12',
    'Alterações a esta política' => 'legal.privacy.13',
]])

@section('title', 'Política de Privacidade')
