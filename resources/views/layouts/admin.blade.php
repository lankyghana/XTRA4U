{{--
    Admin pages render their own full document through <x-admin-layout>, so this
    wrapper is deliberately a pass-through (previously the document was nested
    inside a second <html>/<body>).
--}}
@yield('content')
