FROM node:24-bookworm-slim AS node
FROM composer:2 AS composer
FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip ca-certificates libpq-dev libzip-dev libicu-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 gd pdo_pgsql bcmath intl zip pcntl mbstring \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ARG DEV_UID=1000
ARG DEV_GID=1000
RUN groupadd --gid ${DEV_GID} developer \
    && useradd --uid ${DEV_UID} --gid ${DEV_GID} --create-home --shell /bin/bash developer
WORKDIR /workspace
USER developer
CMD ["sleep", "infinity"]
