## Installation by docker-compose (Only for development)
- install docker and docker-compose
- create .env file and set docker's data. Set ```APP_ENV```, ```APP_URL``` etc.
This is part of ```env``` file. These properties you have to set up properly:
```
APP_URL=http://localhost:5555

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=external_payment
DB_USERNAME=external_payment
DB_PASSWORD=external_payment

#for getting DOCKER_UID and DOCKER_GID use id command
DOCKER_UID={YOUR_SYSTEM_UID}
DOCKER_GID={YOUR_SYSTEM_GID}
```

- run composer: 
```bash
docker-compose run --rm --no-deps php composer install
```
- create application's key and set up configuration:
```bash
docker-compose run --rm --no-deps php php artisan key:generate
```
- You can `UP` php container and dependent containers. Waiting for loading data and then press ```ctrl+c```
```bash
docker-compose up php
```
- run migrations
```bash
docker-compose run --rm php php artisan migrate
```
- set permissions:
```bash
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```
- Run and Go to ```http://localhost:5555/register```
```bash
docker-compose up
```
