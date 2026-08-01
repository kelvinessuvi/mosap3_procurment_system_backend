@extends('emails.layouts.master')

@section('content')
    @php
        $isQuotation = $entityType === 'quotation';
        $ref = $entity->reference_number;
        $deadline = $isQuotation ? $entity->deadline : $entity->expected_delivery_date;
        $isSupplier = $recipientKind === 'supplier';
        $daysOverdue = $trigger === 'overdue' ? \Carbon\Carbon::today()->diffInDays(\Carbon\Carbon::parse($deadline)) : 0;
    @endphp

    <h2 style="text-align: center; color: #111827;">
        @if($trigger === 'overdue')
            Alerta de {{ $isQuotation ? 'Prazo Excedido' : 'Atraso de Entrega' }}
        @else
            Lembrete de {{ $isQuotation ? 'Prazo' : 'Entrega' }}
        @endif
    </h2>

    <p>Prezado(a) <strong>{{ $recipientName ?? 'Equipe de Procurement' }}</strong>,</p>

    @if($isQuotation)
        <div style="background-color: #eff6ff; border-left: 4px solid #2563eb; padding: 16px; margin: 24px 0;">
            <p style="margin: 0 0 8px;"><strong>System ID:</strong> {{ $ref }}</p>
            <p style="margin: 0 0 8px;"><strong>Título da actividade:</strong> {{ $entity->title }}</p>
            <p style="margin: 0 0 8px;"><strong>Referência PP:</strong> {{ $entity->activity_description }}</p>
            <p style="margin: 0;"><strong>Prazo Limite:</strong> {{ \Carbon\Carbon::parse($deadline)->format('d/m/Y H:i') }}</p>
        </div>

        @if($trigger === 't_minus_2')
            <p>Este é um lembrete automático: <strong>faltam 2 dias</strong> para o prazo de resposta da cotação acima.</p>
        @elseif($trigger === 't_minus_1')
            <p>Este é um alerta de reforço: o prazo de resposta termina <strong>AMANHÃ</strong>.</p>
        @elseif($trigger === 'due_date')
            <p>O prazo de resposta termina <strong>HOJE</strong>.</p>
        @else
            <div style="background-color: #fef2f2; border-left: 4px solid #dc2626; padding: 16px; margin: 24px 0;">
                <p style="margin: 0; color: #991b1b;"><strong>O prazo foi excedido há {{ $daysOverdue }} dia(s).</strong></p>
            </div>
            @if($isSupplier)
                <p>Ainda pode submeter a sua proposta, caso tenha interesse em participar.</p>
            @else
                <p>Recomendamos verificar o estado do processo e, se necessário, contactar os fornecedores.</p>
            @endif
        @endif
    @else
        <div style="background-color: #f0fdf4; border-left: 4px solid #16a34a; padding: 16px; margin: 24px 0;">
            <p style="margin: 0 0 8px;"><strong>Referência:</strong> {{ $ref }}</p>
            <p style="margin: 0 0 8px;"><strong>Fornecedor:</strong> {{ $entity->supplier?->company_name }}</p>
            <p style="margin: 0;"><strong>Data prevista de entrega:</strong> {{ \Carbon\Carbon::parse($deadline)->format('d/m/Y') }}</p>
        </div>

        @if($trigger === 't_minus_2')
            <p><strong>Faltam 2 dias</strong> para a data de entrega prevista.</p>
        @elseif($trigger === 't_minus_1')
            <p>A entrega está prevista para <strong>AMANHÃ</strong>.</p>
        @elseif($trigger === 'due_date')
            <p>A entrega está prevista para <strong>HOJE</strong>.</p>
        @else
            <div style="background-color: #fef2f2; border-left: 4px solid #dc2626; padding: 16px; margin: 24px 0;">
                <p style="margin: 0; color: #991b1b;"><strong>A entrega está ATRASADA há {{ $daysOverdue }} dia(s).</strong></p>
            </div>
            <p>Recomendamos contactar o fornecedor para actualizar a data de entrega.</p>
        @endif
    @endif

    @if($isSupplier && $token)
        <div style="text-align: center; margin-top: 24px;">
            <a href="{{ url('/quotation/' . $token) }}" class="btn">Visualizar e Enviar Proposta</a>
        </div>
        <p style="font-size: 14px; color: #6b7280; text-align: center;">
            Se o botão acima não funcionar, copie e cole o link abaixo no seu navegador:<br>
            <a href="{{ url('/quotation/' . $token) }}" style="color: #2563eb;">{{ url('/quotation/' . $token) }}</a>
        </p>
    @endif

    <div class="divider"></div>
    <p style="margin-bottom:0;">Atenciosamente,<br>
        <strong>Equipe de Procurement</strong><br>
        <strong>procurement@mosap3.ao</strong><br>
    </p>
@endsection
