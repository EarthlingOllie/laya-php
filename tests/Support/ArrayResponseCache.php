<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use ArrayObject;
use EarthlingOllie\SystemOneClassification\Client\ResponseCache;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Transport\Response;

use function array_keys;

/** An in-memory ResponseCache for client tests: it keeps what is put and records why it was skipped. */
final readonly class ArrayResponseCache implements ResponseCache
{
    /**
     * @param  ArrayObject<string, Response>  $entries
     * @param  ArrayObject<int, SystemOneException>  $skipped
     * @param  ArrayObject<int, string>  $log  'get <key>' or 'put <key>' per call
     */
    public function __construct(
        public ArrayObject $entries = new ArrayObject,
        public ArrayObject $skipped = new ArrayObject,
        public ArrayObject $log = new ArrayObject,
    ) {}

    public function get(string $key): ?Response
    {
        $this->log->append("get {$key}");

        return $this->entries[$key] ?? null;
    }

    public function put(string $key, Response $response): void
    {
        $this->log->append("put {$key}");
        $this->entries[$key] = $response;
    }

    public function skipped(SystemOneException $reason): void
    {
        $this->skipped->append($reason);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->entries->getArrayCopy());
    }
}
