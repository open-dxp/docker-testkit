#!/usr/bin/env bash
set -e

source /var/www/html/etc/runtime-env.sh

while :
do
    case "$1" in
        tests)
            echo ""
            echo "*************************************************************************"
            echo "run tests"
            echo "*************************************************************************"
            cd /var/www/html/public
            /usr/bin/php -d memory_limit=-1 vendor/bin/codecept run -c lib/test-bundle --env local "${@:2}"
            cd ~-
            exit
            ;;
        tests-xdebug)
            echo ""
            echo "*************************************************************************"
            echo "run tests with xdebug"
            echo "*************************************************************************"
            echo ""
            cd /var/www/html/public
            PHP_IDE_CONFIG="serverName=opendxp.test" /usr/bin/php -dxdebug.remote_enable=1 -dxdebug.remote_autostart=On -dxdebug.idekey=PHPSTORM -dxdebug.remote_host=$2 -d memory_limit=-1 -d memory_limit=-1 vendor/bin/codecept run -c lib/test-bundle --env local "${@:3}"
            cd ~-
            exit
            ;;
        phpstan)
            echo ""
            echo "*************************************************************************"
            echo "run phpstan"
            echo "*************************************************************************"
            echo ""
            cd /var/www/html/public
            bin/console cache:warmup --env=test --no-optional-warmers
            php -d memory_limit=-1 vendor/bin/phpstan analyse -c lib/test-bundle/phpstan.neon -a lib/test-bundle/tests/_phpstan-bootstrap.php lib/test-bundle/src "${@:2}"
            cd ~-
            exit
            ;;
        ecs)
            echo ""
            echo "*************************************************************************"
            echo "run ecs"
            echo "*************************************************************************"
            echo ""
            cd /var/www/html/public
            ln -sfn /var/www/html/public/vendor lib/test-bundle/vendor
            php -d memory_limit=-1 vendor/bin/ecs check lib/test-bundle/src --config lib/test-bundle/ecs.php "${@:2}"
            rm -rf lib/test-bundle/vendor
            cd ~-
            exit
            ;;
        *)
            echo "Usage: $(basename $0) tests|tests-enable-xdebug|tests-xdebug|phpstan|ecs [args]"
            exit
            ;;
    esac
done
