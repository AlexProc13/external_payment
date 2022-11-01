FROM php:8.1-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    locales \
    zip \
    jpegoptim optipng pngquant gifsicle \
    vim \
    unzip \
    git \
    curl \
    libzip-dev \
    libmagickwand-dev --no-install-recommends \
    supervisor

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*
RUN printf "\n" | pecl install imagick

# Install extensions
RUN docker-php-ext-install pdo_mysql zip exif pcntl
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install gd
RUN docker-php-ext-enable imagick

# Add for redis
RUN pecl install redis && docker-php-ext-enable redis

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install opcache

RUN apt-get update -y
RUN apt-get install -y libgmp-dev re2c libmhash-dev libmcrypt-dev file wget nano
RUN ln -s /usr/include/x86_64-linux-gnu/gmp.h /usr/local/include/
RUN docker-php-ext-configure gmp
RUN docker-php-ext-install gmp

ARG user
ARG uid
ARG main_user

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u $uid -d /home/$user $user
RUN mkdir -p /home/$user/.composer

USER $main_user
