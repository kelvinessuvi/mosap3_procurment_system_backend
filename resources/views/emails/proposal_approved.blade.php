<!DOCTYPE html>
<html>
<head>
    <title>Proposta Aprovada</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #10b981; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .footer { background-color: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; border-radius: 0 0 5px 5px; }
        .badge { background-color: #10b981; color: white; padding: 5px 10px; border-radius: 3px; font-weight: bold; }
        .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #10b981; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✓ Proposta Aprovada</h1>
        </div>
        
        <div class="content">
            <p>Prezado fornecedor <strong>{{ $supplier->commercial_name }}</strong>,</p>
            
            <p>Temos o prazer de informar que sua proposta foi <span class="badge">APROVADA</span>!</p>
            
            <div class="info-box">
                <strong>Pedido de Cotação:</strong> {{ $quotationRequest->reference_number }}<br>
                <strong>Título:</strong> {{ $quotationRequest->title }}<br>
                <strong>Data de Submissão:</strong> {{ $quotationResponse->submitted_at->format('d/m/Y H:i') }}
            </div>
            
            @if($quotationResponse->review_notes)
            <div class="info-box">
                <strong>Observações:</strong><br>
                {{ $quotationResponse->review_notes }}
            </div>
            @endif
            
            <p><strong>Próximos passos:</strong></p>
            <ul>
                <li>Nossa equipe entrará em contato em breve para os próximos procedimentos</li>
                <li>Por favor, mantenha-se disponível para esclarecimentos adicionais</li>
                <li>Aguarde informações sobre o processo de aquisição</li>
            </ul>
            
            <p>Agradecemos pela sua participação e profissionalismo.</p>
        </div>
        
        <div class="footer">
            <p>Atenciosamente,<br>
            <strong>Equipe de Procurement MOSAP3</strong></p>
        </div>
    </div>
</body>
</html>
