<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Transport\Redactor;

it('masks the userinfo of any URL in the text', function (string $text, string $redacted): void {
    expect((new Redactor)->redact($text))->toBe($redacted);
})->with([
    'user and password' => ['at http://user:s3cret@laya.test:8000/v1 now', 'at http://***@laya.test:8000/v1 now'],
    'token as user' => ['https://tok3n@laya.test', 'https://***@laya.test'],
    'unencoded @ in the password' => ['http://u:p@ss@laya.test/x', 'http://***@laya.test/x'],
    'already masked by Guzzle' => ['for http://***@laya.test/v1', 'for http://***@laya.test/v1'],
    'two URLs' => ['http://a:b@one.test and https://c:d@two.test', 'http://***@one.test and https://***@two.test'],
]);

it('masks every query value of a URL in the text, whatever the parameter is called', function (string $text, string $redacted): void {
    expect((new Redactor)->redact($text))->toBe($redacted);
})->with([
    'api_key' => ['http://laya.test/v1?api_key=q-secret', 'http://laya.test/v1?api_key=***'],
    'several, with a fragment' => ['http://laya.test/?a=1&sig=xyz#top', 'http://laya.test/?a=***&sig=***#top'],
    'followed by text, up to the next space' => ['for http://laya.test/?x-auth=abc refused', 'for http://laya.test/?x-auth=*** refused'],
    'a bare flag' => ['http://laya.test/?debug&token=t', 'http://laya.test/?debug&token=***'],
]);

it('masks secret-looking key=value pairs outside URLs', function (string $text, string $redacted): void {
    expect((new Redactor)->redact($text))->toBe($redacted);
})->with([
    ['sent api_key=abc123 to the host', 'sent api_key=*** to the host'],
    ['API-KEY=abc', 'API-KEY=***'],
    ['access_token=abc&x=1', 'access_token=***&x=1'],
    ['password=hunter2', 'password=***'],
]);

it('masks bearer tokens', function (): void {
    expect((new Redactor)->redact('Authorization: Bearer abc.def-123 sent'))->toBe('Authorization: Bearer *** sent')
        ->and((new Redactor)->redact('"bearer abc"'))->toBe('"bearer ***"');
});

it('masks the connection secrets wherever they appear, raw or URL-encoded', function (): void {
    $redactor = Redactor::forConnection('http://ollie:p%40ss word@laya.test', 'sk-live/key+1');

    expect($redactor->redact('key sk-live/key+1, encoded sk-live%2Fkey%2B1, user ollie, password p@ss word / p%40ss word / p%40ss%20word'))
        ->toBe('key ***, encoded ***, user ***, password *** / *** / ***');
});

it('ignores an empty key and a URL without userinfo', function (?string $key): void {
    $text = 'The model server at http://laya.test:8000 could not be reached: Connection refused';

    expect(Redactor::forConnection('http://laya.test:8000', $key)->redact($text))->toBe($text);
})->with([null, '']);

it('masks the longest secret first so a shorter one cannot split it', function (): void {
    expect((new Redactor('abc', 'abcdef'))->redact('x abcdef y abc'))->toBe('x *** y ***');
});

it('leaves text without secrets alone', function (): void {
    $text = 'cURL error 7: Failed to connect to 127.0.0.1 port 9 (see https://curl.se/libcurl/c/libcurl-errors.html) for http://127.0.0.1:9/v1/systemone';

    expect((new Redactor)->redact($text))->toBe($text);
});

it('masks the whole text when a pattern cannot run', function (): void {
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect((new Redactor)->redact('see http://user:secret@laya.test/?api_key=k'))->toBe('***');
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});
