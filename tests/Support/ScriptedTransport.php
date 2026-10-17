<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use ArrayObject;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use EarthlingOllie\SystemOneClassification\Transport\Transport;
use LogicException;

use function array_shift;
use function array_values;

/**
 * A transport that answers from a script and logs every call, for client tests that need no HTTP.
 *
 * Each POST takes the next scripted POST response, each GET the next GET response; running out
 * of script fails the test. The log may be shared with other test doubles to check call order.
 */
final readonly class ScriptedTransport implements Transport
{
    /** @var ArrayObject<int, Response> */
    private ArrayObject $posts;

    /** @var ArrayObject<int, Response> */
    private ArrayObject $gets;

    /**
     * @param  list<Response>  $posts
     * @param  list<Response>  $gets
     * @param  ArrayObject<int, string>  $log  'POST /path' or 'GET /path' per call
     */
    public function __construct(array $posts = [], array $gets = [], public ArrayObject $log = new ArrayObject)
    {
        $this->posts = new ArrayObject($posts);
        $this->gets = new ArrayObject($gets);
    }

    public function post(string $path, array $body): Response
    {
        $this->log->append("POST {$path}");

        return self::next($this->posts, "POST {$path}");
    }

    public function get(string $path): Response
    {
        $this->log->append("GET {$path}");

        return self::next($this->gets, "GET {$path}");
    }

    /** @return list<string> */
    public function calls(): array
    {
        return array_values($this->log->getArrayCopy());
    }

    /** @param ArrayObject<int, Response> $script */
    private static function next(ArrayObject $script, string $call): Response
    {
        $remaining = $script->getArrayCopy();
        $next = array_shift($remaining) ?? throw new LogicException("No scripted response left for {$call}.");
        $script->exchangeArray($remaining);

        return $next;
    }
}
