#!/bin/sh

set -eu

NGINX_DEFAULT_CONF="/etc/nginx/sites-enabled/default"
NGINX_FALLBACK_CONF="/etc/nginx/sites-available/default"
NGINX_CLIENT_MAX_BODY_SIZE="${NGINX_CLIENT_MAX_BODY_SIZE:-110M}"
LARAVEL_PUBLIC_ROOT="/home/site/wwwroot/public"
LEGACY_PRIVATE_STORAGE_ROOT="/home/site/wwwroot/storage/app/private"

if [ ! -f "$NGINX_DEFAULT_CONF" ] && [ -f "$NGINX_FALLBACK_CONF" ]; then
    NGINX_DEFAULT_CONF="$NGINX_FALLBACK_CONF"
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

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
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
fi

# Documentos privados vivem fora de /home/site/wwwroot, que é substituído a cada
# deploy. Garante a raiz persistente e traz, uma única vez, o que ainda estiver
# no local antigo (sem sobrescrever nada que já exista no destino).
# Só caminho absoluto é aceito: um valor relativo criaria o diretório no CWD do
# processo e espalharia os documentos. A aplicação aplica a mesma regra.
PRIVATE_STORAGE_TARGET=""

case "${PRIVATE_STORAGE_ROOT:-}" in
    "")
        echo "AVISO: PRIVATE_STORAGE_ROOT não definido — documentos privados ficarão em $LEGACY_PRIVATE_STORAGE_ROOT e serão perdidos no próximo deploy." >&2
        ;;
    /*)
        PRIVATE_STORAGE_TARGET="${PRIVATE_STORAGE_ROOT%/}"
        ;;
    *)
        echo "AVISO: PRIVATE_STORAGE_ROOT='$PRIVATE_STORAGE_ROOT' não é um caminho absoluto e será ignorado. Use, por exemplo, /home/data/private." >&2
        ;;
esac

if [ -n "$PRIVATE_STORAGE_TARGET" ]; then
    mkdir -p "$PRIVATE_STORAGE_TARGET"

    if [ -d "$LEGACY_PRIVATE_STORAGE_ROOT" ] && [ "$PRIVATE_STORAGE_TARGET" != "$LEGACY_PRIVATE_STORAGE_ROOT" ]; then
        cp -a -n "$LEGACY_PRIVATE_STORAGE_ROOT/." "$PRIVATE_STORAGE_TARGET/" 2>/dev/null || true
    fi

    chown -R www-data:www-data "$PRIVATE_STORAGE_TARGET" 2>/dev/null || true
fi

cd /home/site/wwwroot
php artisan migrate --force --no-interaction
php artisan optimize

# O container Linux do App Service não tem cron nem supervisor, e nada reinicia
# um processo em segundo plano que termine. Sem os laços abaixo o `queue:work`
# encerra por `--max-time` após uma hora e o agendador nunca roda — as duas
# coisas param em silêncio até o próximo deploy.
while true; do php artisan queue:work --sleep=3 --tries=1 --timeout=420 --max-time=3600; sleep 5; done &
while true; do php artisan schedule:work; sleep 5; done &

service nginx reload || service nginx restart || true
