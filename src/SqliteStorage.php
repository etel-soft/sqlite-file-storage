<?php

/* @noinspection SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Etel\SqliteFileStorage;

use DateTimeImmutable;
use Etel\SqliteFileStorage\Exception\ContentIntegrityException;
use Etel\SqliteFileStorage\Exception\EncryptionKeyNotFoundException;
use Etel\SqliteFileStorage\Exception\EncryptionNotAvailableException;
use Etel\SqliteFileStorage\Exception\InvalidEncryptionKeyException;
use Etel\SqliteFileStorage\Exception\ObjectAlreadyExistsException;
use Etel\SqliteFileStorage\Exception\ObjectNotFoundException;
use Etel\SqliteFileStorage\Exception\StorageIoException;
use Etel\SqliteFileStorage\Exception\StorageNotInitializedException;
use Etel\SqliteFileStorage\Exception\StorageReadOnlyException;
use Etel\SqliteFileStorage\Internal\Codec\CodecFactory;
use Etel\SqliteFileStorage\Internal\Codec\Compressor;
use Etel\SqliteFileStorage\Internal\CompressionMap;
use Etel\SqliteFileStorage\Internal\Crypto\Encryptor;
use Etel\SqliteFileStorage\Internal\Crypto\PassthroughCrypto;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamDecryptor;
use Etel\SqliteFileStorage\Internal\Crypto\SecretStreamEncryptor;
use Etel\SqliteFileStorage\Internal\DecodingStreamWrapper;
use Etel\SqliteFileStorage\Internal\EncryptionMap;
use Etel\SqliteFileStorage\Internal\Row;
use Etel\SqliteFileStorage\Internal\Streams;
use Etel\SqliteFileStorage\Internal\Uuid7;
use Exception;
use finfo;
use InvalidArgumentException;
use Override;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;
use Throwable;

use const FILEINFO_MIME_TYPE;
use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;
use const SQLITE3_ASSOC;
use const SQLITE3_INTEGER;
use const SQLITE3_NULL;
use const SQLITE3_OPEN_READWRITE;
use const SQLITE3_TEXT;

use function base64_decode;
use function base64_encode;
use function basename;
use function class_exists;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fstat;
use function function_exists;
use function fwrite;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function rewind;
use function stream_copy_to_stream;
use function strlen;
use function strtr;
use function substr;
use function time;

/**
 * SQLite-backed storage node. Create instances via StorageFactory.
 *
 * Not thread-safe: one instance encapsulates one SQLite connection.
 */
final class SqliteStorage implements Storage
{
    private const int CHUNK_SIZE = 262144;

    private const int MIME_SNIFF_SIZE = 65536;

    private const int SCHEMA_VERSION = 1;

    private const string SELECT_OBJECT = <<<'SQL'
        SELECT o.id, o.key, o.name, o.mime, o.meta, o.content_id, o.created_at, o.updated_at,
               c.hash, c.size, c.stored_size, c.compression, c.encryption, c.key_id, c.enc_context
            FROM objects o
            INNER JOIN contents c ON c.id = o.content_id
        SQL;

    public function __construct(
        private readonly SQLite3 $db,
        private readonly StorageOptions $options
    ) {}

    #[Override]
    public function close(): void
    {
        $this->db->close();
    }

