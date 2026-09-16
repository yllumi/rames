FROM php:8.3.22-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories \
  && apk update --no-cache

# system deps: git (clone repo site), docker CLI + compose plugin (orkestrasi),
# curl + curl-dev (extension curl utk Guzzle curl handler ke Docker Engine API),
# util-linux (binary `script` — PTY untuk terminal interaktif `docker exec -it`),
# oniguruma-dev (dependensi build mbstring — truncasi teks multibyte di UI)
RUN apk add --no-cache git openssh-client openssh-keygen docker-cli docker-cli-compose curl curl-dev certbot certbot-dns-cloudflare util-linux oniguruma-dev

# PHP extensions
RUN docker-php-ext-install -j$(nproc) pdo pdo_mysql pcntl curl mbstring \
  && docker-php-ext-enable opcache pcntl

# composer
RUN php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" \
  && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
  && rm /tmp/composer-setup.php \
  && rm -rf /var/cache/apk/*

RUN mkdir -p /app
WORKDIR /app