#!/bin/sh

set -eu

NGINX_DEFAULT_CONF="/etc/nginx/sites-enabled/default"
NGINX_FALLBACK_CONF="/etc/nginx/sites-available/default"
NGINX_CLIENT_MAX_BODY_SIZE="${NGINX_CLIENT_MAX_BODY_SIZE:-64M}"

if [ ! -f "$NGINX_DEFAULT_CONF" ] && [ -f "$NGINX_FALLBACK_CONF" ]; then
    NGINX_DEFAULT_CONF="$NGINX_FALLBACK_CONF"
fi

if [ -f "$NGINX_DEFAULT_CONF" ]; then
    if grep -q "client_max_body_size" "$NGINX_DEFAULT_CONF"; then
        sed -i "s/client_max_body_size [^;]*;/client_max_body_size ${NGINX_CLIENT_MAX_BODY_SIZE};/" "$NGINX_DEFAULT_CONF"
    elif grep -q "server_name _;" "$NGINX_DEFAULT_CONF"; then
        sed -i "/server_name _;/a\\    client_max_body_size ${NGINX_CLIENT_MAX_BODY_SIZE};" "$NGINX_DEFAULT_CONF"
    else
        sed -i "/listen \\[::\\]:8080;/a\\    client_max_body_size ${NGINX_CLIENT_MAX_BODY_SIZE};" "$NGINX_DEFAULT_CONF"
    fi
fi

service nginx reload || service nginx restart || true
