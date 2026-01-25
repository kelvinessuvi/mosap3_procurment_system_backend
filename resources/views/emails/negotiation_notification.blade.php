@extends('emails.layouts.master')

@section('content')
    <div style="text-align: center;">
        <h2 style="color: #d97706;">Solicitação de Revisão</h2>
        <p style="color: #6b7280;">Sua proposta requer ajustes</p>
    </div>
    
    <p>Prezado fornecedor,</p>
    
    <p>Analisamos sua proposta para <strong>{{ $quotation->title }}</strong> e gostaríamos de solicitar uma revisão nos seguintes pontos:</p>
    
    <div style="background-color: #fffbeb; border-left: 4px solid #d97706; padding: 16px; margin: 24px 0;">
        <p style="margin: 0 0 8px;"><strong>Motivo:</strong> {{ $notification->reason }}</p>
        <p style="margin: 0;"><strong>Mensagem:</strong><br>{{ $notification->message }}</p>
    </div>
    
    <div style="text-align: center;">
        <a href="{{ url('/quotation/' . $token) }}" class="btn">
            Enviar Nova Proposta (Revisão)
        </a>
    </div>
    
    <p style="margin-top: 24px;">Por favor, envie a revisão o mais breve possível para prosseguirmos com o processo.</p>
    
    <div class="divider"></div>
    <p style="margin-bottom:0;">Atenciosamente,<br><strong>Equipe de Procurement</strong></p>
@endsection
