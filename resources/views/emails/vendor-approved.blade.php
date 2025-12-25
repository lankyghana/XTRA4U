<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vendor Approved</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #111827;">
    <p>Hello {{ $vendor->name ?? 'Vendor' }},</p>

    <p>Your XTRA4U vendor account has been approved. You can now log in and start selling.</p>

    <p>
        Login: <a href="{{ rtrim($appUrl, '/') }}/vendor/login">{{ rtrim($appUrl, '/') }}/vendor/login</a>
    </p>

    <p>If you did not request this account, you can ignore this email.</p>

    <p>— {{ config('mail.from.name', config('app.name')) }}</p>
</body>
</html>
