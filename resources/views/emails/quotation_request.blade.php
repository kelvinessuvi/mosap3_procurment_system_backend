@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #111827;">Convite para Cotação</h2>
    
    <p>Prezado fornecedor <strong>{{ $supplier->commercial_name }}</strong>,</p>
    
    <p>Sua empresa foi selecionada para participar de um processo de aquisição. Abaixo estão os detalhes da solicitação:</p>
    
    <div style="background-color: #eff6ff; border-left: 4px solid #2563eb; padding: 16px; margin: 24px 0;">
        <p style="margin: 0 0 8px;"><strong>Referência:</strong> {{ $quotation->reference_number }}</p>
        <p style="margin: 0 0 8px;"><strong>Título:</strong> {{ $quotation->title }}</p>
        <p style="margin: 0;"><strong>Prazo Limite:</strong> {{ $quotation->deadline->format('d/m/Y H:i') }}</p>
    </div>

    @if($quotation->description)
    <p><strong>Descrição:</strong><br>{{ $quotation->description }}</p>
    @endif
    
    <div style="text-align: center;">
        <a href="{{ url('/quotation/' . $token) }}" class="btn">
            Visualizar e Enviar Proposta
        </a>
    </div>
    
    <p style="font-size: 14px; color: #6b7280; text-align: center;">
        Se o botão acima não funcionar, copie e cole o link abaixo no seu navegador:<br>
        <a href="{{ url('/quotation/' . $token) }}" style="color: #2563eb;">{{ url('/quotation/' . $token) }}</a>
    </p>

    <div class="divider"></div>
    <p style="margin-bottom:0;">Atenciosamente,<br><strong>Equipe de Procurement</strong></p>
@endsection
