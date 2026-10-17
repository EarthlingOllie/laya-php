Compose files shaped like the ones `php artisan sail:install` writes, for the
`system-one:install --mode=sail` feature tests (tests/Feature/Commands/InstallCommandTest.php).

- `docker-compose.yml` follows Sail's stub: `laravel.test` and `mysql` on the `sail` network.
- `no-volumes.yml` is trimmed by hand: two-space indentation, a comment, and no `volumes:`.
