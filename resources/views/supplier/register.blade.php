<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Fornecedor - MOSAP3</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen py-8">
    <div class="max-w-3xl mx-auto bg-white rounded-lg shadow-md p-8">
        <div class="text-center mb-8">
            <img src="https://mosap3-api.yetuware.com/logo.svg" alt="MOSAP3 Logo" class="h-16 mx-auto mb-4">
            <h1 class="text-2xl font-bold text-gray-800">Registo de Fornecedor</h1>
            <p class="text-gray-600 mt-2">Preencha os dados da sua empresa para completar o registo</p>
        </div>

        @if ($errors->any())
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                <ul class="list-disc list-inside text-red-700 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('success'))
            <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
                <p class="text-green-700 text-sm">{{ session('success') }}</p>
            </div>
        @endif

        <form action="{{ url('/api/supplier/register/' . $token) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <!-- Dados da Empresa -->
            <fieldset class="border border-gray-200 rounded-lg p-6">
                <legend class="text-lg font-semibold text-gray-700 px-2">Dados da Empresa</legend>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="email" value="{{ old('email', $supplier->email) }}" readonly
                               class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 text-gray-500 cursor-not-allowed">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Razão Social *</label>
                        <input type="text" name="legal_name" value="{{ old('legal_name') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nome Comercial *</label>
                        <input type="text" name="commercial_name" value="{{ old('commercial_name') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Telefone *</label>
                        <input type="text" name="phone" value="{{ old('phone') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">NIF *</label>
                        <input type="text" name="nif" value="{{ old('nif') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Atividade *</label>
                        <select name="activity_type" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            <option value="">Selecione...</option>
                            <option value="service" @selected(old('activity_type') === 'service')>Serviços</option>
                            <option value="commerce" @selected(old('activity_type') === 'commerce Geral')>Comércio</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Província *</label>
                        <input type="text" name="province" value="{{ old('province') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Município *</label>
                        <input type="text" name="municipality" value="{{ old('municipality') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Endereço</label>
                        <textarea name="address" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">{{ old('address') }}</textarea>
                    </div>
                </div>
            </fieldset>

            <!-- Categorias -->
            <fieldset class="border border-gray-200 rounded-lg p-6">
                <legend class="text-lg font-semibold text-gray-700 px-2">Categorias de Produtos/Serviços *</legend>
                <p class="text-sm text-gray-500 mb-3">Selecione as categorias nas quais a sua empresa atua.</p>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    @foreach ($categories as $category)
                        <label class="flex items-center space-x-2 p-2 border border-gray-200 rounded-md hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="categories[]" value="{{ $category->id }}"
                                   @if(is_array(old('categories')) && in_array($category->id, old('categories'))) checked @endif
                                   class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                            <span class="text-sm text-gray-700">{{ $category->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <!-- Documentos -->
            <fieldset class="border border-gray-200 rounded-lg p-6">
                <legend class="text-lg font-semibold text-gray-700 px-2">Documentos</legend>
                <p class="text-sm text-gray-500 mb-3">Formatos aceites: PDF, JPG, PNG (máx 5MB cada).</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Certificado Comercial *</label>
                        <input type="file" name="commercial_certificate" accept=".pdf,.jpg,.png" required
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Alvará Comercial</label>
                        <input type="file" name="commercial_license" accept=".pdf,.jpg,.png"
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Comprovativo de NIF *</label>
                        <input type="file" name="nif_proof" accept=".pdf,.jpg,.png" required
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Pacto Social</label>
                        <input type="file" name="pacto_social" accept=".pdf,.jpg,.png"
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Certificado de Não Devedor</label>
                        <input type="file" name="non_debtor_certificate" accept=".pdf,.jpg,.png"
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Lista de Produtos</label>
                        <input type="file" name="product_list" accept=".pdf,.jpg,.png,.xlsx,.xls"
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                    </div>
                </div>
            </fieldset>

            <div class="text-center">
                <button type="submit"
                        class="bg-green-700 text-white px-8 py-3 rounded-md font-semibold hover:bg-green-800 transition-colors">
                    Concluir Registo
                </button>
            </div>
        </form>
    </div>
</body>
</html>
