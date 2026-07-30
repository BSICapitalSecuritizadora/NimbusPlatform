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

# Arquivos enviados pelos usuários vivem fora de /home/site/wwwroot, que é
# substituído a cada deploy. Garante a raiz persistente e traz, uma única vez, o
# que ainda estiver no local antigo (sem sobrescrever nada que já exista no
# destino). Só caminho absoluto é aceito: um valor relativo criaria o diretório
# no CWD do processo e espalharia os arquivos. A aplicação aplica a mesma regra.
provision_storage_root() {
    storage_variable="$1"
    storage_configured="$2"
    storage_legacy="$3"
    storage_target=""

    case "$storage_configured" in
        "")
            echo "AVISO: $storage_variable não definido — os arquivos ficarão em $storage_legacy e serão perdidos no próximo deploy." >&2
            ;;
        /*)
            storage_target="${storage_configured%/}"
            ;;
        *)
            echo "AVISO: $storage_variable='$storage_configured' não é um caminho absoluto e será ignorado. Use, por exemplo, /home/data/private." >&2
            ;;
    esac

    [ -n "$storage_target" ] || return 0

    mkdir -p "$storage_target"

    if [ -d "$storage_legacy" ] && [ "$storage_target" != "$storage_legacy" ]; then
        cp -a -n "$storage_legacy/." "$storage_target/" 2>/dev/null || true
    fi

    chown -R www-data:www-data "$storage_target" 2>/dev/null || true
}

provision_storage_root "PRIVATE_STORAGE_ROOT" "${PRIVATE_STORAGE_ROOT:-}" "$LEGACY_PRIVATE_STORAGE_ROOT"
provision_storage_root "PUBLIC_STORAGE_ROOT" "${PUBLIC_STORAGE_ROOT:-}" "$LEGACY_PUBLIC_STORAGE_ROOT"

cd /home/site/wwwroot
php artisan migrate --force --no-interaction
php artisan optimize

# O symlink public/storage fica dentro do wwwroot e é destruído a cada deploy.
# Recria apontando para a raiz pública configurada, senão as imagens públicas
# (logos de bancos, mídias de medições) respondem 404 depois de cada deploy.
php artisan storage:link --force --no-interaction || true

# O container Linux do App Service não tem cron nem supervisor, e nada reinicia
# um processo em segundo plano que termine. Sem os laços abaixo o `queue:work`
# encerra por `--max-time` após uma hora e o agendador nunca roda — as duas
# coisas param em silêncio até o próximo deploy.
while true; do php artisan queue:work --sleep=3 --tries=1 --timeout=420 --max-time=3600; sleep 5; done &
while true; do php artisan schedule:work; sleep 5; done &

service nginx reload || service nginx restart || true
