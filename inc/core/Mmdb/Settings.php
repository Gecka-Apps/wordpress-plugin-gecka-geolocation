<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Mmdb;

use Gecka\WP\Plugin\Geolocation\Setup;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * The credentials of a provider and the databases to keep, held in one
 * network option: on a multisite every site shares them.
 *
 * Each credential can live in wp-config.php instead, as a constant named
 * after the provider and the field, as GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY,
 * which then wins over what the option holds.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Settings
{
    /**
     * Settings read, kept for the request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $settings = null;

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
     * Network option holding the settings
     *
     * @return string As gecka_geolocation_maxmind.
     */
    public function option(): string
    {
        return 'gecka_geolocation_' . $this->provider->id();
    }

    /**
     * Every setting, the defaults filling the gaps: each credential, and the
     * editions under editions
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $saved = get_site_option($this->option(), []);
        $saved = is_array($saved) ? $saved : [];

        return $this->settings = $this->sanitize($saved + ['editions' => $this->provider->defaults()]);
    }

    /**
     * Forgets the settings read, once they were saved.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->settings = null;
    }

    /**
     * Reads the settings again from the database, past the cache of the
     * request, as a long update checks what another request saved.
     *
     * @return void
     */
    public function reload(): void
    {
        Setup::uncache($this->option());
        $this->flush();
    }

    /**
     * Saves settings, those not given keeping their value.
     *
     * @param array<string, mixed> $values
     *
     * @return void
     */
    public function save(array $values): void
    {
        update_site_option($this->option(), $this->sanitize(array_merge($this->all(), $values)));
        $this->flush();
    }

    /**
     * A credential, from wp-config.php or the settings
     *
     * @param string $field
     *
     * @return string
     */
    public function value(string $field): string
    {
        return $this->fromConstant($field)
            ? $this->clean($field, constant($this->provider->constant($field)))
            : Value::string($this->all()[$field] ?? '');
    }

    /**
     * Whether wp-config.php holds a credential
     *
     * @param string $field
     *
     * @return bool
     */
    public function fromConstant(string $field): bool
    {
        return defined($this->provider->constant($field));
    }

    /**
     * Whether wp-config.php holds at least one of the credentials
     *
     * @return bool
     */
    public function fromConstants(): bool
    {
        foreach (array_keys($this->provider->fields()) as $field) {
            if ($this->fromConstant($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every credential the provider asks for is set; true for a
     * provider asking none
     *
     * @return bool
     */
    public function hasCredentials(): bool
    {
        foreach (array_keys($this->provider->fields()) as $field) {
            if ($this->value($field) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the provider is to download anything: its credentials are
     * set and an edition is chosen
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->hasCredentials() && $this->wanted() !== [];
    }

    /**
     * The editions to download and keep up to date
     *
     * @return string[]
     */
    public function wanted(): array
    {
        return Value::strings($this->all()['editions'] ?? null);
    }

    /**
     * Cleans settings before they are saved.
     *
     * @param mixed $input
     *
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $clean = [];

        foreach (array_keys($this->provider->fields()) as $field) {
            $clean[$field] = $this->clean($field, $input[$field] ?? '');
        }

        $clean['editions'] = $this->editions($input['editions'] ?? []);

        return $clean;
    }

    /**
     * A credential matching the pattern of its field, empty otherwise
     *
     * @param string $field
     * @param mixed  $value
     *
     * @return string
     */
    public function clean(string $field, mixed $value): string
    {
        $value = trim(Value::string($value));
        $pattern = $this->provider->fields()[$field]['pattern'] ?? null;

        return $pattern !== null && preg_match($pattern, $value) ? $value : '';
    }

    /**
     * The editions of the provider in a list, each once, in the order the
     * provider gives them
     *
     * @param mixed $value
     *
     * @return string[]
     */
    public function editions(mixed $value): array
    {
        $chosen = Value::strings($value);

        return array_values(array_filter(array_keys($this->provider->editions()), static fn(string $edition): bool => in_array($edition, $chosen, true)));
    }
}
