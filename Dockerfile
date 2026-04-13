# syntax=docker/dockerfile:1.4
FROM --platform=linux/amd64 php:8.2-apache

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

# Script de inicialização do container
COPY docker/start-container.sh /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

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

ENV BRANDING_STORAGE_PATH=/var/www/persistent/branding
ENV LOGS_STORAGE_PATH=/var/www/persistent/logs
ENV ENV_STORAGE_FILE=/var/www/persistent/env/.env
VOLUME ["/var/www/persistent/branding", "/var/www/persistent/logs", "/var/www/persistent/env"]

# Expor porta 80
EXPOSE 80

# Comando de inicialização
CMD ["/usr/local/bin/start-container"]
