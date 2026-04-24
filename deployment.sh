#!/bin/bash

# Falhar se algum comando der erro
set -e

echo "--- Iniciando Deploy Laravel ---"

# 1. Instalar dependências do Composer (apenas produção)
composer install --no-dev --optimize-autoloader

# 2. Instalar dependências do NPM e gerar Assets
npm install
npm run build

# 3. Cache de configurações para performance
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Rodar Migrações do Banco de Dados (Crucial para o Azure MySQL)
# O flag --force é obrigatório em produção
php artisan migrate --force

echo "--- Deploy Finalizado com Sucesso ---"
