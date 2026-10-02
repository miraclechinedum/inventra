<?php

namespace App\Support\WhatsApp;

use RuntimeException;

/**
 * The one Graph API version every WhatsApp call — server and browser SDK alike — uses.
 *
 * Read from `whatsapp.graph_version` and validated rather than trusted: a malformed value would
 * otherwise become part of every request path. There is no fallback to a built-in version. The
 * repository default is not proof of production support; set WHATSAPP_GRAPH_VERSION to a version
 * confirmed current on Meta's changelog before activation.
 */
final class GraphVersion
{
    public static function configured(): string
    {
        $version = config('whatsapp.graph_version');

        if (! self::isValid($version)) {
            throw new RuntimeException('WHATSAPP_GRAPH_VERSION must look like "v26.0".');
        }

        return $version;
    }

    public static function isValid(mixed $version): bool
    {
        return is_string($version) && preg_match('/^v[1-9]\d{0,2}\.\d{1,2}$/', $version) === 1;
    }
}