    #[Override]
    public function copyObject(string $sourceKey, string $targetKey): ObjectInfo
    {
        $this->assertWritable(operation: 'copyObject');
        self::assertKey(key: $targetKey);

        $this->transactional(operation: function () use ($sourceKey, $targetKey): void {
            $source = $this->selectFullRow(key: $sourceKey) ?? throw ObjectNotFoundException::byKey(key: $sourceKey);
            $target = $this->selectObjectRow(key: $targetKey);
            $now = time();

            $this->changeRefcount(contentId: Row::int(row: $source, field: 'content_id'), delta: 1);

            if ($target !== null) {
                $this->execute(
                    sql: <<<'SQL'
                        UPDATE objects SET name = :name, mime = :mime, meta = :meta,
                            content_id = :contentId, updated_at = :now
                        WHERE key = :key
                        SQL,
                    params: [
                        ':name' => Row::string(row: $source, field: 'name'),
                        ':mime' => Row::stringOrNull(row: $source, field: 'mime'),
                        ':meta' => Row::string(row: $source, field: 'meta'),
                        ':contentId' => Row::int(row: $source, field: 'content_id'),
                        ':now' => $now,
                        ':key' => $targetKey,
                    ]
                );
                $this->changeRefcount(contentId: $target['content_id'], delta: -1);
            } else {
                $this->execute(
                    sql: <<<'SQL'
                        INSERT INTO objects (id, key, name, mime, meta, content_id, created_at)
                        VALUES (:id, :key, :name, :mime, :meta, :contentId, :now)
                        SQL,
                    params: [
                        ':id' => Uuid7::generate(),
                        ':key' => $targetKey,
                        ':name' => Row::string(row: $source, field: 'name'),
                        ':mime' => Row::stringOrNull(row: $source, field: 'mime'),
                        ':meta' => Row::string(row: $source, field: 'meta'),
                        ':contentId' => Row::int(row: $source, field: 'content_id'),
                        ':now' => $now,
                    ]
                );
            }
        });

        return $this->headObject(key: $targetKey);
    }

    #[Override]
    public function deleteObject(string $key): void
    {
        $this->assertWritable(operation: 'deleteObject');

        $this->transactional(operation: function () use ($key): void {
            $row = $this->selectObjectRow(key: $key) ?? throw ObjectNotFoundException::byKey(key: $key);

            $this->execute(sql: 'DELETE FROM objects WHERE key = :key', params: [':key' => $key]);
            $this->changeRefcount(contentId: $row['content_id'], delta: -1);
        });
    }

    #[Override]
    public function findByHash(string $hash): ?ObjectInfo
    {
        $row = $this->fetchOne(
            sql: self::SELECT_OBJECT . ' WHERE c.hash = :hash ORDER BY o.key LIMIT 1',
            params: [':hash' => $hash]
        );

        return $row === null ? null : $this->hydrateInfo(row: $row);
    }

    #[Override]
    public function getObject(string $key): StorageObject
    {
        $row = $this->selectFullRow(key: $key) ?? throw ObjectNotFoundException::byKey(key: $key);

        return new StorageObject(info: $this->hydrateInfo(row: $row), stream: $this->openContentStream(row: $row));
    }

    #[Override]
    public function getStats(): StorageStats
    {
        $row = $this->fetchOne(
            sql: <<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM objects) AS object_count,
                    (SELECT COUNT(*) FROM contents) AS content_count,
                    (SELECT COALESCE(SUM(c.size), 0) FROM objects o INNER JOIN contents c ON c.id = o.content_id)
                        AS total_size,
                    (SELECT COALESCE(SUM(stored_size), 0) FROM contents) AS total_stored_size
                SQL
        ) ?? throw StorageIoException::queryFailed();

