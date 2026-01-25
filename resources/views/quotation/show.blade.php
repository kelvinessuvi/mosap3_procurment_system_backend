<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Cotação - {{ $quotation->reference_number }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden mb-8">
            <div class="px-6 py-8 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ $quotation->title }}</h1>
                        <p class="text-sm text-gray-500 mt-1">Ref: {{ $quotation->reference_number }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Prazo de Entrega</p>
                        <p class="text-lg font-semibold text-red-600">{{ \Carbon\Carbon::parse($quotation->deadline)->format('d/m/Y H:i') }}</p>
                    </div>
                </div>
            </div>
            
            <div class="px-6 py-6 bg-gray-50">
                <p class="text-gray-700">{{ $quotation->description }}</p>
            </div>
        </div>

        <!-- Items Table -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-medium text-gray-900">Itens Solicitados</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantidade</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Especificações</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($quotation->items as $item)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $item->name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $item->quantity }} {{ $item->unit }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $item->description ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Actions -->
        @if($errors->any())
            <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4">
                <div class="flex">
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Erro ao processar solicitação:</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <ul class="list-disc pl-5 space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="flex justify-end gap-4">
            <!-- Decline Form -->
            <form action="{{ url('/api/quotation/' . $token . '/decline') }}" method="POST" onsubmit="return confirm('Tem certeza que deseja recusar este pedido?');">
                <!-- Como é API, technically deveria enviar JSON, mas para simplificar vamos fazer um POST form normal e tratar redirect se possível, ou usar JS. 
                     Para este MVP, vamos criar uma interface visual simples que usa JS fetch para interagir com a API existente. -->
            </form>

            <!-- Interface JS para interagir com a API -->
             <div class="flex gap-4" id="action-buttons">
                <button onclick="declineQuotation()" class="bg-red-100 text-red-700 px-6 py-3 rounded-md font-medium hover:bg-red-200 transition">
                    Recusar Pedido
                </button>
                <button onclick="openSubmissionModal()" class="bg-blue-600 text-white px-6 py-3 rounded-md font-medium hover:bg-blue-700 transition shadow">
                    Enviar Proposta
                </button>
            </div>
        </div>

        <!-- Status Message -->
        <div id="status-message" class="hidden mt-4 p-4 rounded-md"></div>
    </div>

    <!-- Modal de Envio (Simplificado) -->
    <div id="submission-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Enviar Proposta</h3>
                
                <form id="proposal-form" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Data de Entrega</label>
                            <input type="date" name="delivery_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Dias para Entrega</label>
                            <input type="number" name="delivery_days" required min="1" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Termos de Pagamento</label>
                            <input type="text" name="payment_terms" required placeholder="Ex: 50% Adjudicação, 50% Entrega" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4">
                         @foreach($quotation->items as $index => $item)
                        <div class="border p-4 rounded bg-gray-50">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ $item->name }} ({{ $item->quantity }})</label>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-xs text-gray-500">Preço Total (KZ)</label>
                                    <input type="number" step="0.01" name="items[{{ $index }}][unit_price]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2">
                                    <input type="hidden" name="items[{{ $index }}][quotation_item_id]" value="{{ $item->id }}">
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Observações</label>
                                    <input type="text" name="items[{{ $index }}][notes]" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2">
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <div>
                         <label class="block text-sm font-medium text-gray-700 mb-1">Anexo (Proposta PDF)</label>
                         <input type="file" id="proposal_file" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"/>
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancelar</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Enviar Cotação</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const TOKEN = "{{ $token }}";
        
        function declineQuotation() {
            if(!confirm('Deseja realmente recusar este pedido?')) return;
            
            fetch(`/api/quotation/${TOKEN}/decline`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            })
            .then(response => {
                if(response.ok) {
                    alert('Pedido recusado com sucesso.');
                    location.reload();
                } else {
                    alert('Erro ao recusar pedido.');
                }
            });
        }

        function openSubmissionModal() {
            document.getElementById('submission-modal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('submission-modal').classList.add('hidden');
        }

        document.getElementById('proposal-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            
            // Build items array logic would go here, simpler to construct object then append
            // For MVP, simplistic gathering:
            const items = [];
            const inputs = this.querySelectorAll('input[name^="items"]');
            
            // This is a naive implementation, properly you'd group by index
            // But since names are items[0][unit_price], FormData handles this natively somewhat, 
            // but the API expects JSON for items and Multipart for file.
            
            // Construct JSON for items
            const formElements = this.elements;
            const itemsData = [];
            
            // Group inputs by index
            const itemMap = {};
             
            for(let i=0; i < formElements.length; i++) {
                const name = formElements[i].name;
                if(name.startsWith('items[')) {
                    const match = name.match(/items\[(\d+)\]\[(\w+)\]/);
                    if(match) {
                        const index = match[1];
                        const field = match[2];
                        if(!itemMap[index]) itemMap[index] = {};
                        itemMap[index][field] = formElements[i].value;
                    }
                }
            }
            
            formData.append('items', JSON.stringify(Object.values(itemMap))); // Send as JSON string
            
            const fileInput = document.getElementById('proposal_file');
            if(fileInput.files[0]) {
                formData.append('proposal_file', fileInput.files[0]);
            }

            try {
                // IMPORTANT: Since we are mixing JSON (items) and File, 
                // the existing API endpoint might expect strict JSON for items.
                // We'll need to check the Controller implementation. 
                // Standard approach: send everything as simple fields or adjust controller.
                // Assuming standard Laravel handling of array fields in multipart.
                
                // Let's retry sending as standard formdata "items[0][id]" etc
                const cleanFormData = new FormData(this);
                if(fileInput.files[0]) {
                    cleanFormData.set('proposal_file', fileInput.files[0]);
                }
                
                const response = await fetch(`/api/quotation/${TOKEN}/submit`, {
                    method: 'POST',
                    body: cleanFormData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if(response.ok) {
                    alert('Proposta enviada com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + (data.message || 'Falha ao enviar'));
                }
            } catch (error) {
                console.error(error);
                alert('Erro de conexão.');
            }
        });

        // Auto-fill delivery days
        const deliveryDateInput = document.querySelector('input[name="delivery_date"]');
        const deliveryDaysInput = document.querySelector('input[name="delivery_days"]');

        if (deliveryDateInput && deliveryDaysInput) {
            deliveryDateInput.addEventListener('change', function() {
                const selectedDate = new Date(this.value);
                const today = new Date();
                
                // Reset hours to compare just dates
                selectedDate.setHours(0,0,0,0);
                today.setHours(0,0,0,0);
                
                if (selectedDate > today) {
                    const diffTime = Math.abs(selectedDate - today);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
                    deliveryDaysInput.value = diffDays;
                } else {
                    // If date is today or past, logic might vary, but usually implies 0 or error.
                    // Validation usually prevents past dates ("after:now" in controller).
                    deliveryDaysInput.value = '';
                }
            });
        }
    </script>
</body>
</html>
