<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Model\ModelProfile;
use EarthlingOllie\SystemOneClassification\Transport\RetryPolicy;
use SensitiveParameter;
use SensitiveParameterValue;

use function is_string;

/**
 * One connection of config/system-one.php, checked: what SystemOneManager::configuration()
 * returns and connection() builds a client from.
 *
 * The API key is held as a SensitiveParameterValue, as HttpTransport holds it, so dumping,
 * exporting or JSON-encoding the config never shows it; apiKey() is the one way to read it.
 */
final readonly class ConnectionConfig
{
    private ?SensitiveParameterValue $apiKey;

    /**
     * @param  ?string  $apiKey  null when the connection has none
     * @param  ?string  $model  sent when a decision pins none; null lets the server route
     * @param  bool  $remote  a host off this machine: used only with an API key, and over https
     */
    public function __construct(
        public string $name,
        public string $url,
        #[SensitiveParameter] ?string $apiKey,
        public float $timeout,
        public ModelProfile $profile,
        public ?string $model,
        public RetryPolicy $retry,
        public bool $remote,
    ) {
        $this->apiKey = $apiKey === null ? null : new SensitiveParameterValue($apiKey);
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    public function apiKey(): ?string
    {
        $apiKey = $this->apiKey?->getValue();

        return is_string($apiKey) ? $apiKey : null;
    }
}
