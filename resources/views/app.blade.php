<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- The value proposition, where a crawler can read it without running any JavaScript.

             Here rather than in the landing page's own <Head>: there is no Inertia SSR, so a meta tag
             declared in a Vue page only exists once the bundle has run. The landing page is the one
             page anybody indexes, and every other page in the application is behind a login, so one
             server-rendered description covering the product is right for all of them. If a second
             public page ever wants its own, it belongs in the controller, not duplicated here - two
             descriptions on one document is worse than this one. --}}
        <meta name="description" content="Steel nesting software that halves cutting waste, so you spend less on steel. Cross-project nesting and offcut tracking, built for Australian fabricators.">
        <meta property="og:title" content="{{ config('app.name', 'Laravel') }}">
        <meta property="og:description" content="Steel nesting software that halves cutting waste, so you spend less on steel.">
        <meta property="og:type" content="website">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        {{-- 700 is here because headings use font-bold; without it the browser fakes the weight --}}
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Fontawesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <!-- analytics -->
        {{-- Production only, so local and any staging host stay out of the figures. APP_ENV is the
             gate, not the hostname: a URL sniff counted anything served off a steelnesting domain --}}
        @production
            <!-- Google tag (gtag.js) -->
            <script async src="https://www.googletagmanager.com/gtag/js?id=G-J6FQY25TJY"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());

                gtag('config', 'G-J6FQY25TJY');
            </script>
        @endproduction

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
