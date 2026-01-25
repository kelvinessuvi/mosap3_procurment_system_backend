<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <style>
        /* Base */
        body {
            background-color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            font-size: 16px;
            line-height: 1.5;
            margin: 0;
            padding: 0;
            width: 100% !important;
            color: #1f2937;
        }
        /* Layout */
        .wrapper {
            background-color: #f3f4f6;
            margin: 0;
            padding: 40px 0;
            width: 100%;
        }
        .container {
            margin: 0 auto;
            max-width: 600px;
            width: 600px;
        }
        .card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        /* Components */
        .header {
            background-color: #2563eb;
            padding: 24px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            font-size: 20px;
            font-weight: bold;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .body {
            padding: 32px;
        }
        .footer {
            margin-top: 24px;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
        }
        /* Typography */
        h1, h2, h3 {
            color: #111827;
            margin-top: 0;
        }
        p {
            margin-bottom: 16px;
        }
        /* Utilities */
        .btn {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 600;
            margin: 16px 0;
            text-align: center;
        }
        .btn:hover {
            background-color: #1d4ed8;
        }
        .divider {
            border-top: 1px solid #e5e7eb;
            margin: 24px 0;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .table th {
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
            padding: 8px;
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
        }
        .table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 8px;
            font-size: 14px;
        }
        /* Mobile */
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; padding: 0 16px; }
            .card { width: 100% !important; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <!-- Card -->
            <div class="card">
                <!-- Header -->
                <div class="header">
                    <h1>{{ config('app.name', 'MOSAP3 Procurement') }}</h1>
                </div>

                <!-- Body -->
                <div class="body">
                    @yield('content')
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>&copy; {{ date('Y') }} {{ config('app.name', 'MOSAP3 Procurement') }}. Todos os direitos reservados.</p>
                <p>Este email foi enviado automaticamente. Por favor, não responda diretamente.</p>
            </div>
        </div>
    </div>
</body>
</html>
