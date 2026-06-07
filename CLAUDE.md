# Perla's Flowers — Developer Notes

## Deployment

There is no local dev environment. All testing is done against the live site at
`https://flowers.cresswell.org` via the `pair` SSH alias (see server memory).

Files are deployed by uploading directly via SSH. After uploading, smoke-test
every changed route with `curl` via `mcp__ssh-manager__ssh_execute`.

### Adding a new controller class

`composer.json` sets `"optimize-autoloader": true`, which generates a static
classmap at `vendor/composer/autoload_classmap.php`. **Any new class added to
`src/` will not be found by the autoloader until the classmap is regenerated.**
A missing class causes a silent HTTP 500 with an empty body (pair.com's Apache
swallows the PHP error output for 500 responses).

After uploading a new controller (or any new class under `src/`), always run on
the server:

```bash
cd ~/public_html/flowers.cresswell.org && composer dump-autoload --optimize
```

Verify the class was picked up:

```bash
grep "ClassName" ~/public_html/flowers.cresswell.org/vendor/composer/autoload_classmap.php
```

### .env values with spaces must be quoted

vlucas/phpdotenv throws `InvalidFileException` on unquoted values that contain
spaces (e.g. `BUSINESS_STREET_ADDRESS=6134 S Troost Ave`). Always quote them:

```
BUSINESS_STREET_ADDRESS="6134 S Troost Ave"
```

A dotenv parse failure causes the same silent 500 as above on any route that
has no OPcache bytecode entry yet (typically new routes).

### Debugging a silent 500 on pair.com

1. Check syntax: `php82 -l path/to/file.php`
2. Check classmap: `grep "MyClass" vendor/composer/autoload_classmap.php`
3. Drop a standalone PHP file in `public/` with `ini_set('display_errors', 1)`
   at the top — Apache serves real files directly, so errors appear as 200 with
   error HTML rather than being swallowed.
4. Remove test files when done: `rm public/test_*.php`
