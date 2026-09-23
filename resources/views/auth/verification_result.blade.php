<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} - MOSAP3</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-lg w-full bg-white rounded-lg shadow-md p-8 text-center">
        @if ($status === 'success')
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-green-100">
                <span class="text-3xl text-green-600">&check;</span>
            </div>
        @else
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-100">
                <span class="text-3xl text-red-600">&times;</span>
            </div>
        @endif

        <h1 class="text-2xl font-bold text-gray-800 mb-3">{{ $title }}</h1>
        <p class="text-gray-600 mb-6">{{ $message }}</p>

        @if ($status === 'success')
            <p class="text-sm text-gray-400">Pode fechar esta página e iniciar sessão.</p>
        @else
            <p class="text-sm text-gray-400">Pode fechar esta página.</p>
        @endif
    </div>
</body>
</html>
