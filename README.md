# OpenDXP Testkit

### Support Table

| Branch  | Supported OpenDXP Versions | Supported Symfony Versions |
|---------|----------------------------|----------------------------|
| **1.x** | `1.x`                      | `^7.4`                     |

***

## Usage

- Copy `/.ddev/.env.dist` to `/.ddev/.env`
- Change variables to your needs

> Note: set TEST_EXECUTE_COMPOSER to "true" for initial setup!

Execute:

```bash
$ ddev start
```

Execute: 

```bash
$ sh test_dispatch ecs|phpstan|tests [args] # on mac
$ ./test_dispatch ecs|phpstan|tests [args] # on linux
```
## PhpStorm
- Exclude `/.ddev`, `/data` and `/public`

## Examples

### PhpStan

```bash
$ ./test_dispatch phpstan -l 4
```

### ECS

```bash
$ ./test_dispatch ecs --fix
```

### Test Examples

> Examples from FormBuilderBundle

```bash
# acceptance test
$ ./test_dispatch tests tests/Acceptance/Form/SimpleFormWithDivLayoutCest.php --debug

# functional test
$ ./test_dispatch tests tests/Functional/Attributes/FormAttributesCest.php --debug

# unit test
$ ./test_dispatch tests tests/Unit/Config/ActiveElementsTest.php --debug

# all
$ ./test_dispatch tests --debug
```

### Run Test with xdebug

```bash
$ ddev enable xdebug
$ ./test_dispatch tests-xdebug YOUR_IP tests/acceptance/Form/SimpleFormWithDivLayoutCest.php --debug
# set mapping paths in PHP|Server config:
- /var/www/public/vendor/opendxp
- /var/www/public/vendor/symfony
```