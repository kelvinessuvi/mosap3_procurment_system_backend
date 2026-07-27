@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #111827;">Registro Aprovado</h2>

    <p>Prezado(a) <strong>{{ $supplier->company_name }}</strong>,</p>

    <p>Temos o prazer de informar que o seu registro como fornecedor na plataforma <strong>MOSAP3 Procurement</strong> foi <strong style="color: #16a34a;">aprovado</strong>.</p>

    <p>Agora você poderá receber convites para participar de processos de aquisição e enviar as suas propostas.</p>

    <div class="divider"></div>
    <p style="margin-bottom:0;">Atenciosamente,<br>
        <strong>{{ $senderUser->name ?? 'Equipe de Procurement' }}</strong><br>
        <strong>procurement@mosap3.ao</strong><br>
    </p>
@endsection
