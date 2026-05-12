#!/bin/sh

set -eu

NGINX_DEFAULT_CONF="/etc/nginx/sites-enabled/default"
NGINX_FALLBACK_CONF="/etc/nginx/sites-available/default"
NGINX_CLIENT_MAX_BODY_SIZE="${NGINX_CLIENT_MAX_BODY_SIZE:-110M}"
LARAVEL_PUBLIC_ROOT="/home/site/wwwroot/public"

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
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF
fi

cd /home/site/wwwroot
php artisan migrate --force --no-interaction
php artisan optimize

service nginx reload || service nginx restart || true
