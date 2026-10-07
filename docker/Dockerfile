# Throwaway Linux environment for running the checks on a chosen PHP version.
# Build from a clean checkout so no host vendor/ sneaks in:
#   git archive HEAD | docker build --build-arg PHP_VERSION=8.2 -t scheduler-test:8.2 -f docker/Dockerfile -
#   docker run --rm scheduler-test:8.2
ARG PHP_VERSION=8.2

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl
COPY --from=composer /usr/bin/composer /usr/bin/composer

# not root: the mutex tests check permission errors, root would skip them
RUN useradd --create-home app && mkdir /app && chown app:app /app
WORKDIR /app
COPY --chown=app:app . .
USER app

CMD ["sh", "-c", "composer validate --strict && composer install --no-interaction --no-progress && composer check-platform-reqs && composer check"]
