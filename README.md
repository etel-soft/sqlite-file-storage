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

## Compression decided per object

An `AutoCompression` in place of an algorithm stores each object compressed or as it is, whichever pays, as
`compression=on` does in ZFS: content already compressed (JPEG, MP4, ZIP, FLAC…) is not compressed again, which
saves CPU on the write and lets a reader seek in it rather than decode it from its first byte (see
[Seeking in content](#seeking-in-content)). The writer reads the first MiB of the content first:

- a type known to be compressed, detected from the bytes, is stored as it is;
- otherwise four 64 KiB stretches across that MiB are compressed as a probe, and the content is compressed when the
  median of their ratios is under 0.95 — a header unlike the rest (padding, a thumbnail) does not decide it.

What was applied is stored and reported (`ObjectInfo::$compression`, `EncodedContent::$compression`): always a
`Compression`. The algorithm (zlib), the threshold, the sample (up to 16 MiB, held in memory on each write) and the
type list are the `AutoCompression`'s own settings. It goes wherever a `Compression` does: `StorageOptions`,
`PutOptions`, `FileCodec::encode()`.

```php
$storage = new StorageFactory()->open($path, new StorageOptions(defaultCompression: new AutoCompression()));
```

## Seeking in content

The content stream seeks (`fseek`). Content stored as it is — neither compressed nor encrypted — seeks anywhere, at
once: a position in it is one in the stored bytes. Other content seeks by decoding what it skips (an encrypted chunk
opens only after the ones before it, a compressed stream only from its start): forward from where the stream is,
back from the start of the content again — a seek back in a large object costs a read of it up to the target.
`FileCodec::decode()` streams seek the same way, given a seekable source; without one they seek forward only. Such
a stream takes its source over: closing it closes the source.

## Content bound to associated data

Encrypted content can be bound to associated data, say its owner and its name: the bytes are authenticated with the
first chunk, and every next chunk depends on it, so the content decrypts only with the same bytes. A replica moved
under another owner or name — by anyone who can write the database or the disk, but has no key — then fails to
decrypt instead of being served as the other object.

```php
$storage->putObject($key, $content, new PutOptions(encryption: Encryption::XChaCha20Poly1305, associatedData: $ad));
$storage->getObject($key, associatedData: $ad);
```

What a reader gives is what the content must be bound to: `getObject()` with associated data refuses content bound
to other data, content that is not bound, and content that is not encrypted — so nothing else can be put in the
object's place. Content written unbound (before 2.0, or with no data) reads without associated data only
(`ObjectInfo::$boundToAssociatedData` tells which it is); to bind it, write it again. Associated data needs
encryption: `putObject()` refuses it without. The storage keeps a digest of the data with the content, to share
identical content only under the same binding and to refuse other data with a clear message. The digest is keyed
(BLAKE2b under a key derived from the content key): associated data is often guessable, and a bare hash would give
it away to anyone reading the database, and link the objects bound alike.
`FileCodec::encode()` and `decode()` take the same `associatedData`, under the same rules. A copy (`copyObject`)
or a move keeps the binding of its source.

## What encryption does not hide

Encryption covers the content only. Anyone who reads the database file sees, in the clear, the keys and names of
objects, their metadata and types, sizes (original and stored), times — and the SHA-256 of each original content
(`ObjectInfo::$hash`, the ETag, which `findByHash()` and deduplication match). With the hash, a reader without the
key can tell whether the storage holds a file they know, and which objects share content. That is the price of
deduplication and of a content ETag across keys.

A hash key takes the first of these away. With `StorageOptions::$hashKeyId` — the id of a 32-byte key of the key
provider, one kept for this alone — the hash is an HMAC-SHA256 of the content instead of its SHA-256: the same
64 hex characters, the same for the same content, but nothing a reader without the key can compute.

```php
$storage = new StorageFactory()->open(path: $path, options: new StorageOptions(
    defaultEncryption: Encryption::XChaCha20Poly1305,
    keyProvider: new ArrayKeyProvider(keys: ['content' => $contentKey, 'hash' => $hashKey], currentKeyId: 'content'),
    hashKeyId: 'hash'
));

$storage->findByHash(hash: $storage->contentHash(content: $file));
```

- `ObjectInfo::$hash` is then no SHA-256 of the content: to look content up, or to compare an ETag, hash it with
  `Storage::contentHash()`.
- Set the key on a new storage and never change it. The storage does not record how a content was hashed: in one
  that already holds content, what was hashed before keeps the hash it has — it is not found by the new hash and
  not shared with content written after; nothing else breaks.
- Without `hashKeyId` nothing changes: the hash stays the SHA-256, and an existing storage deduplicates as it did.
- What stays visible: which objects share content, and all the sizes — a known file may be told by its exact size.
- It matters for encrypted content only: content stored unencrypted is there to read.

`FileCodec` takes the same `hashKeyId` and has the same `contentHash()`.

## Durability

The storage runs SQLite in WAL mode. By default (`Durability::Buffered`, `synchronous = NORMAL`) the log is
synced only at checkpoints: the storage stays consistent and a process crash loses nothing, but a power cut or
an OS crash may roll back the last writes, ones that already returned. Where a write that returned must be on
the disk, ask for `Durability::Synced` (`synchronous = FULL`): every commit syncs the log. That is a sync per
write — on an SSD a few milliseconds, which bounds how many writes a second one storage takes (its writes go one
at a time): for small objects several times fewer than buffered.

```php
$storage = new StorageFactory()->open($path, new StorageOptions(durability: Durability::Synced));
```

The log does not grow back to nothing by itself: after a large write SQLite starts it over from the beginning but
keeps the file. A write connection cuts it back to `journalSizeLimitBytes` (`PRAGMA journal_size_limit`, 64 MiB by
default; `-1` leaves it unbounded) when it starts the log over, so one large write leaves no log of its size behind.

Opening an existing storage for writing takes no write lock: the schema is read as any other data, and only a new
database (or one to migrate) is set up under `BEGIN IMMEDIATE`.

## Deleting in batches

`deleteObject()` is a transaction of its own, so a commit — and, under `Durability::Synced`, a sync to disk — per key.
`deleteObjects()` (since 2.1) takes a list of keys in one transaction:

```php
$deleted = $storage->deleteObjects(['invoices/2026/0001.pdf', 'invoices/2026/0002.pdf']);
```

It deletes all of them or, when it fails, none. A key with no object is passed over rather than refused
(`deleteObject()` throws an `ObjectNotFoundException`); what comes back is how many objects were deleted. Content
shared with objects that stay is kept, as with `deleteObject()`.

An implementation or a decorator of `Storage` adds the method.

## Upgrading from 1.x

2.0 changes the API where 1.x let invalid states through; the database format stays — a 1.x database opens as it
is, with no migration.

- PHP 8.5 is the minimum (1.x ran on 8.4).
- `Storage` has a new method, `contentHash()`: an implementation or a decorator of `Storage` adds it.
- `Compression::Auto` is gone: a request to decide per object is an `AutoCompression`, given where a `Compression`
  goes (`StorageOptions::$defaultCompression`, `PutOptions::$compression`, `FileCodec::encode()`), and carries its
  settings — `StorageOptions::$autoCompression` and the `FileCodec` constructor argument are gone with it.
  `Compression::cases()` lists only what can be stored.
- `Storage::getObject()` takes `$associatedData`: an implementation or a decorator of `Storage` adds it.
- Associated data is checked strictly: `getObject()` and `FileCodec::decode()` with it refuse content that is not
  bound or not encrypted; `putObject()` and `FileCodec::encode()` refuse it without encryption. Content bound to
  associated data is read by 2.0 only.
- `listObjects(prefix:)` compares keys byte by byte: it no longer matches keys that differ from the prefix in case.
- Content streams seek (`fseek`); `StorageObject::getContentStream()` was documented as not seekable.
- A write SQLite rolls back itself (a full disk, an I/O error) fails with its cause, a `StorageIoException`, instead
  of a raw `SQLite3Exception` from the rollback.
- Content must decode to its recorded size: content cut short (a stored file not written to its end, a compressed
  stream without its end) or longer than recorded fails with a `ContentIntegrityException` instead of being read as
  if it were whole. `FileCodec::decode()` needs the exact `$plainSize` for that.
- A key, an id, a name or a mime type with a NUL byte is refused (`InvalidArgumentException`): SQLite gives such a
  text back cut at that byte. Meta that cannot be stored as JSON (a string that is not UTF-8, `NAN`) is refused the
  same way, before anything is written.
- `close()` on a storage whose content stream is still open fails with a `StorageIoException`.

## Requirements

PHP >= 8.5 with `ext-sqlite3`, `ext-zlib`.
Optional: `ext-sodium` (encryption), `ext-brotli` (brotli compression), `ext-fileinfo` (MIME detection).

## Development

Docker is the reference environment: PHP 8.5 (the minimum supported version) with all
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
