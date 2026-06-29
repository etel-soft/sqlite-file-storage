[![CI Status](https://github.com/etel-soft/sqlite-file-storage/workflows/CI/badge.svg)](https://github.com/etel-soft/sqlite-file-storage/actions)
[![codecov](https://codecov.io/gh/etel-soft/sqlite-file-storage/graph/badge.svg?token=ZRLQ94E6ZY)](https://codecov.io/gh/etel-soft/sqlite-file-storage)
[![Latest Stable Version](http://poser.pugx.org/etel/sqlite-file-storage/v)](https://packagist.org/packages/etel/sqlite-file-storage)
[![License](http://poser.pugx.org/etel/sqlite-file-storage/license)](https://packagist.org/packages/etel/sqlite-file-storage)
[![PHP Version Require](http://poser.pugx.org/etel/sqlite-file-storage/require/php)](https://packagist.org/packages/etel/sqlite-file-storage)


# Etel SQLite file storage

File storage backed by a single SQLite database, with S3-like semantics: one database file
is one storage node (bucket), objects are identified by flat path-like keys. Content is
processed strictly in chunks (never fully in memory), with optional streaming compression
(zlib family, brotli) and authenticated streaming encryption (libsodium secretstream,
XChaCha20-Poly1305). Identical content is deduplicated via refcounting.

## Quick start

```php
use Etel\SqliteFileStorage\{ArrayKeyProvider, Compression, Encryption, PutOptions, StorageFactory, StorageOptions};

$storage = new StorageFactory()->open('/var/data/bucket-main.db', new StorageOptions(
    defaultCompression: Compression::Zlib,
    defaultEncryption: Encryption::XChaCha20Poly1305,
    keyProvider: new ArrayKeyProvider(['key-2026' => $rawKey32bytes], 'key-2026')
));

$info = $storage->putObject('invoices/2026/0001.pdf', fopen('/tmp/upload.pdf', 'rb'));

$object = $storage->getObject('invoices/2026/0001.pdf');
$stream = $object->getContentStream();   // decrypted + decompressed, lazy, chunked

foreach ($storage->listObjects(prefix: 'invoices/2026/') as $item) {
    echo $item->key, ' ', $item->size, "\n";
}
```

## Requirements

PHP >= 8.4 with `ext-sqlite3`, `ext-zlib`.
Optional: `ext-sodium` (encryption), `ext-brotli` (brotli compression), `ext-fileinfo` (MIME detection).

## Development

Docker is the reference environment: PHP 8.4 (the minimum supported version) with all
optional extensions, including ext-brotli, so the full test matrix runs there.

```sh
docker compose build
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpunit --coverage-html coverage
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=1G
docker compose run --rm php vendor/bin/php-cs-fixer fix
```
Tests that need an extension missing on the host (e.g. brotli) are skipped automatically.
