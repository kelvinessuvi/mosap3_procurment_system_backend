@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #ef4444;">Atualização sobre sua Proposta</h2>
    
    <p>Prezado fornecedor <strong>{{ $supplier->commercial_name }}</strong>,</p>
    
    <p>Agradecemos imensamente seu interesse e o envio da proposta para nossa solicitação.</p>
    
    <div style="background-color: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; padding: 16px; margin: 24px 0;">
        <p style="margin: 0 0 8px;"><strong>Pedido:</strong> {{ $quotationRequest->reference_number }}</p>
        <p style="margin: 0;"><strong>Título:</strong> {{ $quotationRequest->title }}</p>
    </div>
    
    <p>Após criteriosa análise técnica e comercial, informamos que sua proposta <strong>não foi selecionada</strong> para prosseguimento neste momento.</p>

    @if($quotationResponse->review_notes)
    <p><strong>Feedback da Equipe:</strong></p>
    <blockquote style="border-left: 4px solid #e5e7eb; margin: 0; padding-left: 16px; color: #6b7280;">
        "{{ $quotationResponse->review_notes }}"
    </blockquote>
    @endif
    
    <div class="divider"></div>
    
    <p>Valorizamos sua parceria e manteremos seus dados para futuros processos de compras.</p>
    <p style="margin-bottom:0;">Atenciosamente,<br><strong>Equipe de Procurement</strong></p>
@endsection
