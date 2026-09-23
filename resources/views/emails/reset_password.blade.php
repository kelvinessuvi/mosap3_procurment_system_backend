@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #111827;">Recuperação de Senha</h2>

    <p>Prezado(a) <strong>{{ $user->name }}</strong>,</p>

    <p>Recebemos um pedido de recuperação de senha para a conta associada ao email <strong>{{ $user->email }}</strong>.</p>

    <p>Introduza o código abaixo na plataforma para definir uma nova senha:</p>

    <div class="code-box" style="background-color:#f3f4f6; border:1px dashed #148742; border-radius:8px; color:#148742; font-family:'Courier New', Courier, monospace; font-size:34px; font-weight:bold; letter-spacing:10px; margin:24px auto; padding:18px 12px; text-align:center;">
        {{ $code }}
    </div>

    <p style="font-size: 14px; color: #6b7280;">
        Este código é válido durante {{ $expiresInMinutes }} minutos e só pode ser usado uma vez.
        Se não pediu a recuperação de senha, ignore esta mensagem — a sua senha permanece inalterada.
    </p>

    <p style="font-size: 14px; color: #6b7280;">
        Nunca partilhe este código com terceiros. A equipa MOSAP3 nunca lhe pedirá este código por telefone ou email.
    </p>

    <div class="divider"></div>
    <p style="margin-bottom:0;">Atenciosamente,<br>
        <strong>Equipe de Procurement</strong><br>
        <strong>procurement@mosap3.ao</strong><br>
    </p>
@endsection
