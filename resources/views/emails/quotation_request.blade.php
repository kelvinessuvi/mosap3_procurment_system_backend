<!DOCTYPE html>
<html>
<head>
    <title>Pedido de Cotação - {{ $quotation->title }}</title>
</head>
<body>
    <h1>Pedido de Cotação: {{ $quotation->reference_number }}</h1>
    <p>Prezado fornecedor <strong>{{ $supplier->commercial_name }}</strong>,</p>
    
    <p>Você foi convidado para enviar uma cotação para: <strong>{{ $quotation->title }}</strong></p>
    
    <p><strong>Prazo limite:</strong> {{ $quotation->deadline->format('d/m/Y H:i') }}</p>
    
    <p>Por favor, clique no link abaixo para visualizar os detalhes e submeter sua proposta:</p>
    
    <p>
        <a href="{{ url('/quotation/' . $token) }}">
            Acessar Pedido de Cotação
        </a>
    </p>
    
    <p>Link direto: {{ url('/quotation/' . $token) }}</p>
    
    <p>Atenciosamente,<br>Equipe de Procurement MOSAP3</p>
</body>
</html>
