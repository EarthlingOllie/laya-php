<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;

/**
 * The seam between the client and the wire. HttpTransport is the only real one; the
 * interface exists so the testing fake can stand in for HTTP.
 *
 * A transport returns whatever status it got (4xx and 5xx included) and throws only when
 * nothing came back at all.
 */
interface Transport
{
    /**
     * @param  array<string, mixed>  $body  sent as JSON
     *
     * @throws HostUnavailable when the server cannot be reached
     */
    public function post(string $path, array $body): Response;

    /**
     * A GET with no body, such as the server's `/health`.
     *
     * @throws HostUnavailable when the server cannot be reached
     */
    public function get(string $path): Response;
}
