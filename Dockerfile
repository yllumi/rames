FROM php:8.3.22-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories \
  && apk update --no-cache

# system deps: git (clone repo site), docker CLI + compose plugin (orkestrasi),
# curl + curl-dev (extension curl utk Guzzle curl handler ke Docker Engine API),
# util-linux (binary `script` — PTY untuk terminal interaktif `docker exec -it`),
# oniguruma-dev (dependensi build mbstring — truncasi teks multibyte di UI),
# unzip (ekstraksi arsip .zip file manager DI HOST dashboard — container app
#   belum tentu punya unzip),
# restic (backup volume ke S3 — dijalankan di container helper, PLAN_VOLUME_BACKUP.md §3)
RUN apk add --no-cache git openssh-client openssh-keygen docker-cli docker-cli-compose curl curl-dev certbot certbot-dns-cloudflare util-linux oniguruma-dev unzip restic

# PHP extensions
RUN docker-php-ext-install -j$(nproc) pdo pdo_mysql pcntl curl mbstring \
  && docker-php-ext-enable opcache pcntl

# Driver SQLite WAJIB: seluruh data domain dashboard disimpan di `rames.sqlite`
# (SPECS §8i). Pada base image resmi `pdo_sqlite`/`sqlite3` sudah ter-kompilasi
# di dalam PHP (tidak ada `.so` terpisah, sehingga `docker-php-ext-enable`
# tidak berlaku) — dinyatakan eksplisit di sini supaya build GAGAL cepat bila
# base image kelak tidak lagi menyertakannya.
RUN php -m | grep -qix 'pdo_sqlite' || (echo "pdo_sqlite tidak tersedia di image PHP" >&2; exit 1)

# Batas unggah PHP efektif untuk file manager container. `upload_max_filesize`
# = 64M adalah batas NYATA per berkas (sama dengan batas klien). `post_max_size`
# = 68M memberi HEADROOM untuk overhead multipart (boundary/field), sehingga
# berkas ~64 MiB tidak ditolak server padahal lolos cek klien. `memory_limit`
# sengaja TIDAK dinaikkan: transfer byte lewat docker cp + berkas temp.
RUN printf 'upload_max_filesize=64M\npost_max_size=68M\n' > "$PHP_INI_DIR/conf.d/zz-files-upload.ini"

# composer
RUN php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" \
  && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
  && rm /tmp/composer-setup.php \
  && rm -rf /var/cache/apk/*

RUN mkdir -p /app
WORKDIR /app