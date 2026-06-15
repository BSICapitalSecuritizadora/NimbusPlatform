<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/jpeg" href="{{ asset('favicon.jpg') }}">
    <title inertia>{{ config('app.name', 'BSI Capital') }}</title>
    @vite('resources/js/app.ts')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
