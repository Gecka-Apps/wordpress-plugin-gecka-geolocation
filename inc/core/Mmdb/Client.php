<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Mmdb;

use Gecka\WP\Plugin\Geolocation\Plugin;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * Requests to the download service of a provider, through the HTTP API of
 * WordPress.
 *
 * Redirections are followed here, one by one, and the provider names the
 * headers of each hop: its credentials go to its own host only, not to the
 * storage a download is redirected to. A HEAD request tells the date of the
 * latest release, in Last-Modified.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Client
{
    /**
     * Seconds a HEAD request or the download of a checksum waits at most.
     */
    public const SHORT_TIMEOUT = 15;

    /**
     * Seconds the download of a database waits at most.
     */
    public const TIMEOUT = 300;

    /**
     * Bytes a download may weigh at most.
     */
    public const MAX_BYTES = 2147483648;

    /**
     * Redirections followed at most.
     */
    private const REDIRECTS = 5;

    /**
     * @var Provider
     */
    private Provider $provider;

    /**
     * @param Provider $provider
     */
    public function __construct(Provider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Status of an address and the date of what it serves
     *
     * @param string $url
     *
     * @return array{status: int, modified: int} modified is 0 when unknown.
     *
     * @throws \RuntimeException When the request fails.
     */
    public function head(string $url): array
    {
        $response = $this->request('HEAD', $url, ['timeout' => self::SHORT_TIMEOUT]);

        return ['status' => Value::int(wp_remote_retrieve_response_code($response)), 'modified' => self::modified($response)];
    }

    /**
     * Status and body of a small answer
     *
     * @param string $url
     *
     * @return array{status: int, body: string}
     *
     * @throws \RuntimeException When the request fails.
     */
    public function fetch(string $url): array
    {
        $response = $this->request('GET', $url, ['timeout' => self::SHORT_TIMEOUT, 'limit_response_size' => 65536]);

        return ['status' => Value::int(wp_remote_retrieve_response_code($response)), 'body' => wp_remote_retrieve_body($response)];
    }

    /**
     * Body of a small answer, as a checksum
     *
     * @param string $url
     * @param string $edition The edition it concerns, for the error.
     *
     * @return string
     *
     * @throws \RuntimeException When it cannot be downloaded.
     */
    public function get(string $url, string $edition): string
    {
        $answer = $this->fetch($url);

        if ($answer['status'] !== 200) {
            throw new \RuntimeException(esc_html($this->provider->message($answer['status'], $edition)), (int) $answer['status']);
        }

        return $answer['body'];
    }

    /**
     * Downloads a file.
     *
     * @param string $url
     * @param string $file
     * @param string $edition The edition it concerns, for the error.
     *
     * @return int Date of the release, from Last-Modified; 0 when unknown.
     *
     * @throws \RuntimeException When it cannot be downloaded.
     */
    public function download(string $url, string $file, string $edition): int
    {
        $response = $this->request('GET', $url, ['timeout' => self::TIMEOUT, 'stream' => true, 'filename' => $file, 'limit_response_size' => self::MAX_BYTES]);
        $status = Value::int(wp_remote_retrieve_response_code($response));

        if ($status !== 200) {
            throw new \RuntimeException(esc_html($this->provider->message($status, $edition)), (int) $status);
        }

        return self::modified($response);
    }

    /**
     * Sends a request and follows its redirections, each hop over HTTPS and
     * with the headers the provider gives it
     *
     * @param string                                                                        $method
     * @param string                                                                        $url
     * @param array{timeout: float, stream?: bool, filename?: string, limit_response_size?: int} $args
     *
     * @return array<string, mixed> The last response.
     *
     * @throws \RuntimeException When a request fails, or a hop leaves HTTPS.
     */
    private function request(string $method, string $url, array $args): array
    {
        $deadline = microtime(true) + $args['timeout'];
        $redirects = 0;

        for (;;) {
            if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
                /* translators: %s: name of a provider */
                throw new \RuntimeException(esc_html(sprintf(__('%s sent the download over an insecure address.', 'gecka-geolocation'), $this->provider->label())));
            }

            $left = $deadline - microtime(true);
            $response = $left > 0 ? wp_safe_remote_request(
                $url,
                array_merge(
                    $args,
                    [
                        'method' => $method,
                        'timeout' => $left,
                        'redirection' => 0,
                        'headers' => $this->provider->headers($url),
                        'user-agent' => 'Gecka-Geolocation/' . Plugin::VERSION,
                    ],
                ),
            ) : new \WP_Error('http_request_failed', __('Operation timed out.', 'gecka-geolocation'));

            if (is_wp_error($response)) {
                /* translators: 1: name of a provider, 2: error */
                throw new \RuntimeException(esc_html(sprintf(__('Cannot reach %1$s: %2$s', 'gecka-geolocation'), $this->provider->label(), $response->get_error_message())));
            }

            $status = Value::int(wp_remote_retrieve_response_code($response));
            $location = wp_remote_retrieve_header($response, 'location');

            if ($status < 300 || $status >= 400 || ! is_string($location) || $location === '') {
                return $response;
            }

            if ($redirects++ >= self::REDIRECTS) {
                /* translators: %s: name of a provider */
                throw new \RuntimeException(esc_html(sprintf(__('%s redirected the download too many times.', 'gecka-geolocation'), $this->provider->label())));
            }

            $url = \WP_Http::make_absolute_url($location, $url);
        }
    }

    /**
     * Date of a response, from Last-Modified
     *
     * @param array<string, mixed> $response
     *
     * @return int
     */
    private static function modified(array $response): int
    {
        $header = wp_remote_retrieve_header($response, 'last-modified');
        $time = is_string($header) && $header !== '' ? strtotime($header) : false;

        return $time === false ? 0 : $time;
    }
}
