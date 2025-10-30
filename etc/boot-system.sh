#!/usr/bin/env bash

set -e

(sudo apt-get update || true)
sudo apt-get install gawk -y

########################################
##### CONFIG DEFINITION ################
########################################

# ENV: $TEST_BUNDLE_NAME (via docker env)
# ENV: $TEST_BUNDLE_VENDOR_NAME (via docker env)

# ENV: $TEST_OPENDXP_VERSION (via docker env)
# ENV: $TEST_SYMFONY_VERSION (via docker env)
# ENV: $TEST_EXECUTE_COMPOSER (via docker env)

source /var/www/html/etc/runtime-env.sh
source $OPENDXP_CODECEPTION_FRAMEWORK/src/_etc/scripts/yaml_reader.sh

if [[ -f "public/composer.json" ]]; then
    rm public/composer.json
fi

cp composer.dist.json public/composer.json

chmod +x /usr/local/bin/bundle

########################################
##### composer.json adjustments ########
########################################

BUNDLE_LOCATION=$(pwd)/public/lib/test-bundle
SAVE_TEST_BUNDLE_VENDOR_NAME=$(printf '%s\n' "$TEST_BUNDLE_VENDOR_NAME" | sed -e 's/[]\/$*.^[]/\\&/g');

# copy require-dev node from bundle composer.json
REQUIRE_DEV_DATA=$(sed -nr '/.*(\brequire-dev\b).*\{/,/\}/p' "$BUNDLE_LOCATION/composer.json")
SAVE_REQUIRE_DEV_DATA=$(printf "%s\n" "$REQUIRE_DEV_DATA" | sed -e 's/[]\/${}\n"*.:^[]/\\&/g' | tr -d '\n' | sed -z 's/\(.*\}\),/\1/');

sed -i "s/__OPENDXP_VERSION__/$TEST_OPENDXP_VERSION/g" public/composer.json
sed -i "s/__SYMFONY_VERSION__/$TEST_SYMFONY_VERSION/g" public/composer.json
sed -i "s/__BUNDLE_VENDOR_NAME__/$SAVE_TEST_BUNDLE_VENDOR_NAME/g" public/composer.json
sed -i "s/__REQUIRE_DEV_DATA__/$SAVE_REQUIRE_DEV_DATA/g" public/composer.json

########################################
##### Add system config ################
########################################

# reset symfony / opendxp structure to default!
rsync -az --delete /var/www/html/etc/boot_structure/bin/ /var/www/html/public/bin/
rsync -az --delete /var/www/html/etc/boot_structure/config/ /var/www/html/public/config/
rsync -az --delete /var/www/html/etc/boot_structure/public/ /var/www/html/public/public/
rsync -az --delete /var/www/html/etc/boot_structure/src/ /var/www/html/public/src/
rsync -az --delete /var/www/html/etc/boot_structure/templates/ /var/www/html/public/templates/
rsync -az --delete /var/www/html/etc/boot_structure/translations/ /var/www/html/public/translations/
rsync -az --delete /var/www/html/etc/boot_structure/var/ /var/www/html/public/var/
rsync -az --delete /var/www/html/etc/boot_structure/.env /var/www/html/public/.env

# remove default bundle config while booting
if [ -f "$TEST_BUNDLE_TEST_DIR/_data/config/config.yaml" ] ; then
    rm "$TEST_BUNDLE_TEST_DIR/_data/config/config.yaml"
fi

########################################
##### Setup Test Bundle ################
########################################

bash $OPENDXP_CODECEPTION_FRAMEWORK/src/_etc/scripts/setup.sh

########################################
##### Execute composer if required #####
########################################

eval "$(parse_yaml $TEST_BUNDLE_TEST_DIR/_etc/config.yaml)"

ADDITIONAL_PACKAGES=''
NODE='additional_composer_packages'

for CURRENT_CONFIG_NODE in ${__}; do
  if [ $CURRENT_CONFIG_NODE != $NODE ]; then continue; fi
  SECTIONS="${CURRENT_CONFIG_NODE}__"
  for FILE in ${!SECTIONS}; do
    PACKAGE=${FILE}_package
    VERSION=${FILE}_version
    ADDITIONAL_PACKAGES+=", \"${!PACKAGE}\": \"${!VERSION}\""
  done
done

if [ ! -z "$ADDITIONAL_PACKAGES" ]; then
  ADDITIONAL_PACKAGES=$(printf "%s\n" "$ADDITIONAL_PACKAGES" | sed -e 's/[]\/${}"*.:^[]/\\&/g');
fi

sed -i "s/__ADDITIONAL_PACKAGES__/$ADDITIONAL_PACKAGES/g" public/composer.json

if [[ "$TEST_EXECUTE_COMPOSER" = true ]]; then
  cd public
  composer self-update
  composer update --no-progress --profile --no-scripts
  cd ../
fi

########################################
##### install assets ###################
########################################

cd public
bin/console assets:install public --relative --symlink
cd ../