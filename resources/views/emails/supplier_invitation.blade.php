@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #111827;">Convite para Registro</h2>

    <p>Saudações,</p>

    <p>É convidado(a) a se registrar como fornecedor/prestador no nosso banco de dados de fornecedores e prestadores.</p>

    <p>Clique no botão abaixo para completar o seu cadastro com os dados da sua empresa e documentos necessários.</p>

    <div style="text-align: center;">
        <a href="{{ url('/supplier/register/' . $supplier->registration_token) }}" class="btn">
            Completar Cadastro
        </a>
    </div>

    <p style="font-size: 14px; color: #6b7280; text-align: center;">
        Se o botão acima não funcionar, copie e cole o link abaixo no seu navegador:<br>
        <a href="{{ url('/supplier/register/' . $supplier->registration_token) }}" style="color: #2563eb;">{{ url('/supplier/register/' . $supplier->registration_token) }}</a>
    </p>

    <div class="divider"></div>
    <p style="margin-bottom:0;">Atenciosamente,<br>
        <strong>{{ $senderUser->name ?? 'Equipe de Procurement' }}</strong><br>
        <strong>procurement@mosap3.ao</strong><br>
    </p>
@endsection
