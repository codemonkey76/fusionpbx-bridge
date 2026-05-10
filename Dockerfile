FROM php:8.4-cli-alpine

# Install PostgreSQL client extension and curl (for healthcheck)
RUN apk add --no-cache postgresql-dev curl \
	&& docker-php-ext-install pdo pdo_pgsql

WORKDIR /app

COPY . .

EXPOSE 8880

# Use PHP's built-in server - swap for php-fpm + nginx if you need more throughput
CMD ["php", "-S", "0.0.0.0:8880", "-t", "/app/public"]
