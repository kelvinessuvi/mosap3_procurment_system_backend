@extends('emails.layouts.master')

@section('content')
    <div style="text-align: center; margin-bottom: 24px;">
        <span style="background-color: #10b981; color: white; padding: 8px 16px; border-radius: 20px; font-weight: bold; font-size: 14px;">APROVADA</span>
    </div>

    <h2 style="text-align: center; color: #065f46;">Parabéns! Sua proposta foi aprovada.</h2>
    
    <p>Prezado fornecedor <strong>{{ $supplier->commercial_name }}</strong>,</p>
    
    <p>Temos o prazer de informar que sua proposta foi selecionada em nosso processo de aquisição.</p>
    
    <div style="background-color: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; padding: 16px; margin: 24px 0;">
        <p style="margin: 0 0 8px;"><strong>Pedido:</strong> {{ $quotationRequest->reference_number }}</p>
        <p style="margin: 0 0 8px;"><strong>Título:</strong> {{ $quotationRequest->title }}</p>
        <p style="margin: 0;"><strong>Data de Envio:</strong> {{ $quotationResponse->submitted_at->format('d/m/Y') }}</p>
    </div>
    
    @if($quotationResponse->review_notes)
    <p><strong>Observações da Aprovação:</strong></p>
    <blockquote style="border-left: 4px solid #10b981; margin: 0; padding-left: 16px; color: #4b5563; font-style: italic;">
        "{{ $quotationResponse->review_notes }}"
    </blockquote>
    @endif
    
    <div class="divider"></div>

    <h3>Próximos Passos</h3>
    <ul>
        <li>Nossa equipe entrará em contato em breve para formalizar o pedido.</li>
        <li>Fique atento para a emissão da Ordem de Compra.</li>
    </ul>
    
    <p style="margin-top: 24px;">Agradecemos pelo excelente trabalho!</p>
    <p style="margin-bottom:0;">Atenciosamente,<br><strong>Equipe de Procurement</strong></p>
@endsection
