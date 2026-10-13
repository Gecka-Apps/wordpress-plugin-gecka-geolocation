<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Admin;

use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;
use Gecka\WP\Plugin\Geolocation\Plugin;
use Gecka\WP\Plugin\Geolocation\Record;
use Gecka\WP\Plugin\Geolocation\Request;
use Gecka\WP\Plugin\Geolocation\Setup;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * The tab of the databases: for each provider, the databases wanted or
 * installed with their release and their state, and the button updating
 * them; then a lookup to try a provider on an address.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class DatabasesView
{
    /**
     * @var Plugin
     */
    private Plugin $plugin;

    /**
     * @param Plugin $plugin
     */
    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Prints the tab.
     *
     * @return void
     */
    public function render(): void
    {
        $this->notices();

        foreach ($this->plugin->bundled() as $provider) {
            $this->provider($provider);
        }

        $this->lookup();
    }

    /**
     * Prints the notices the updates leave.
     *
     * @return void
     */
    private function notices(): void
    {
        $updated = sanitize_key(Request::query('updated'));

        $notices = [
            '1' => ['success', __('The databases are up to date.', 'gecka-geolocation')],
            'failed' => ['error', __('Some databases could not be updated: the table tells why. The daily task tries again.', 'gecka-geolocation')],
            'busy' => ['warning', __('Another request is updating the databases. Try again in a minute.', 'gecka-geolocation')],
            'queued' => ['info', __('The remaining databases download in the background. Reload the page in a minute.', 'gecka-geolocation')],
        ];

        if (isset($notices[$updated])) {
            wp_admin_notice($notices[$updated][1], ['type' => $notices[$updated][0], 'dismissible' => true]);
        }
    }

    /**
     * Prints the section of a provider.
     *
     * @param MmdbProvider $provider
     *
     * @return void
     */
    private function provider(MmdbProvider $provider): void
    {
        $settings = $provider->settings();
        $updater = $provider->updater();
        $state = $updater->state();
        $installed = $provider->databases()->installed();
        $editions = array_values(array_intersect(array_keys($provider->editions()), array_merge($settings->wanted(), $installed)));

        printf('<h2>%s</h2>', esc_html($provider->label()));

        if ($editions === []) {
            printf(
                '<p>%s</p>',
                wp_kses(
                    /* translators: 1: address of the tab of the provider, 2: name of the provider */
                    sprintf(__('No database is chosen. Choose them in the <a href="%1$s">%2$s</a> tab.', 'gecka-geolocation'), esc_url(SettingsPage::url($provider->id())), esc_html($provider->label())),
                    ['a' => ['href' => []]],
                ),
            );

            return;
        }

        if (! $settings->hasCredentials()) {
            $message = wp_kses(
                /* translators: 1: name of the provider, 2: address of its tab */
                sprintf(__('Enter the credentials of your %1$s account in the <a href="%2$s">%1$s</a> tab.', 'gecka-geolocation'), esc_html($provider->label()), esc_url(SettingsPage::url($provider->id()))),
                ['a' => ['href' => []]],
            );

            if ($installed === []) {
                printf('<p>%s</p>', $message); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

                return;
            }

            wp_admin_notice($message, ['type' => 'warning', 'additional_classes' => ['inline']]);
        } elseif ($state['error'] !== '') {
            wp_admin_notice(esc_html($state['error']), ['type' => 'error', 'additional_classes' => ['inline']]);
        }

        $stale = $updater->stale();

        if ($stale) {
            wp_admin_notice(Notices::staleMessage($provider, $stale), ['type' => 'error', 'additional_classes' => ['inline']]);
        }

        $this->table($provider, $editions);

        if ($settings->configured()) {
            $this->updateButton($provider);
        }
    }

    /**
     * Prints the databases of a provider wanted or installed.
     *
     * @param MmdbProvider $provider
     * @param string[]     $editions
     *
     * @return void
     */
    private function table(MmdbProvider $provider, array $editions): void
    {
        $updater = $provider->updater();
        $databases = $provider->databases();
        $state = $updater->state();
        $installed = $databases->installed();

        printf(
            '<table class="widefat striped gecka-geolocation-databases"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>',
            esc_html__('Database', 'gecka-geolocation'),
            esc_html__('Content', 'gecka-geolocation'),
            esc_html__('Release', 'gecka-geolocation'),
            esc_html__('Size', 'gecka-geolocation'),
            esc_html__('State', 'gecka-geolocation'),
        );

        foreach ($editions as $edition) {
            $entry = $state['editions'][$edition] ?? null;
            $present = in_array($edition, $installed, true);
            $release = $entry && $entry['installed'] ? $entry['installed'] : ($present ? ($databases->metadata($edition)['built'] ?? 0) : 0);

            printf(
                '<tr><td><strong>%s</strong><br><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($provider->editionLabel($edition)),
                esc_html($edition),
                esc_html($provider->description($edition)),
                esc_html($release ? (string) wp_date(Value::string(get_option('date_format')), $release) : '—'),
                esc_html($present ? (string) size_format((int) filesize($databases->path($edition)), 1) : '—'),
                wp_kses($this->status($provider, $edition, $present, $entry), ['span' => ['class' => []], 'br' => []]),
            );
        }

        echo '</tbody></table>';

        $event = $updater->event();
        $next = Value::int(Setup::onMainSite(static fn(): int|false => wp_next_scheduled($event)));

        printf(
            '<p class="description">%s %s</p>',
            esc_html(
                $state['checked']
                    /* translators: 1: name of a provider, 2: time since, as 3 hours */
                    ? sprintf(__('Last checked with %1$s %2$s ago.', 'gecka-geolocation'), $provider->label(), human_time_diff($state['checked']))
                    /* translators: %s: name of a provider */
                    : sprintf(__('Never checked with %s yet.', 'gecka-geolocation'), $provider->label()),
            ),
            esc_html(
                $next && $provider->settings()->configured()
                    /* translators: %s: date and time */
                    ? sprintf(__('Next check: %s.', 'gecka-geolocation'), wp_date(Value::string(get_option('date_format')) . ' ' . Value::string(get_option('time_format')), $next))
                    : '',
            ),
        );
    }

    /**
     * State of a database, as HTML
     *
     * @param MmdbProvider                                                                                                                       $provider
     * @param string                                                                                                                             $edition
     * @param bool                                                                                                                               $present
     * @param array{installed: int, remote: int, release: string, installed_release: string, downloaded: int, bytes: int, error: string}|null $entry
     *
     * @return string
     */
    private function status(MmdbProvider $provider, string $edition, bool $present, ?array $entry): string
    {
        $updater = $provider->updater();
        $databases = $provider->databases();
        $wanted = in_array($edition, $provider->settings()->wanted(), true);
        $error = $entry['error'] ?? '';
        $outdated = $updater->outdated($edition);
        $readError = $present && $databases->metadata($edition) === null ? $databases->error($edition) : null;

        $status = match (true) {
            $readError !== null => self::badge('no', __('Unreadable', 'gecka-geolocation')) . '<br>' . esc_html($readError),
            ! $wanted => self::badge('no', __('No longer wanted, deleted at the next check', 'gecka-geolocation')),
            in_array($edition, $updater->stale(), true) => self::badge('no', __('Outdated past the licence limit', 'gecka-geolocation')),
            $present && $outdated > 0 => self::badge('wait', sprintf(/* translators: %s: date */ __('Release of %s to download', 'gecka-geolocation'), wp_date(Value::string(get_option('date_format')), time() - $outdated))),
            $present => self::badge('yes', __('Up to date', 'gecka-geolocation')),
            in_array($edition, $updater->state()['pending'], true) => self::badge('wait', __('Queued', 'gecka-geolocation')),
            default => self::badge('wait', __('Not downloaded yet', 'gecka-geolocation')),
        };

        return $error !== '' ? $status . '<br>' . esc_html($error) : $status;
    }

    /**
     * A small coloured label, as HTML
     *
     * @param string $kind yes, no or wait.
     * @param string $text
     *
     * @return string
     */
    private static function badge(string $kind, string $text): string
    {
        return sprintf('<span class="gecka-geolocation-badge gecka-geolocation-badge-%s">%s</span>', esc_attr($kind), esc_html($text));
    }

    /**
     * Prints the button updating the databases of a provider. With
     * JavaScript, it runs the steps through the REST API and tells the
     * progress; without, it posts the form.
     *
     * @param MmdbProvider $provider
     *
     * @return void
     */
    private function updateButton(MmdbProvider $provider): void
    {
        $id = $provider->id();
        $attributes = [
            'check' => rest_url(SettingsPage::REST_NAMESPACE . '/' . $id . '/update/check'),
            'next' => rest_url(SettingsPage::REST_NAMESPACE . '/' . $id . '/update/next'),
            'nonce' => wp_create_nonce('wp_rest'),
            'done' => SettingsPage::url('databases'),
            /* translators: %s: name of a provider */
            'checking' => sprintf(__('Asking %s for the latest releases…', 'gecka-geolocation'), $provider->label()),
            /* translators: 1: name of a database, 2: its rank, 3: number of databases to download */
            'progress' => __('Downloading %1$s (%2$s of %3$s)…', 'gecka-geolocation'),
            'busy' => __('Another request is updating the databases, waiting for it…', 'gecka-geolocation'),
            'error' => __('The update was interrupted. Try again, or let the daily task do it.', 'gecka-geolocation'),
        ];

        if ($provider->updater()->takeQueued()) {
            $attributes['autostart'] = '1';
        }

        echo '<div class="gecka-geolocation-update"';

        foreach ($attributes as $key => $value) {
            printf(' data-%s="%s"', esc_attr($key), esc_attr($value));
        }

        echo '>';
        printf('<form method="post" action="%s" class="gecka-admin-buttons">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="gecka_geolocation_update">';
        printf('<input type="hidden" name="provider" value="%s">', esc_attr($id));
        wp_nonce_field('gecka_geolocation_update_' . $id);
        printf(
            '<button type="submit" class="button button-primary">%s</button>',
            /* translators: %s: name of a provider */
            esc_html(sprintf(__('Update %s now', 'gecka-geolocation'), $provider->label())),
        );
        echo '</form>';
        echo '<progress hidden></progress> <span class="gecka-geolocation-status" role="status" aria-live="polite"></span>';
        echo '</div>';
    }

    /**
     * Prints the form trying a provider on an address, what it knows of it,
     * and the credit its licence asks for.
     *
     * @return void
     */
    private function lookup(): void
    {
        $ip = isset($_GET['ip']) ? trim(Request::query('ip')) : $this->plugin->clientIp(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads.
        $providers = $this->plugin->providers();
        $chosen = sanitize_key(Request::query('provider'));
        $chosen = isset($providers[$chosen]) ? $chosen : $this->plugin->defaultProvider();

        printf('<h2 id="gecka-geolocation-lookup">%s</h2>', esc_html__('Try an address', 'gecka-geolocation'));
        printf('<form method="get" action="%s#gecka-geolocation-lookup" class="gecka-geolocation-lookup">', esc_url(SettingsPage::url('databases')));

        foreach (['page' => SettingsPage::PAGE, 'tab' => 'databases'] as $key => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr($value));
        }

        printf(
            '<label for="gecka-geolocation-ip" class="screen-reader-text">%s</label><input type="text" id="gecka-geolocation-ip" name="ip" value="%s" class="regular-text code" spellcheck="false"> ',
            esc_html__('IP address', 'gecka-geolocation'),
            esc_attr($ip),
        );
        printf('<label for="gecka-geolocation-lookup-provider" class="screen-reader-text">%s</label><select id="gecka-geolocation-lookup-provider" name="provider">', esc_html__('Provider', 'gecka-geolocation'));

        foreach ($providers as $id => $provider) {
            printf('<option value="%s" %s>%s</option>', esc_attr($id), selected($chosen, $id, false), esc_html($provider->label()));
        }

        echo '</select> ';
        submit_button(__('Locate', 'gecka-geolocation'), 'secondary', '', false);
        echo '</form>';

        if ($ip === '') {
            return;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            printf('<p>%s</p>', esc_html__('This is not an IP address.', 'gecka-geolocation'));

            return;
        }

        $record = $this->plugin->lookup($ip, $chosen);
        $rows = $record ? $this->rows($record) : [];

        if ($record === null || $rows === []) {
            printf('<p>%s</p>', esc_html($record ? __('The databases know nothing of this address: a private or reserved one, or one they do not cover.', 'gecka-geolocation') : __('This provider has no database installed yet.', 'gecka-geolocation')));

            return;
        }

        echo '<table class="widefat striped gecka-geolocation-result"><tbody>';

        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html($value));
        }

        $emoji = $record->flag();

        if ($emoji !== null) {
            printf('<tr><th scope="row">%s</th><td class="gecka-geolocation-emoji">%s</td></tr>', esc_html__('Flag emoji', 'gecka-geolocation'), esc_html($emoji));
        }

        foreach (['4x3' => __('SVG flag 4:3', 'gecka-geolocation'), '1x1' => __('SVG flag 1:1', 'gecka-geolocation')] as $ratio => $label) {
            $url = $record->flagUrl($ratio);

            if ($url !== null) {
                printf('<tr><th scope="row">%s</th><td><img src="%s" alt="" class="gecka-geolocation-flag"></td></tr>', esc_html($label), esc_url($url));
            }
        }

        $logo = $record->networkLogoUrl();

        if ($logo !== null) {
            printf('<tr><th scope="row">%s</th><td><img src="%s" alt="" class="gecka-geolocation-logo"></td></tr>', esc_html__('Network logo', 'gecka-geolocation'), esc_url($logo));
        }

        echo '</tbody></table>';

        $credit = $record->credit();

        if ($credit !== null) {
            printf(
                '<p class="description gecka-geolocation-attribution">%s</p>',
                $credit['url'] !== null ? sprintf('<a href="%s">%s</a>', esc_url($credit['url']), esc_html($credit['text'])) : esc_html($credit['text']),
            );
        }
    }

    /**
     * What a record knows, by label
     *
     * @param Record $record
     *
     * @return array<string, string>
     */
    private function rows(Record $record): array
    {
        $place = static fn(?string $name, ?string $code): ?string => $name !== null ? ($code !== null ? "$name ($code)" : $name) : $code;
        $subdivisions = array_map(static fn(array $subdivision): string => (string) $place($subdivision['name'], $subdivision['code']), $record->subdivisions());
        $anonymous = $record->anonymous();
        $latitude = $record->latitude();
        $longitude = $record->longitude();
        $source = $record->source(Facet::COUNTRY) ?? $record->source(Facet::ASN);
        $provider = $this->plugin->mmdb($record->provider());
        $network = $record->network(Facet::COUNTRY) ?? $record->network(Facet::ASN);

        $rows = [
            __('Country', 'gecka-geolocation') => $place($record->countryName(), $record->country()),
            __('Continent', 'gecka-geolocation') => $place($record->continentName(), $record->continent()),
            __('European Union', 'gecka-geolocation') => $record->inEuropeanUnion() === null ? null : ($record->inEuropeanUnion() ? __('Yes', 'gecka-geolocation') : __('No', 'gecka-geolocation')),
            __('Registered in', 'gecka-geolocation') => $record->registeredCountry() !== $record->country() ? $record->registeredCountry() : null,
            __('Subdivisions', 'gecka-geolocation') => $subdivisions ? implode(', ', $subdivisions) : null,
            __('City', 'gecka-geolocation') => $record->city(),
            __('Postal code', 'gecka-geolocation') => $record->postalCode(),
            /* translators: 1: latitude, 2: longitude, 3: radius in kilometres */
            __('Coordinates', 'gecka-geolocation') => $latitude !== null && $longitude !== null ? ($record->accuracyRadius() !== null ? sprintf(__('%1$s, %2$s, within %3$d km', 'gecka-geolocation'), $latitude, $longitude, $record->accuracyRadius()) : $latitude . ', ' . $longitude) : null,
            __('Time zone', 'gecka-geolocation') => $record->timeZone(),
            __('Autonomous system', 'gecka-geolocation') => $record->asn() !== null ? trim('AS' . $record->asn() . ' ' . $record->asOrganization() . ($record->asDomain() !== null ? ' (' . $record->asDomain() . ')' : '')) : null,
            __('ISP', 'gecka-geolocation') => $record->isp(),
            __('Organisation', 'gecka-geolocation') => $record->organization(),
            __('Connection', 'gecka-geolocation') => $record->connectionType(),
            __('Domain', 'gecka-geolocation') => $record->domain(),
            __('Anonymity', 'gecka-geolocation') => $anonymous === null ? null : (implode(', ', array_keys(array_filter($anonymous))) ?: __('None known', 'gecka-geolocation')),
            __('Network', 'gecka-geolocation') => $network !== null ? $network . ($source !== null ? ' (' . ($provider !== null ? $provider->editionLabel($source) : $source) . ')' : '') : null,
        ];

        return array_map('strval', array_filter($rows, static fn($value): bool => $value !== null && $value !== ''));
    }
}
