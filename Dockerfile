ARG FROM_TAG=7.2-4.7.2

FROM wodby/php:${FROM_TAG}

USER root

ENV COMPOSER_ALLOW_SUPERUSER 1
COPY composer.json /var
RUN cd /var && composer install --prefer-dist --no-progress --no-suggest --classmap-authoritative

# node build
RUN apk add --update nodejs-current
RUN apk add --update npm yarn
RUN npm rebuild node-sass

USER wodby

COPY ./deploy.php /
COPY ./entrypoint.sh /

ENTRYPOINT ["/entrypoint.sh"]
CMD ["sudo", "-E", "LD_PRELOAD=/usr/lib/preloadable_libiconv.so", "php-fpm"]
