#!/bin/sh
set -e

# deploy
if [ "${DEPLOY_REPOSITORY}" == "" ]; then
  echo 'WARN: There are no `DEPLOY_REPOSITORY` env defined.';

  if [ "${DEPLOY_PHP_INFO}" == "1" ]; then
    mkdir -p "${APP_ROOT}/current/public"
    echo "<?php phpinfo();" > "${APP_ROOT}/current/public/index.php"
  fi
else
  # if not symlink remove it!
  if [ ! -L "${APP_ROOT}/current" ] && [ -d "${APP_ROOT}/current" ]; then
    rm -rf "${APP_ROOT}/current"
  fi

  if [ "${DEPLOY_DEPLOYER_TASK}" == "" ]; then
    DEPLOY_DEPLOYER_TASK=deploy
  fi

  /var/vendor/bin/dep -vvv --file=/deploy.php $DEPLOY_DEPLOYER_TASK
  # chown -R www-data:www-data $APP_ROOT
fi

. /docker-entrypoint.sh
