<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <meta name="description" content="Generate images with Pixelforge and pay only for what you use. See the price before you generate.">
        <meta name="theme-color" content="#0a0a0a">
        @if (config('sentry.dsn'))
            {{-- Browser error reporting (resources/js/lib/sentry.ts). A DSN is public by design; user is an id only, no email. --}}
            <meta name="sentry-dsn" content="{{ config('sentry.dsn') }}">
            <meta name="sentry-environment" content="{{ config('sentry.environment') }}">
            @if (config('sentry.release'))<meta name="sentry-release" content="{{ config('sentry.release') }}">@endif
            @auth<meta name="sentry-user" content="{{ auth()->id() }}">@endauth
        @endif

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
