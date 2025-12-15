# OpenDXP Testkit

### Support Table

| Branch  | Supported OpenDXP Versions | Supported Symfony Versions |
|---------|----------------------------|----------------------------|
| **1.x** | `1.x`                      | `^7.4`                     |

***

## Usage

- Copy `/.ddev/.env.dist` to `/.ddev/.env`
- Change variables to your needs

Execute:

```bash
$ ddev start
```

Execute: 

```bash
# use --composer for the first run to install dependencies
$ ddev opendxp-tests-run codecept|phpstan|cs-fixer --composer --debug
```

### Additional Test Assets
- Create a folder `assets-test` on top level and add additional assets
- If this folder exists, a symlink to `app/public/assets-test` will be created

## PhpStorm
- Exclude `/.ddev`, `/app`

## Examples

### PhpStan

```bash
$ ddev opendxp-tests-run phpstan -l 4
```

### CS Fixer

```bash
$ ddev opendxp-tests-run cs-fixer --fix
```

### Test Examples

> Examples from FormBuilderBundle

```bash
# acceptance test
$ ddev opendxp-tests-run codecept tests/Acceptance/Form/SimpleFormWithDivLayoutCest.php --debug

# functional test
$ ddev opendxp-tests-run codecept tests/Functional/Attributes/FormAttributesCest.php --debug

# unit test
$ ddev opendxp-tests-run codecept tests/Unit/Config/ActiveElementsTest.php --debug

# all
$ ddev opendxp-tests-run codecept --debug
```

### Run Test with xdebug

```bash
$ ddev enable xdebug
$ ddev opendxp-tests-run codecept-xdebug YOUR_IP tests/acceptance/Form/SimpleFormWithDivLayoutCest.php --debug
# set mapping paths in PHP|Server config:
- /var/www/public/vendor/opendxp
- /var/www/public/vendor/symfony
```