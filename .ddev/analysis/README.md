# Architecture check containers

Two containers, one recipe. `Dockerfile` takes the tool name, the PHP version and any system
packages as build arguments, so the difference between the two is four lines of
`docker-compose.analysis.yaml` rather than a second recipe.

```
ddev opendxp-analysis            both, and write the report
ddev opendxp-analysis deptrac    layer rules, and the picture
ddev opendxp-analysis arkitect   class rules
```

Each tool gets its own container because their dependency trees exclude one another:

- **deptrac 4** needs `nikic/php-parser ^5`.
- **PHPArkitect 0.3** needs `nikic/php-parser ~4`, and `symfony/translation ^7.4` declares a
  conflict with php-parser v4.

Neither tree can be shared with the application either. `app/` carries no `composer.lock` and is
wiped on every `ddev start`, so a `composer require` there drops everything installed but not
declared.

## PHP versions

deptrac follows the application: `PHP_VERSION: ${DDEV_PHP_VERSION:-8.4}`. A tool that judges the
application's structure runs on the version the application runs on.

PHPArkitect is pinned to 8.3 because its parser refuses anything newer. That is the tool's ceiling,
not the application's, and it is written down in the compose file so nobody reads it as a statement
about the code.

## Where the configuration lives

Not here. A tool's configuration belongs to the code it judges, so `deptrac.yaml` and
`phparkitect.php` sit in the bundle's own repository next to `phpstan.neon` and are rsynced into
`app/` with everything else. This directory only says which release is installed.

`composer.json` of each tool is versioned. The lock file and the `vendor` directory live in the
image.
