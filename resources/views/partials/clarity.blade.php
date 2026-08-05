@php
    $clarityProjectId = (string) config('services.clarity.id', '');
    $clarityExcludedRoutes = (array) config('services.clarity.excluded_routes', []);

    // `routeIs()` sem padrões devolve false, então uma lista vazia carrega o
    // script normalmente — e uma rota sem nome nunca é considerada excluída.
    $shouldLoadClarity = $clarityProjectId !== ''
        && ! request()->routeIs(...$clarityExcludedRoutes);
@endphp

@if($shouldLoadClarity)
    <script type="text/javascript" nonce="{{ $cspNonce ?? \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window, document, "clarity", "script", "{{ $clarityProjectId }}");
    </script>
@endif
