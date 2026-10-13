<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * Reads the variables of the request as plain text.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
final class Request
{
    /**
     * A variable of the query string as plain text, empty when missing.
     * Unchecked by a nonce: callers read a choice of display with it, or
     * check the nonce of their action first.
     *
     * @param string $key Name of the variable.
     *
     * @return string
     */
    public static function query(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See the description.
        return isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : '';
    }

    /**
     * A server variable as plain text, empty when missing
     *
     * @param string $key Name of the variable, as REMOTE_ADDR.
     *
     * @return string
     */
    public static function server(string $key): string
    {
        return isset($_SERVER[$key]) && is_scalar($_SERVER[$key]) ? sanitize_text_field(wp_unslash((string) $_SERVER[$key])) : '';
    }
}
