<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * The settings of the plugin that go beyond a provider, held in one network
 * option: the provider asked when a lookup names none, WooCommerce, and the
 * logos of the networks.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Settings
{
    /**
     * Network option holding the settings.
     */
    public const OPTION = 'gecka_geolocation_settings';

    /**
     * Settings read, kept for the request.
     *
     * @var array{provider: string, woocommerce: bool, network_logos: bool}|null
     */
    private ?array $settings = null;

    /**
     * Default values
     *
     * @return array{provider: string, woocommerce: bool, network_logos: bool}
     */
    public static function defaults(): array
    {
        return [
            'provider' => '',
            'woocommerce' => false,
            'network_logos' => false,
        ];
    }

    /**
     * Every setting, the defaults filling the gaps
     *
     * @return array{provider: string, woocommerce: bool, network_logos: bool}
     */
    public function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $saved = get_site_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        return $this->settings = $this->sanitize(array_merge(self::defaults(), $saved));
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
     * Saves settings, those not given keeping their value.
     *
     * @param array<string, mixed> $values
     *
     * @return void
     */
    public function save(array $values): void
    {
        update_site_option(self::OPTION, $this->sanitize(array_merge($this->all(), $values)));
        $this->flush();
    }

    /**
     * Identifier of the provider chosen in the settings
     *
     * @return string Empty for the first one with data in place.
     */
    public function provider(): string
    {
        return $this->all()['provider'];
    }

    /**
     * Whether WooCommerce is to locate its customers
     *
     * @return bool
     */
    public function woocommerce(): bool
    {
        return $this->all()['woocommerce'];
    }

    /**
     * Whether the logos of the networks are downloaded and given out
     *
     * @return bool
     */
    public function networkLogos(): bool
    {
        return $this->all()['network_logos'];
    }

    /**
     * Cleans settings before they are saved.
     *
     * @param mixed $input
     *
     * @return array{provider: string, woocommerce: bool, network_logos: bool}
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            'provider' => sanitize_key(Value::string($input['provider'] ?? null)),
            'woocommerce' => ! empty($input['woocommerce']),
            'network_logos' => ! empty($input['network_logos']),
        ];
    }
}
