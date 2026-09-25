@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #148742;">{{ $title }}</h2>

    <p>Olá{{ $recipientName ? ' ' . $recipientName : '' }},</p>

    <p>{{ $messageText }}</p>

    @if(!empty($details))
    <div style="background-color: #f0fff4; border: 1px solid #44B16F; border-radius: 8px; padding: 16px; margin: 24px 0;">
        @foreach($details as $label => $value)
            @if($value !== null && $value !== '')
            <p style="margin: 0 0 8px;"><strong>{{ $label }}:</strong> {{ $value }}</p>
            @endif
        @endforeach
    </div>
    @endif

    @if($actionUrl)
    <p style="text-align: center; margin: 32px 0;">
        <a href="{{ $actionUrl }}" style="background-color: #44B16F; color: #ffffff; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-block;">
            Abrir no painel
        </a>
    </p>
    @endif

    <div class="divider"></div>

    <p style="margin-bottom:0; color: #6b7280; font-size: 14px;">
        Esta é uma notificação automática da Plataforma de Gestão de Fornecedores MOSAP3.
    </p>
@endsection
