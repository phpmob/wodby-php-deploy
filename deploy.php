<?php

namespace Deployer;

require_once 'recipe/common.php';
require_once 'recipe/cloudflare.php';
require_once 'recipe/cachetool.php';

set('writable_chmod_mode', '0777');
set('cachetool', $_ENV['DEPLOY_CACHE_TOOL_CGI_URL'] ?? '127.0.0.1:9000');
set('cloudflare', [
    'email' => $_ENV['DEPLOY_CF_EMAIL'] ?? null,
    'api_key' => $_ENV['DEPLOY_CF_API_KEY'] ?? null,
    'domain' => $_ENV['DEPLOY_CF_DOMAIN'] ?? null,
]);

set('shared_dirs', explode(',', $_ENV['DEPLOY_SHARED_DIRS'] ?? 'var/log,var/sessions,var/media'));
set('shared_files', explode(',', $_ENV['DEPLOY_SHARED_FILES'] ?? '.env'));
set('writable_dirs', explode(',', $_ENV['DEPLOY_WRITABLE_DIRS'] ?? 'var,{{deploy_path}}/shared/var/log,{{deploy_path}}/shared/var/sessions,{{deploy_path}}/shared/var/media'));

set('bin/console', function () {
    return parse('{{bin/php}} {{release_path}}/bin/console --no-interaction');
});

desc('Migrate database');
task('database:migrate', function () {
    run('{{bin/console}} doctrine:migrations:migrate --allow-no-migration');
});

desc('Clear cache');
task('deploy:cache:clear', function () {
    run('{{bin/console}} cache:clear --no-warmup');
});

desc('Warm up cache');
task('deploy:cache:warmup', function () {
    run('{{bin/console}} cache:warmup');
});

desc('Deploy project');
task('deploy', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:vendors',
    'deploy:symlink',
    'deploy:unlock',
    'database:migrate',
    'cleanup',
]);

after('deploy', 'success');

desc('Setup database');
task('database:setup', function () {
    // default
    run('{{bin/console}} doctrine:database:drop --connection=default --force --if-exists');
    run('{{bin/console}} doctrine:database:create --connection=default');
    run('{{bin/console}} doctrine:schema:update --dump-sql --force');
    // media
    run('{{bin/console}} doctrine:database:drop --connection=media --force --if-exists');
    run('{{bin/console}} doctrine:database:create --connection=media');
    run('{{bin/console}} doctrine:phpcr:init:dbal --force');
    run('{{bin/console}} doctrine:phpcr:repository:init');
    // fixture
    run('{{bin/console}} sylius:fixtures:load prod');
});

task('cmd:setup', function () {
    run('{{bin/console}} ' . $_ENV['DEPLOY_CMD_SETUP']);
});

task('shell:setup', function () {
    run('cd {{release_path}} && ' . $_ENV['DEPLOY_SHELL_SETUP']);
});

localhost('prod')
    ->set('writable_mode', 'chmod')
    ->set('deploy_path', $_ENV['DEPLOY_APP_ROOT'] ?? '/var/www/html')
    ->set('repository', $_ENV['DEPLOY_REPOSITORY'])
    ->set('branch', $_ENV['DEPLOY_BRANCH'] ?? 'build')
;

desc('Clearing APCu system cache');
task('cachetool:clear:apcu', function () {
    $releasePath = get('release_path');
    $options = get('cachetool');

    if (strlen($options)) {
        $options = "--fcgi={$options}";
    }

    cd($releasePath);
    $hasCachetool = run("if [ -e $releasePath/{{bin/cachetool}} ]; then echo 'true'; fi");

    if ('true' !== $hasCachetool) {
        run("curl -sO https://gordalina.github.io/cachetool/downloads/{{bin/cachetool}}");
    }

    run("{{bin/php}} {{bin/cachetool}} apcu:cache:clear system {$options}");
});

task('geoip:update', function () {
    $sharedPath = "{{deploy_path}}/shared";

    run("mkdir /tmp/geoip && cd /tmp/geoip && curl http://geolite.maxmind.com/download/geoip/database/GeoLite2-City.tar.gz | tar -xz");

    run("rm -rf $sharedPath/geoip && mkdir -p $sharedPath/geoip");
    run('cd /tmp/geoip && cd $(ls -d */|head -n 1) && mv GeoLite2-City.mmdb '.$sharedPath.'/geoip');
    //run("cd $sharedPath/geoip && ls -al");
});

task('test', function() {
var_dump($_ENV);
});

set('bin/yarn', function () {
    return run('which yarn');
});

task('yarn:build', function() {
    run('cd {{release_path}} && {{bin/yarn}} install && {{bin/yarn}} run build');
});

if (($_ENV['DEPLOY_CF_API_KEY'] ?? 0)) {
    after('deploy:cache:clear', 'deploy:cloudflare');
}

if (1 === intval($_ENV['DEPLOY_OPcode_ON'] ?? 0)) {
    after('deploy:cache:clear', 'cachetool:clear:opcache');
}

if (1 === intval($_ENV['DEPLOY_APC_ON'] ?? 0)) {
    after('deploy:cache:clear', 'cachetool:clear:apc');
}

if (1 === intval($_ENV['DEPLOY_APCu_ON'] ?? 0)) {
    after('deploy:cache:clear', 'cachetool:clear:apcu');
}

if (1 === intval($_ENV['DEPLOY_DATA_SETUP'] ?? 0)) {
    after('deploy:vendors', 'database:setup');
    after('deploy:vendors', 'deploy:writable');
}

if (($_ENV['DEPLOY_CMD_SETUP'] ?? 0)) {
    after('deploy:vendors', 'cmd:setup');
    after('deploy:vendors', 'deploy:writable');
}

if (($_ENV['DEPLOY_SHELL_SETUP'] ?? 0)) {
    after('deploy:vendors', 'shell:setup');
}

if (1 === intval($_ENV['DEPLOY_YARN_BUILD'] ?? 0)) {
    after('deploy:vendors', 'yarn:build');
}

if (1 === intval($_ENV['DEPLOY_UPDATE_GEOIP'] ?? 0)) {
    after('deploy', 'geoip:update');
}

task('env_file:update', function() {
    run('cat {{release_path}}/' . $_ENV['DEPLOY_PROD_ENV_FILE'] . ' > {{deploy_path}}/shared/.env');
});

if (($_ENV['DEPLOY_PROD_ENV_FILE'] ?? 0)) {
    after('deploy:shared', 'env_file:update');
}
