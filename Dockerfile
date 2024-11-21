FROM ghcr.io/krkabol/jacq-symfony-base:main
LABEL org.opencontainers.image.source=https://github.com/acq-system/symfony
LABEL org.opencontainers.image.description="JACQ herbarium service Symfony"
ARG GIT_TAG
ENV GIT_TAG=$GIT_TAG

# devoted for Kubernetes, where the app has to be copied into final destination (/app) after the container starts
COPY  --chown=www:www htdocs /app
RUN chmod -R 777 /app/var

## use in case you want to run in docker on local machine
#COPY htdocs /var/www/html