        return new StorageStats(
            objectCount: Row::int(row: $row, field: 'object_count'),
            contentCount: Row::int(row: $row, field: 'content_count'),
            totalSize: Row::int(row: $row, field: 'total_size'),
            totalStoredSize: Row::int(row: $row, field: 'total_stored_size')
        );
    }

    #[Override]
    public function hasObject(string $key): bool
    {
        return $this->fetchOne(sql: 'SELECT 1 AS one FROM objects WHERE key = :key', params: [':key' => $key]) !== null;
    }

    #[Override]
    public function headObject(string $key): ObjectInfo
    {
        $row = $this->selectFullRow(key: $key) ?? throw ObjectNotFoundException::byKey(key: $key);

        return $this->hydrateInfo(row: $row);
    }

    #[Override]
    public function initialize(): void
    {
        $this->assertWritable(operation: 'initialize');

        // The check and the creation run under one write lock: connections opening a new database at once
        // would otherwise all see no schema and all try to create it.
        $this->transactional(operation: function (): void {
            if (!$this->schemaExists()) {
                $this->createSchema();

                return;
            }

            $storedVersion = $this->querySingle(sql: "SELECT value FROM storage_info WHERE key = 'schema_version'");
            $version = is_string(value: $storedVersion) || is_int(value: $storedVersion) ? (int) $storedVersion : 0;

            if ($version > self::SCHEMA_VERSION) {
                throw StorageIoException::schemaTooNew(version: $version);
            }

            // Sequential migration steps for future schema versions go here.
        });
    }

    #[Override]
    public function listObjects(string $prefix = '', ?string $afterKey = null, int $limit = 1000): iterable
    {
        $pageSize = max(1, $limit);
        $pattern = strtr($prefix, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
        $after = $afterKey;

        do {
            $result = $this->execute(
                sql: self::SELECT_OBJECT . "
                    WHERE o.key LIKE :pattern ESCAPE '\\' AND (:after IS NULL OR o.key > :after)
                    ORDER BY o.key
                    LIMIT :limit
                ",
                params: [':pattern' => $pattern, ':after' => $after, ':limit' => $pageSize]
            );

            $count = 0;

            while (($row = $result->fetchArray(mode: SQLITE3_ASSOC)) !== false) {
                ++$count;
                $after = Row::string(row: $row, field: 'key');

                yield $this->hydrateInfo(row: $row);
            }
        } while ($count === $pageSize);
    }

    #[Override]
    public function moveObject(string $sourceKey, string $targetKey): ObjectInfo
    {
        $this->assertWritable(operation: 'moveObject');
        self::assertKey(key: $targetKey);

        $this->transactional(operation: function () use ($sourceKey, $targetKey): void {
            $this->selectObjectRow(key: $sourceKey) ?? throw ObjectNotFoundException::byKey(key: $sourceKey);

            if ($targetKey === $sourceKey) {
                return;
            }

            $target = $this->selectObjectRow(key: $targetKey);

            if ($target !== null) {
                $this->execute(sql: 'DELETE FROM objects WHERE key = :key', params: [':key' => $targetKey]);
                $this->changeRefcount(contentId: $target['content_id'], delta: -1);
            }

            $this->execute(
                sql: 'UPDATE objects SET key = :target, updated_at = :now WHERE key = :source',
                params: [':target' => $targetKey, ':now' => time(), ':source' => $sourceKey]
            );
        });

        return $this->headObject(key: $targetKey);
    }

    #[Override]
    public function putObject(string $key, mixed $content, PutOptions $options = new PutOptions()): ObjectInfo
    {
        $this->assertWritable(operation: 'putObject');
        self::assertKey(key: $key);

        if ($options->writeMode === WriteMode::CreateOnly && $this->hasObject(key: $key)) {
            throw ObjectAlreadyExistsException::byKey(key: $key);
        }

        $compression = $options->compression ?? $this->options->defaultCompression;
        $encryption = $options->encryption ?? $this->options->defaultEncryption;

        [$encryptor, $keyId] = $this->createEncryptor(encryption: $encryption);

        $source = Streams::toResource(content: $content);
        $ownsSource = $source !== $content;

        try {
            [$spool, $hash, $size, $storedSize, $firstBytes] = $this->encode(
                source: $source,
                compressor: CodecFactory::compressor(compression: $compression),
                encryptor: $encryptor
            );
        } finally {
            if ($ownsSource) {
                fclose(stream: $source);
            }
        }

        $encContext = $encryption === Encryption::None ? null : json_encode(
            value: ['header' => base64_encode(string: $encryptor->header()), 'chunk_size' => self::CHUNK_SIZE],
            flags: JSON_THROW_ON_ERROR
        );

        try {
            $this->transactional(
                operation: function () use (
                    $key,
                    $options,
                    $compression,
                    $encryption,
                    $keyId,
                    $encContext,
                    $spool,
                    $hash,
                    $size,
                    $storedSize,
                    $firstBytes
                ): void {
                    $existing = $this->selectObjectRow(key: $key);

                    if ($existing !== null && $options->writeMode === WriteMode::CreateOnly) {
                        throw ObjectAlreadyExistsException::byKey(key: $key); // @codeCoverageIgnore
                    }

                    $now = time();
                    $contentId = $options->deduplicate
                        ? $this->findReusableContent(hash: $hash, encryption: $encryption, keyId: $keyId)
                        : null;

                    if ($contentId !== null) {
                        $this->changeRefcount(contentId: $contentId, delta: 1);
                    } else {
                        $contentId = $this->insertContent(
                            hash: $hash,
                            size: $size,
                            storedSize: $storedSize,
                            compression: $compression,
                            encryption: $encryption,
                            keyId: $keyId,
                            encContext: $encContext,
                            spool: $spool,
                            now: $now
                        );
                    }

                    $name = $options->name ?? basename(path: $key);
                    $mime = $options->mimeType ?? self::detectMime(leadingBytes: $firstBytes);
                    $meta = json_encode(value: $options->meta, flags: JSON_THROW_ON_ERROR);

                    if ($existing !== null) {
                        $this->execute(
                            sql: <<<'SQL'
                                UPDATE objects SET name = :name, mime = :mime, meta = :meta,
                                    content_id = :contentId, updated_at = :now
                                WHERE key = :key
                                SQL,
                            params: [
                                ':name' => $name,
                                ':mime' => $mime,
                                ':meta' => $meta,
                                ':contentId' => $contentId,
                                ':now' => $now,
                                ':key' => $key,
                            ]
                        );
                        $this->changeRefcount(contentId: $existing['content_id'], delta: -1);
                    } else {
                        $this->execute(
                            sql: <<<'SQL'
                                INSERT INTO objects (id, key, name, mime, meta, content_id, created_at)
                                VALUES (:id, :key, :name, :mime, :meta, :contentId, :now)
                                SQL,
                            params: [
                                ':id' => $options->id ?? Uuid7::generate(),
                                ':key' => $key,
                                ':name' => $name,
                                ':mime' => $mime,
                                ':meta' => $meta,
                                ':contentId' => $contentId,
                                ':now' => $now,
                            ]
                        );
                    }
                }
            );
        } finally {
            fclose(stream: $spool);
        }

        return $this->headObject(key: $key);
    }

    #[Override]
    public function updateObjectMeta(string $key, array $meta, ?string $mimeType = null): ObjectInfo
    {
        $this->assertWritable(operation: 'updateObjectMeta');

        if (!$this->hasObject(key: $key)) {
            throw ObjectNotFoundException::byKey(key: $key);
        }

        $params = [
            ':meta' => json_encode(value: $meta, flags: JSON_THROW_ON_ERROR),
            ':now' => time(),
            ':key' => $key,
        ];

        if ($mimeType !== null) {
            $params[':mime'] = $mimeType;
            $this->execute(
                sql: 'UPDATE objects SET meta = :meta, mime = :mime, updated_at = :now WHERE key = :key',
                params: $params
            );
        } else {
            $this->execute(
                sql: 'UPDATE objects SET meta = :meta, updated_at = :now WHERE key = :key',
                params: $params
            );
        }

        return $this->headObject(key: $key);
    }

    #[Override]
    public function vacuum(): void
    {
        $this->assertWritable(operation: 'vacuum');

        try {
            $this->db->exec(query: 'VACUUM');
            // @codeCoverageIgnoreStart
        } catch (Exception $exception) {
            throw StorageIoException::fromSqlite(previous: $exception);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Asserts the schema is present; used by StorageFactory for read-only connections.
     *
     * @internal
     */
    public function verifyInitialized(string $path): void
    {
        if (!$this->schemaExists()) {
            throw StorageNotInitializedException::atPath(path: $path);
        }
    }

    private function assertWritable(string $operation): void
    {
        if ($this->options->readOnly) {
            throw StorageReadOnlyException::forOperation(operation: $operation);
        }
    }

    private function changeRefcount(int $contentId, int $delta): void
    {
        $this->execute(
            sql: 'UPDATE contents SET refcount = refcount + :delta WHERE id = :id',
            params: [':delta' => $delta, ':id' => $contentId]
        );

        if ($delta < 0) {
            $this->execute(sql: 'DELETE FROM contents WHERE id = :id AND refcount <= 0', params: [':id' => $contentId]);
        }
    }

    /**
     * @return array{Encryptor, null|string} encryptor and the key id used (null when not encrypted)
     */
    private function createEncryptor(Encryption $encryption): array
    {
        if ($encryption === Encryption::None) {
            return [new PassthroughCrypto(), null];
        }

        self::assertEncryptionAvailable(encryption: $encryption);

        $provider = $this->options->keyProvider ?? throw EncryptionKeyNotFoundException::noKeyProvider();
        $keyId = $provider->getCurrentKeyId();
        $key = $provider->getKey(keyId: $keyId);
        self::assertKeyLength(key: $key, keyId: $keyId);

        return [new SecretStreamEncryptor(key: $key), $keyId];
    }

    private function createSchema(): void
    {
        try {
            $this->db->exec(query: 'CREATE TABLE storage_info (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

            $this->db->exec(
                query: <<<'SQL'
                    CREATE TABLE contents (
                        id          INTEGER PRIMARY KEY,
                        hash        TEXT NOT NULL,
                        size        INTEGER NOT NULL,
                        stored_size INTEGER NOT NULL,
                        compression INTEGER NOT NULL,
                        encryption  INTEGER NOT NULL,
                        key_id      TEXT,
                        enc_context TEXT,
                        refcount    INTEGER NOT NULL,
                        content     BLOB NOT NULL,
                        created_at  INTEGER NOT NULL
                    )
                    SQL
            );

            $this->db->exec(query: 'CREATE INDEX idx_contents_hash ON contents (hash)');

            $this->db->exec(
                query: <<<'SQL'
                    CREATE TABLE objects (
                        id         TEXT NOT NULL UNIQUE,
                        key        TEXT NOT NULL UNIQUE,
                        name       TEXT NOT NULL,
                        mime       TEXT,
                        meta       TEXT NOT NULL DEFAULT '{}',
                        content_id INTEGER NOT NULL,
                        created_at INTEGER NOT NULL,
                        updated_at INTEGER
                    )
                    SQL
            );

            $this->db->exec(query: 'CREATE INDEX idx_objects_content ON objects (content_id)');
            $this->db->exec(query: 'CREATE INDEX idx_objects_created ON objects (created_at)');

            $stmt = $this->prepare(sql: 'INSERT INTO storage_info (key, value) VALUES (:key, :value)');
            $stmt->bindValue(param: ':key', value: 'schema_version');
            $stmt->bindValue(param: ':value', value: (string) self::SCHEMA_VERSION);
            $stmt->execute();

            $stmt = $this->prepare(sql: 'INSERT INTO storage_info (key, value) VALUES (:key, :value)');
            $stmt->bindValue(param: ':key', value: 'created_at');
            $stmt->bindValue(param: ':value', value: (string) time());
            $stmt->execute();
            // @codeCoverageIgnoreStart
        } catch (Exception $exception) {
            throw StorageIoException::fromSqlite(previous: $exception);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Pulls the source stream through hash + compression + encryption into a spool stream.
     *
     * @param resource $source
     *
     * @return array{resource, string, int, int, string} [spool, hash, size, storedSize, firstBytes]
     */
    private function encode(mixed $source, Compressor $compressor, Encryptor $encryptor): array
    {
        $spool = fopen(filename: Streams::SPOOL_URI, mode: 'r+b');

        if ($spool === false) {
            throw StorageIoException::streamOpenFailed(); // @codeCoverageIgnore
        }

        $hashContext = hash_init(algo: 'sha256');
        $size = 0;
        $firstBytes = '';
        $pending = '';

        while (!feof(stream: $source)) {
            $chunk = fread(stream: $source, length: self::CHUNK_SIZE);

            if ($chunk === false) {
                throw StorageIoException::readFailed(); // @codeCoverageIgnore
            }

            if ($chunk === '') {
                continue;
            }

            if (strlen(string: $firstBytes) < self::MIME_SNIFF_SIZE) {
                $firstBytes .= substr(
                    string: $chunk,
                    offset: 0,
                    length: self::MIME_SNIFF_SIZE - strlen(string: $firstBytes)
                );
            }

            hash_update(context: $hashContext, data: $chunk);
            $size += strlen(string: $chunk);
            $pending .= $compressor->add(chunk: $chunk);

            while (strlen(string: $pending) >= self::CHUNK_SIZE) {
                $this->spoolWrite(spool: $spool, data: $encryptor->encryptChunk(
                    plaintext: substr(string: $pending, offset: 0, length: self::CHUNK_SIZE),
                    final: false
                ));
                $pending = substr(string: $pending, offset: self::CHUNK_SIZE);
            }
        }

        $pending .= $compressor->finish();

        while (strlen(string: $pending) > self::CHUNK_SIZE) {
            $this->spoolWrite(spool: $spool, data: $encryptor->encryptChunk(
                plaintext: substr(string: $pending, offset: 0, length: self::CHUNK_SIZE),
                final: false
            ));
            $pending = substr(string: $pending, offset: self::CHUNK_SIZE);
        }

        $this->spoolWrite(spool: $spool, data: $encryptor->encryptChunk(plaintext: $pending, final: true));

        $stat = fstat(stream: $spool);

        if ($stat === false) {
            throw StorageIoException::readFailed(); // @codeCoverageIgnore
        }

        rewind(stream: $spool);

        return [$spool, hash_final(context: $hashContext), $size, $stat['size'], $firstBytes];
    }

    /**
     * @param array<string, null|int|string> $params
     */
    private function execute(string $sql, array $params = []): SQLite3Result
    {
        $stmt = $this->prepare(sql: $sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue(param: $name, value: $value, type: match (true) {
                $value === null => SQLITE3_NULL,
                is_int($value) => SQLITE3_INTEGER,
                default => SQLITE3_TEXT
            });
        }

        try {
            $result = $stmt->execute();
        } catch (Exception $exception) {
            throw StorageIoException::fromSqlite(previous: $exception);
        }

        if ($result === false) {
            throw StorageIoException::queryFailed(); // @codeCoverageIgnore
        }

        return $result;
    }

    /**
     * @param array<string, null|int|string> $params
     *
     * @return null|array<string, mixed>
     */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->execute(sql: $sql, params: $params)->fetchArray(mode: SQLITE3_ASSOC);

        return $row === false ? null : $row;
    }

    private function findReusableContent(string $hash, Encryption $encryption, ?string $keyId): ?int
    {
        $row = $this->fetchOne(
            sql: 'SELECT id FROM contents WHERE hash = :hash AND encryption = :encryption AND key_id IS :keyId LIMIT 1',
            params: [
                ':hash' => $hash,
                ':encryption' => EncryptionMap::fromDomain(encryption: $encryption),
                ':keyId' => $keyId,
            ]
        );

        return $row === null ? null : Row::int(row: $row, field: 'id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateInfo(array $row): ObjectInfo
    {
        $decodedMeta = json_decode(
            json: Row::string(row: $row, field: 'meta'),
            associative: true,
            flags: JSON_THROW_ON_ERROR
        );
        $meta = [];

        if (is_array(value: $decodedMeta)) {
            foreach ($decodedMeta as $metaKey => $metaValue) {
                $meta[(string) $metaKey] = $metaValue;
            }
        }

        $updatedAt = Row::intOrNull(row: $row, field: 'updated_at');

        return new ObjectInfo(
            id: Row::string(row: $row, field: 'id'),
            key: Row::string(row: $row, field: 'key'),
            name: Row::string(row: $row, field: 'name'),
            mimeType: Row::stringOrNull(row: $row, field: 'mime'),
            hash: Row::string(row: $row, field: 'hash'),
            size: Row::int(row: $row, field: 'size'),
            storedSize: Row::int(row: $row, field: 'stored_size'),
            compression: CompressionMap::toDomain(value: Row::int(row: $row, field: 'compression')),
            encryption: EncryptionMap::toDomain(value: Row::int(row: $row, field: 'encryption')),
            meta: $meta,
            createdAt: DateTimeImmutable::createFromTimestamp(timestamp: Row::int(row: $row, field: 'created_at')),
            updatedAt: $updatedAt === null ? null : DateTimeImmutable::createFromTimestamp(timestamp: $updatedAt)
        );
    }

    /**
     * @param resource $spool
     */
    private function insertContent(
        string $hash,
        int $size,
        int $storedSize,
        Compression $compression,
        Encryption $encryption,
        ?string $keyId,
        ?string $encContext,
        mixed $spool,
        int $now
    ): int {
        $this->execute(
            sql: <<<'SQL'
                INSERT INTO contents (
                    hash, size, stored_size, compression, encryption, key_id, enc_context, refcount, content, created_at
                )
                VALUES (
                    :hash,
                    :size,
                    :storedSize,
                    :compression,
                    :encryption,
                    :keyId,
                    :encContext,
                    1,
                    zeroblob(:storedSize),
                    :createdAt
                )
                SQL,
            params: [
                ':hash' => $hash,
                ':size' => $size,
                ':storedSize' => $storedSize,
                ':compression' => CompressionMap::fromDomain(compression: $compression),
                ':encryption' => EncryptionMap::fromDomain(encryption: $encryption),
                ':keyId' => $keyId,
                ':encContext' => $encContext,
                ':createdAt' => $now,
            ]
        );

        $contentId = $this->db->lastInsertRowID();

        if ($storedSize > 0) {
            $blob = $this->db->openBlob(
                table: 'contents',
                column: 'content',
                rowid: $contentId,
                flags: SQLITE3_OPEN_READWRITE
            );

            if ($blob === false) {
                throw StorageIoException::blobOpenFailed(); // @codeCoverageIgnore
            }

            $copied = stream_copy_to_stream(from: $spool, to: $blob);
            fclose(stream: $blob);

            if ($copied !== $storedSize) {
                throw StorageIoException::writeFailed(); // @codeCoverageIgnore
            }
        }

        return $contentId;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return resource
     */
    private function openContentStream(array $row)
    {
        $blob = $this->db->openBlob(
            table: 'contents',
            column: 'content',
            rowid: Row::int(row: $row, field: 'content_id')
        );

        if ($blob === false) {
            throw StorageIoException::blobOpenFailed(); // @codeCoverageIgnore
        }

        $encryption = EncryptionMap::toDomain(value: Row::int(row: $row, field: 'encryption'));
        $cipherChunkSize = 0;
        $decryptor = new PassthroughCrypto();

        if ($encryption !== Encryption::None) {
            self::assertEncryptionAvailable(encryption: $encryption);

            $context = json_decode(
                json: Row::string(row: $row, field: 'enc_context'),
                associative: true,
                flags: JSON_THROW_ON_ERROR
            );

            if (
                !is_array(value: $context)
                || !is_string(value: $context['header'] ?? null)
                || !is_int(value: $context['chunk_size'] ?? null)
            ) {
                throw ContentIntegrityException::detected(reason: 'malformed encryption context');
            }

            $header = base64_decode(string: $context['header'], strict: true);

            if ($header === false) {
                throw ContentIntegrityException::detected(reason: 'malformed encryption header');
            }

            $provider = $this->options->keyProvider ?? throw EncryptionKeyNotFoundException::noKeyProvider();
            $keyId = Row::string(row: $row, field: 'key_id');
            $key = $provider->getKey(keyId: $keyId);
            self::assertKeyLength(key: $key, keyId: $keyId);

            $decryptor = new SecretStreamDecryptor(key: $key, header: $header);
            $cipherChunkSize = $context['chunk_size'] + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        }

        return DecodingStreamWrapper::open(
            source: $blob,
            decryptor: $decryptor,
            decompressor: CodecFactory::decompressor(
                compression: CompressionMap::toDomain(value: Row::int(row: $row, field: 'compression')),
            ),
            cipherChunkSize: $cipherChunkSize,
            storedSize: Row::int(row: $row, field: 'stored_size'),
            plainSize: Row::int(row: $row, field: 'size')
        );
    }

    private function prepare(string $sql): SQLite3Stmt
    {
        try {
            $stmt = $this->db->prepare(query: $sql);
            // @codeCoverageIgnoreStart
        } catch (Exception $exception) {
            throw StorageIoException::fromSqlite(previous: $exception);
        }
        // @codeCoverageIgnoreEnd

        if ($stmt === false) {
            throw StorageIoException::queryFailed(); // @codeCoverageIgnore
        }

        return $stmt;
    }

    private function querySingle(string $sql): mixed
    {
        try {
            return $this->db->querySingle(query: $sql);
            // @codeCoverageIgnoreStart
        } catch (Exception $exception) {
            throw StorageIoException::fromSqlite(previous: $exception);
        }
        // @codeCoverageIgnoreEnd
    }

    private function schemaExists(): bool
    {
        $name = $this->querySingle(
            sql: "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'storage_info'"
        );

        return $name !== null && $name !== false;
    }

    /**
     * @return null|array{id: string, content_id: int, created_at: int}
     */
    private function selectObjectRow(string $key): ?array
    {
        $row = $this->fetchOne(
            sql: 'SELECT id, content_id, created_at FROM objects WHERE key = :key',
            params: [':key' => $key]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => Row::string(row: $row, field: 'id'),
            'content_id' => Row::int(row: $row, field: 'content_id'),
            'created_at' => Row::int(row: $row, field: 'created_at'),
        ];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function selectFullRow(string $key): ?array
    {
        return $this->fetchOne(sql: self::SELECT_OBJECT . ' WHERE o.key = :key', params: [':key' => $key]);
    }

    /**
     * @param resource $spool
     */
    private function spoolWrite($spool, string $data): void
    {
        if ($data === '') {
            return;
        }

        if (fwrite(stream: $spool, data: $data) !== strlen(string: $data)) {
            throw StorageIoException::writeFailed(); // @codeCoverageIgnore
        }
    }

    /**
     * @param callable(): void $operation
     */
    private function transactional(callable $operation): void
    {
        try {
            $this->db->exec(query: 'BEGIN IMMEDIATE');
            // @codeCoverageIgnoreStart
        } catch (Exception $exception) {
            throw StorageIoException::fromSqlite(previous: $exception);
        }
        // @codeCoverageIgnoreEnd

        try {
            $operation();
            $this->db->exec(query: 'COMMIT');
        } catch (Throwable $exception) {
            $this->db->exec(query: 'ROLLBACK');

            throw $exception;
        }
    }

    private static function assertEncryptionAvailable(Encryption $encryption): void
    {
        if (!function_exists(function: 'sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw EncryptionNotAvailableException::forAlgorithm(encryption: $encryption); // @codeCoverageIgnore
        }
    }

    private static function assertKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException(message: 'Object key must not be empty.');
        }
    }

    private static function assertKeyLength(string $key, string $keyId): void
    {
        if (strlen(string: $key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw InvalidEncryptionKeyException::byKeyId(
                keyId: $keyId,
                expectedBytes: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            );
        }
    }

    private static function detectMime(string $leadingBytes): ?string
    {
        if ($leadingBytes === '' || !class_exists(class: finfo::class)) {
            return null;
        }

        $mime = new finfo(flags: FILEINFO_MIME_TYPE)->buffer(string: $leadingBytes);

        return $mime === false ? null : $mime;
    }
}
