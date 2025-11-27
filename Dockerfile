# PHP CLI image
FROM php:8.1-cli

# Zarur PHP ext va utilitilarni o'rnatish
RUN apt-get update && apt-get install -y \
    zip unzip \
    && docker-php-ext-install pdo_mysql mysqli \
    && apt-get clean

# Ish papkasi
WORKDIR /app

# Loyihani konteynerga nusxalash
COPY . /app

# PHP skript ishga tushadi
CMD ["php", "bot.php"]
