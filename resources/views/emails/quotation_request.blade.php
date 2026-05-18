@extends('emails.layouts.master')

@section('content')
    <h2 style="text-align: center; color: #111827;">Convite para Cotação</h2>
    
    <!--<p>Prezado fornecedor <strong>{{ $supplier->commercial_name }}</strong>,</p>
    
    <p>Sua empresa foi selecionada para participar de um processo de aquisição. Abaixo estão os detalhes da solicitação:</p>
-->
    <div style="background-color: #eff6ff; border-left: 4px solid #2563eb; padding: 16px; margin: 24px 0;">
        <p style="margin: 0 0 8px;"><strong>System ID:</strong> {{ $quotation->reference_number }}</p>
        <p style="margin: 0 0 8px;"><strong>Referência:</strong> {{ $quotation->title }}</p>
        <p style="margin: 0;"><strong>Prazo Limite:</strong> {{ $quotation->deadline->format('d/m/Y H:i') }}</p>
    </div>

    @if($quotation->description)
    <p><strong>Mensagem</strong><br>{{ $quotation->description }}</p>
    @endif

    @if($quotation->attachments && count($quotation->attachments) > 0)
    <div style="background-color: #f0fdf4; border-left: 4px solid #16a34a; padding: 16px; margin: 24px 0;">
        <p style="margin: 0 0 8px;"><strong>📎 Documentos Anexados:</strong></p>
        <p style="margin: 0; font-size: 14px; color: #374151;">
            {{ count($quotation->attachments) }} documento(s) anexado(s). Acesse o link abaixo para visualizar.
        </p>
    </div>
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
    <p style="margin-bottom:0;">Atenciosamente,<br>
        <strong>{{ $senderUser->name ?? 'Equipe de Procurement' }}</strong><br>
    </p>
@endsection
