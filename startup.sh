#!/bin/sh

set -eu

NGINX_DEFAULT_CONF="/etc/nginx/sites-enabled/default"
NGINX_FALLBACK_CONF="/etc/nginx/sites-available/default"
NGINX_CLIENT_MAX_BODY_SIZE="${NGINX_CLIENT_MAX_BODY_SIZE:-110M}"
LARAVEL_PUBLIC_ROOT="/home/site/wwwroot/public"
LEGACY_PRIVATE_STORAGE_ROOT="/home/site/wwwroot/storage/app/private"
LEGACY_PUBLIC_STORAGE_ROOT="/home/site/wwwroot/storage/app/public"

if [ ! -f "$NGINX_DEFAULT_CONF" ] && [ -f "$NGINX_FALLBACK_CONF" ]; then
    NGINX_DEFAULT_CONF="$NGINX_FALLBACK_CONF"
fi

# Raiz efetiva de cada disco, aplicando as mesmas regras da aplicação: só
# caminho absoluto vale, e a raiz pública precisa ser disjunta da privada.
# O nginx serve `/storage/` diretamente desta raiz, então uma raiz pública que
# se sobreponha à privada publicaria todos os documentos privados.
resolve_storage_root() {
    storage_configured="${1%/}"
    storage_default="$2"

    case "$storage_configured" in
        /*) printf '%s\n' "$storage_configured" ;;
        *) printf '%s\n' "$storage_default" ;;
    esac
}

storage_roots_overlap() {
    case "$1/" in
        "$2"/*) return 0 ;;
    esac

    case "$2/" in
        "$1"/*) return 0 ;;
    esac

    return 1
}

EFFECTIVE_PRIVATE_STORAGE_ROOT="$(resolve_storage_root "${PRIVATE_STORAGE_ROOT:-}" "$LEGACY_PRIVATE_STORAGE_ROOT")"
EFFECTIVE_PUBLIC_STORAGE_ROOT="$(resolve_storage_root "${PUBLIC_STORAGE_ROOT:-}" "$LEGACY_PUBLIC_STORAGE_ROOT")"

# Uma raiz vazia viraria `alias /;` no nginx, servindo o container inteiro em
# /storage/. resolve_storage_root nunca devolve vazio, mas o custo de errar aqui
# é alto demais para depender só disso.
for storage_variable in EFFECTIVE_PRIVATE_STORAGE_ROOT EFFECTIVE_PUBLIC_STORAGE_ROOT; do
    eval "storage_value=\$$storage_variable"

    case "$storage_value" in
        /?*) ;;
        *)
            echo "ERRO: $storage_variable='$storage_value' não é utilizável." >&2
            exit 1
            ;;
    esac
done

if storage_roots_overlap "$EFFECTIVE_PUBLIC_STORAGE_ROOT" "$EFFECTIVE_PRIVATE_STORAGE_ROOT"; then
    echo "ERRO: PUBLIC_STORAGE_ROOT ('$EFFECTIVE_PUBLIC_STORAGE_ROOT') se sobrepõe a PRIVATE_STORAGE_ROOT ('$EFFECTIVE_PRIVATE_STORAGE_ROOT') — os documentos privados seriam servidos publicamente. Usando '$LEGACY_PUBLIC_STORAGE_ROOT'; corrija as App Settings." >&2
    EFFECTIVE_PUBLIC_STORAGE_ROOT="$LEGACY_PUBLIC_STORAGE_ROOT"
fi

if [ -f "$NGINX_DEFAULT_CONF" ]; then
    cat > "$NGINX_DEFAULT_CONF" << EOF
server {
    listen 8080;
    listen [::]:8080;
    root ${LARAVEL_PUBLIC_ROOT};
    index index.php index.html;
    server_name _;
    client_max_body_size ${NGINX_CLIENT_MAX_BODY_SIZE};

    # TLS termina no front-end do App Service, então o container sempre recebe
    # HTTP em 8080: o protocolo original chega em X-Forwarded-Proto. Comparar com
    # "http" (em vez de "!= https") garante que sondas internas e requisições sem
    # o cabeçalho não entrem em loop de redirecionamento. Cobre também os arquivos
    # estáticos, que são servidos pelo nginx sem passar pelo PHP.
    if (\$http_x_forwarded_proto = "http") {
        return 301 https://\$host\$request_uri;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Os arquivos públicos são servidos direto da raiz persistente, nunca
    # através do symlink public/storage: se ele apontar para o lugar errado, os
    # documentos privados vazariam como arquivo estático. O prefixo "^~" impede
    # que a location regex de PHP abaixo capture um .php enviado como upload e
    # o execute — com o symlink, um arquivo em qualquer raiz seria executável.
    location ^~ /storage/ {
        alias ${EFFECTIVE_PUBLIC_STORAGE_ROOT}/;
        try_files \$uri =404;
    }

    location ~ \\.php\$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_read_timeout 420s;
        fastcgi_send_timeout 420s;
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF

    # A imagem do App Service sobe o nginx com a config padrão (docroot
    # /home/site/wwwroot) antes deste script rodar, então a config acima só
    # passa a valer no reload. Recarregar aqui, e não no fim do script, encurta
    # a janela em que todo caminho — inclusive /up — responde 404: sem isso ela
    # se estende por todo o migrate e o optimize, o suficiente para a sonda de
    # integridade do App Service reprovar a instância a cada deploy.
    service nginx reload || service nginx restart || true
fi

# Arquivos enviados pelos usuários vivem fora de /home/site/wwwroot, que é
# substituído a cada deploy. Garante a raiz persistente e traz, uma única vez, o
# que ainda estiver no local antigo (sem sobrescrever nada que já exista no
# destino). Só caminho absoluto é aceito: um valor relativo criaria o diretório
# no CWD do processo e espalharia os arquivos. A aplicação aplica a mesma regra.
provision_storage_root() {
    storage_variable="$1"
    storage_configured="$2"
    storage_target="$3"
    storage_legacy="$4"

    case "$storage_configured" in
        "")
            echo "AVISO: $storage_variable não definido — os arquivos ficarão em $storage_legacy e serão perdidos no próximo deploy." >&2
            ;;
        /*) ;;
        *)
            echo "AVISO: $storage_variable='$storage_configured' não é um caminho absoluto e será ignorado. Use, por exemplo, /home/data/private." >&2
            ;;
    esac

    mkdir -p "$storage_target"

    if [ -d "$storage_legacy" ] && [ "$storage_target" != "$storage_legacy" ]; then
        cp -a -n "$storage_legacy/." "$storage_target/" 2>/dev/null || true
    fi

    chown -R www-data:www-data "$storage_target" 2>/dev/null || true
}

provision_storage_root "PRIVATE_STORAGE_ROOT" "${PRIVATE_STORAGE_ROOT:-}" "$EFFECTIVE_PRIVATE_STORAGE_ROOT" "$LEGACY_PRIVATE_STORAGE_ROOT"
provision_storage_root "PUBLIC_STORAGE_ROOT" "${PUBLIC_STORAGE_ROOT:-}" "$EFFECTIVE_PUBLIC_STORAGE_ROOT" "$LEGACY_PUBLIC_STORAGE_ROOT"

cd /home/site/wwwroot
php artisan migrate --force --isolated --no-interaction
php artisan optimize

# O symlink public/storage fica dentro do wwwroot e é destruído a cada deploy.
# Recria apontando para a raiz pública configurada, senão as imagens públicas
# (logos de bancos, mídias de medições) respondem 404 depois de cada deploy.
php artisan storage:link --force --no-interaction || true

# O container Linux do App Service não tem cron nem supervisor, e nada reinicia
# um processo em segundo plano que termine. Sem os laços abaixo o `queue:work`
# encerra por `--max-time` após uma hora e o agendador nunca roda — as duas
# coisas param em silêncio até o próximo deploy.
while true; do php artisan queue:work --sleep=3 --tries=1 --timeout=600 --max-time=3600; sleep 5; done &
while true; do php artisan schedule:work; sleep 5; done &
