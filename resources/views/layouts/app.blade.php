<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Form Builder') · {{ config('app.name') }}</title>

    {{--
        Two stylesheets, loaded in this order on purpose:

        1. The vendor admin theme (page chrome, sidebar, icons). Pre-compiled,
           served straight from public/theme, never touched by our build.
        2. Our Vite bundle, which carries Bootstrap 5 and our own components.
           Loading it second means our rules win any specificity tie.
    --}}
    <link rel="stylesheet" href="{{ asset('theme/plugins/sidemenu/sidemenu.css') }}">
    <link rel="stylesheet" href="{{ asset('theme/css/sidemenu.css') }}">
    <link rel="stylesheet" href="{{ asset('theme/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('theme/css/icons.css') }}">
    <link rel="stylesheet" href="{{ asset('theme/css/admin-custom.css') }}">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])

    @stack('styles')
</head>
<body class="app sidebar-mini">

<div class="page">
    <div class="page-main h-100">

        {{-- Header --}}
        <div class="app-header1 header py-2 d-flex">
            <div class="container-fluid">
                <div class="d-flex align-items-center">
                    <a class="app-sidebar__toggle" href="javascript:void(0)"
                       aria-label="Toggle sidebar">
                        <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 24 24"
                             fill="currentColor" aria-hidden="true">
                            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
                        </svg>
                    </a>

                    <div class="ms-auto d-flex align-items-center">
                        <strong class="text-dark" style="margin: 0 10px;">
                            {{ auth()->user()?->name ?? 'Guest User' }}
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="app-sidebar__overlay"></div>

        <aside class="app-sidebar">
            <a class="header-brand sidemenu-header-brand" href="{{ route('forms.index') }}">
                <span class="header-brand-img mobile-logo" style="color:#fff;font-size:24px;">FB</span>
            </a>

            <ul class="side-menu">
                @php
                    $nav = [
                        ['route' => 'forms.index',  'label' => 'Forms',            'icon' => 'fe-file-text'],
                        ['route' => 'forms.ai',      'label' => 'Generate with AI', 'icon' => 'fe-zap'],
                        ['route' => 'imports.create', 'label' => 'Import document', 'icon' => 'fe-upload'],
                        ['route' => 'forms.create',  'label' => 'New form',         'icon' => 'fe-plus-square'],
                    ];
                @endphp

                @foreach ($nav as $item)
                    <li class="slide">
                        <a class="side-menu__item {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                           href="{{ route($item['route']) }}">
                            <i class="side-menu__icon fe {{ $item['icon'] }}"></i>
                            <span class="side-menu__label">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </aside>

        {{-- Content --}}
        <div class="app-content">
            <div class="side-app">

                <div class="page-header">
                    <h4 class="page-title mb-0">@yield('heading', View::getSection('title', 'Form Builder'))</h4>

                    @hasSection('actions')
                        <div class="ms-auto">@yield('actions')</div>
                    @endif
                </div>

                @include('partials.flash')

                {{--
                    Two rendering paths land here, and the layout supports both:

                    - Traditional Blade views use @extends + @section('content'),
                      which @yield picks up.
                    - Full-page Livewire components (#[Layout('layouts.app')])
                      are rendered first and handed to the layout as $slot.

                    Section directives inside a Livewire component view still
                    work, because the component is rendered before the layout,
                    so @section('title') has already registered by the time the
                    layout's @yield runs.
                --}}
                @yield('content')

                {{ $slot ?? '' }}

            </div>
        </div>
    </div>
</div>

<footer class="footer">
    <div class="container">
        <div class="row align-items-center">
            <div class="col text-center">
                Copyright &copy; {{ date('Y') }}
                <span class="fs-14 text-primary">Form Builder</span>. All rights reserved.
            </div>
        </div>
    </div>
</footer>

{{--
    The theme's sidebar toggle is written against jQuery, so jQuery has to load
    for the hamburger to work. It is deferred and loaded after our bundle so it
    cannot shadow anything Bootstrap 5 needs.
--}}
<script src="{{ asset('theme/js/jquery-3.2.1.min.js') }}"></script>
<script src="{{ asset('theme/plugins/sidemenu/sidemenu.js') }}"></script>
<script>
    (function() {
        try {
            var toggle = document.querySelector('.app-sidebar__toggle');
            if (toggle) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    document.body.classList.toggle('sidenav-toggled');
                });
            }
            var overlay = document.querySelector('.app-sidebar__overlay');
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    document.body.classList.remove('sidenav-toggled');
                });
            }
        } catch(err) {
            console.error('Error binding sidebar toggle:', err);
        }
    })();
</script>

@stack('scripts')
</body>
</html>
