@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #111827;">Active a sua conta</h2>

    <p>Prezado(a) <strong>{{ $user->name }}</strong>,</p>

    <p>Foi criada uma conta para si na plataforma <strong>MOSAP3 Procurement</strong> com o email <strong>{{ $user->email }}</strong>.</p>

    <p>Por motivos de segurança, ninguém definiu uma senha por si. Clique no botão abaixo para escolher a sua própria senha e activar a conta.</p>

    <div style="text-align: center;">
        <a href="{{ $verificationUrl }}" class="btn">
            Definir Senha e Activar Conta
        </a>
    </div>

    <p style="font-size: 14px; color: #6b7280; text-align: center;">
        Se o botão acima não funcionar, copie e cole o link abaixo no seu navegador:<br>
        <a href="{{ $verificationUrl }}" style="color: #2563eb;">{{ $verificationUrl }}</a>
    </p>

    <p style="font-size: 14px; color: #6b7280;">
        Este link é válido durante {{ $expiresInHours }} horas e só pode ser usado uma vez.
        Nunca o partilhe com terceiros. Se não reconhece este registo, ignore esta mensagem.
    </p>

    <div class="divider"></div>
    <p style="margin-bottom:0;">Atenciosamente,<br>
        <strong>Equipe de Procurement</strong><br>
        <strong>procurement@mosap3.ao</strong><br>
    </p>
@endsection
