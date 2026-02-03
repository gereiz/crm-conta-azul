FROM php:8.2-apache

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    default-mysql-client \
    cron \
    tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Ativar mod_rewrite do Apache
RUN a2enmod rewrite

# Instalar Node.js e NPM (usando Node 20 LTS)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir diretório de trabalho
WORKDIR /var/www/html

# Configurar Apache para usar a pasta public como root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
 
# Configurar timezone do container
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Copiar arquivos do projeto
COPY . .
 
# Script de execução do scheduler com logging
COPY docker/schedule-run.sh /usr/local/bin/schedule-run.sh
RUN chmod +x /usr/local/bin/schedule-run.sh

# Instalar dependências do PHP
# Usamos --no-scripts para evitar erros de boot sem variáveis de ambiente/banco
ENV COMPOSER_MEMORY_LIMIT=-1
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts

# Instalar dependências do Node e buildar assets
RUN npm install && npm run build

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Configurar Cron para o Scheduler do Laravel (com logging em storage/logs/laravel.log)
RUN echo "* * * * * www-data cd /var/www/html && /usr/local/bin/schedule-run.sh" > /etc/cron.d/laravel-scheduler \
    && chmod 0644 /etc/cron.d/laravel-scheduler \
    && crontab /etc/cron.d/laravel-scheduler

# Criar script de inicialização
# Adicionamos package:discover e storage:link
RUN echo '#!/bin/bash\n\
\n\
# Se NÃO existe .env (primeira instalação ou ambiente limpo), cria a partir do example\n\
if [ ! -f .env ]; then\n\
    cp .env.example .env\n\
    # Força driver de sessão para file para evitar erro de banco na instalação\n\
    sed -i "s/SESSION_DRIVER=database/SESSION_DRIVER=file/g" .env\n\
    \n\
    # Desabilitada geracao automatica de chave em producao para evitar conflitos\n\
    # O usuario deve configurar APP_KEY nas variaveis de ambiente\n\
    # if [ -z "$APP_KEY" ]; then\n\
    #    if grep -q "^APP_KEY=$" .env; then\n\
    #         php artisan key:generate --force\n\
    #    fi\n\
    # fi\n\
fi\n\
\n\
# IMPORTANTE: Em produção, o .env deve ser persistido via volume ou injetado.\n\
# Se o .env existir mas não tiver os dados do banco configurados, rodamos o artisan migrate se necessário\n\
# Mas cuidado: migrate em produção automática pode ser perigoso.\n\
\n\
# Garante que o arquivo storage/installed não bloqueie se quisermos reconfigurar\n\
# rm -f storage/installed\n\
\n\
php artisan package:discover --ansi\n\
php artisan storage:link\n\
php artisan config:cache\n\
php artisan route:cache\n\
php artisan view:cache\n\
\n\
# IMPORTANTE: A limpeza de cache deve ser feita ANTES de tentar rodar qualquer coisa que dependa da APP_KEY criptografada\n\
# Se o cache de config foi gerado com uma chave antiga, a aplicação não consegue descriptografar os dados do banco.\n\
php artisan config:clear\n\
\n\
# Ajusta permissões recursivamente para garantir escrita\n\
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database\n\
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database\n\
\n\
# Garante permissão de escrita no .env se ele existir\n\
if [ -f .env ]; then\n\
    chown www-data:www-data .env\n\
    chmod 664 .env\n\
fi\n\
\n\
# Iniciar o cron\n\
cron\n\
\n\
apache2-foreground' > /usr/local/bin/start-container \
    && chmod +x /usr/local/bin/start-container

# Expor porta 80
EXPOSE 80

# Comando de inicialização
CMD ["/usr/local/bin/start-container"]
