<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use function array_filter;
use function array_map;
use function is_string;
use function parse_url;
use function preg_replace;
use function rawurldecode;
use function rawurlencode;
use function str_replace;
use function strlen;
use function urlencode;
use function usort;

/**
 * Masks credentials in text that may reach an exception message, a log line or an event:
 * the connection's API key and URL userinfo (given to forConnection()), plus, in any text,
 * URL userinfo, every query value of a URL, secret-looking `name=value` pairs and bearer
 * tokens. Each masked part becomes `***`.
 *
 * It errs on the side of masking: a short key is masked wherever it appears, even inside
 * an unrelated word.
 */
final readonly class Redactor
{
    private const string MASK = '***';

    /** `scheme://userinfo@`; the userinfo may hold a raw `@`, so it runs to the last one. */
    private const string USERINFO = '~\b([a-z][a-z0-9+.\-]*://)[^\s/?#]*@~i';

    /**
     * Each value in a URL's query string: the first after `scheme://...?`, each later one
     * where the previous match ended (`\G`). `\K` keeps the name out of the match.
     */
    private const string URL_QUERY_VALUE = '~(?:\b[a-z][a-z0-9+.\-]*://[^\s?#]*\?|(?!^)\G)(?:[^\s#=&;]*[&;])*[^\s#=&;]*=\K[^\s#&;]*~i';

    private const string SECRET_PAIR = '~\b(api[_-]?key|access[_-]?token|client[_-]?secret|token|secret|password|passwd)=[^\s&#]*~i';

    private const string BEARER = '~\b(bearer\s+)[^\s"\',;]+~i';

    /** @var list<string> longest first, so a secret that contains another is masked whole */
    private array $secrets;

    public function __construct(string ...$secrets)
    {
        $variants = [];

        foreach ($secrets as $secret) {
            // str_replace() skips an empty search string, and a duplicate is masked twice harmlessly.
            $variants = [...$variants, $secret, rawurlencode($secret), urlencode($secret)];
        }

        usort($variants, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->secrets = $variants;
    }

    /** A redactor for one connection: its API key, and the user and password in its URL. */
    public static function forConnection(string $url, ?string $apiKey): self
    {
        $userinfo = array_filter([parse_url($url, PHP_URL_USER), parse_url($url, PHP_URL_PASS)], is_string(...));

        // As written in the URL, and decoded, as an HTTP client would send it.
        return new self(...$userinfo, ...array_map(rawurldecode(...), $userinfo), ...[$apiKey ?? '']);
    }

    /** The text with every credential masked; all of it masked if a pattern fails to run. */
    public function redact(string $text): string
    {
        return preg_replace(
            [self::USERINFO, self::URL_QUERY_VALUE, self::SECRET_PAIR, self::BEARER],
            ['$1'.self::MASK.'@', self::MASK, '$1='.self::MASK, '$1'.self::MASK],
            str_replace($this->secrets, self::MASK, $text),
        ) ?? self::MASK;
    }
}
