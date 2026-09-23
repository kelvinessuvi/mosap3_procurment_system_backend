<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Definir Senha - MOSAP3</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-lg shadow-md p-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-2 text-center">Bem-vindo(a), {{ $user->name }}</h1>
        <p class="text-gray-600 text-sm mb-6 text-center">
            Defina a senha da sua conta para concluir a activação.
        </p>

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3">
                <ul class="text-sm text-red-700 list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ url('/email/activate') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" value="{{ $user->email }}" disabled
                       class="w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-gray-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Senha</label>
                <input type="password" name="password" required minlength="8" autofocus
                       class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-green-600 focus:outline-none">
                <p class="mt-1 text-xs text-gray-500">Mínimo de 8 caracteres.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha</label>
                <input type="password" name="password_confirmation" required minlength="8"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-green-600 focus:outline-none">
            </div>

            <button type="submit"
                    class="w-full rounded-md bg-green-700 px-4 py-2 font-semibold text-white hover:bg-green-800">
                Definir Senha e Activar Conta
            </button>
        </form>
    </div>
</body>
</html>
