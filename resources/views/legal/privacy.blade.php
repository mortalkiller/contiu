{{-- Structure only: the text is Pedro's (or his lawyer's) to write (GDPR: Pedro becomes the
     processor of the freelancers' clients' data). --}}
@extends('legal.layout', ['sections' => [
    'Responsável pelo tratamento',
    'Dados que recolhemos',
    'Para que usamos os dados e com que fundamento',
    'Dados dos teus clientes (subcontratação)',
    'Subcontratantes',
    'Conservação dos dados',
    'Os teus direitos',
    'Contactos e reclamações',
]])

@section('title', 'Política de Privacidade')
