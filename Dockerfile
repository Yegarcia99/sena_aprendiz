FROM php:8.2-apache

# Extensiones necesarias
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Habilitar mod_rewrite para URLs limpias
RUN a2enmod rewrite

# Copiar proyecto al directorio web
WORKDIR /var/www/html
COPY . .

# Configurar Apache para el proyecto
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/project.conf \
 && a2enconf project

# Exponer puerto dinamico de Railway (usa PORT env var)
# Apache escucha en 80 por defecto; Railway lo mapea
EXPOSE 80

# Script de inicio que ajusta el puerto si Railway lo requiere
CMD bash -c "\
    if [ ! -z \"\$PORT\" ] && [ \"\$PORT\" != \"80\" ]; then \
        sed -i \"s/Listen 80/Listen \$PORT/g\" /etc/apache2/ports.conf; \
        sed -i \"s/:80>/:$PORT>/g\" /etc/apache2/sites-enabled/000-default.conf; \
    fi && \
    apache2-foreground"
