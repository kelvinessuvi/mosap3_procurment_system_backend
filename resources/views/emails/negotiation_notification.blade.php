<!DOCTYPE html>
<html>
<head>
    <title>Solicitação de Revisão - {{ $quotation->title }}</title>
</head>
<body>
    <h1>Solicitação de Revisão</h1>
    <p>Prezado fornecedor,</p>
    
    <p>A sua proposta para <strong>{{ $quotation->title }}</strong> foi analisada e solicitamos uma revisão.</p>
    
    <p><strong>Motivo:</strong> {{ $notification->reason }}</p>
    <p><strong>Mensagem:</strong> {{ $notification->message }}</p>
    
    <p>Por favor, acesse o link abaixo para enviar uma nova versão da sua proposta:</p>
    
    <p>
        <a href="{{ url('/quotation/' . $token) }}">
            Enviar Nova Proposta (Revisão)
        </a>
    </p>
    
    <p>Atenciosamente,<br>Equipe de Procurement MOSAP3</p>
</body>
</html>
