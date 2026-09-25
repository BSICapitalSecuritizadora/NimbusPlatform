#!/bin/bash
echo "Starting Laravel scheduler..."
cd /home/site/wwwroot

# Mesmo tratamento do startup.sh: locks de sobreposição que sobreviveram a um
# reinício são limpos antes de subir, e o agendador roda com teto de memória
# explícito. O `-d` não chega aos comandos disparados pelo agendador; a
# automação do Quadro de Vendas eleva o próprio limite
# (SALES_BOARD_AUTOMATION_MEMORY_LIMIT).
php artisan schedule:clear-cache --no-interaction || true
php -d memory_limit=512M artisan schedule:work
echo "Scheduler stopped. Restarting..."
