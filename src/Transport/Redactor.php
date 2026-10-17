<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use JsonException;
use SensitiveParameter;
use stdClass;

use function array_filter;
use function array_keys;
use function array_map;
use function get_object_vars;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
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

    /** What redactBody() masks in what a server echoes: URL credentials. */
    private const array URL_PATTERNS = [self::USERINFO => '$1'.self::MASK.'@', self::URL_QUERY_VALUE => self::MASK];

    /** What redact() masks in the HTTP client's own text: URL credentials, secret pairs, bearer tokens. */
    private const array PATTERNS = [...self::URL_PATTERNS, self::SECRET_PAIR => '$1='.self::MASK, self::BEARER => '$1'.self::MASK];

    /** @var list<string> longest first, so a secret that contains another is masked whole */
    private array $secrets;

    public function __construct(#[SensitiveParameter] string ...$secrets)
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
    public static function forConnection(string $url, #[SensitiveParameter] ?string $apiKey): self
    {
        $userinfo = array_filter([parse_url($url, PHP_URL_USER), parse_url($url, PHP_URL_PASS)], is_string(...));

        // As written in the URL, and decoded, as an HTTP client would send it.
        return new self(...$userinfo, ...array_map(rawurldecode(...), $userinfo), ...[$apiKey ?? '']);
    }

    /** The text with every credential masked; all of it masked if a pattern fails to run. */
    public function redact(string $text): string
    {
        return $this->mask($text, self::PATTERNS);
    }

    /**
     * A response body with what the request sent masked: the connection's secrets, and the
     * userinfo and query values of any URL the server echoes. The patterns for secret-looking
     * pairs and bearer tokens are left out here: they are meant for the HTTP client's own error
     * text, and in a server's message they mangle its wording ("invalid or missing bearer
     * token") while a key the server echoes is the connection's, masked anyway.
     *
     * A JSON body has each string in it masked and stays JSON with the same structure, so a
     * `detail` can still be read out of it (masking the raw text could swallow the closing
     * quote along with a URL's query value); anything else is masked as text.
     */
    public function redactBody(string $body): string
    {
        try {
            $decoded = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->redactEchoed($body);
        }

        return json_encode($this->redactStrings($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** The text with the secrets and URL credentials masked; all of it if a pattern fails to run. */
    private function redactEchoed(string $text): string
    {
        return $this->mask($text, self::URL_PATTERNS);
    }

    /**
     * The text with the secrets and then each pattern's matches replaced.
     *
     * @param  array<string, string>  $patterns  replacement by pattern
     */
    private function mask(string $text, array $patterns): string
    {
        return preg_replace(array_keys($patterns), $patterns, str_replace($this->secrets, self::MASK, $text)) ?? self::MASK;
    }

    private function redactStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactEchoed($value);
        }

        if (is_array($value)) {
            return array_map($this->redactStrings(...), $value);
        }

        if ($value instanceof stdClass) {
            return (object) array_map($this->redactStrings(...), get_object_vars($value));
        }

        return $value;
    }
}
