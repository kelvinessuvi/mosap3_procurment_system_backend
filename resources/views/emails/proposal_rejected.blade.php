<!DOCTYPE html>
<html>
<head>
    <title>Proposta Recusada</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #ef4444; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .footer { background-color: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; border-radius: 0 0 5px 5px; }
        .badge { background-color: #ef4444; color: white; padding: 5px 10px; border-radius: 3px; font-weight: bold; }
        .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #ef4444; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Proposta Recusada</h1>
        </div>
        
        <div class="content">
            <p>Prezado fornecedor <strong>{{ $supplier->commercial_name }}</strong>,</p>
            
            <p>Agradecemos pela submissão da sua proposta. Informamos que, após análise técnica e financeira, sua proposta não foi selecionada neste momento.</p>
            
            <div class="info-box">
                <strong>Pedido de Cotação:</strong> {{ $quotationRequest->reference_number }}<br>
                <strong>Título:</strong> {{ $quotationRequest->title }}<br>
                <strong>Data de Submissão:</strong> {{ $quotationResponse->submitted_at->format('d/m/Y H:i') }}
            </div>
            
            @if($quotationResponse->review_notes)
            <div class="info-box">
                <strong>Motivo:</strong><br>
                {{ $quotationResponse->review_notes }}
            </div>
            @endif
            
            <p>Valorizamos muito sua participação no processo e esperamos contar com sua parceria em futuras oportunidades de negócio.</p>
            
            <p>Continuaremos a considerar sua empresa para próximos pedidos de cotação que correspondam ao seu perfil e capacidades.</p>
            
            <p>Agradecemos pela compreensão e pelo profissionalismo demonstrado.</p>
        </div>
        
        <div class="footer">
            <p>Atenciosamente,<br>
            <strong>Equipe de Procurement MOSAP3</strong></p>
        </div>
    </div>
</body>
</html>
