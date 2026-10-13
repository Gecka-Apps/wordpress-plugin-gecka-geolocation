<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Admin;

use Gecka\WP\AdminMenu\Menus;
use Gecka\WP\Plugin\Geolocation\Base;
use Gecka\WP\Plugin\Geolocation\Integrations\WooCommerce;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;
use Gecka\WP\Plugin\Geolocation\Plugin;
use Gecka\WP\Plugin\Geolocation\Request;
use Gecka\WP\Plugin\Geolocation\Setup;
use Gecka\WP\Plugin\Geolocation\Storage;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * Settings > Geolocation, under the Settings menu of the network admin on a
 * multisite: the databases, their state and a lookup to try them out; a tab
 * per provider with its credentials and the databases to keep; the default
 * provider and WooCommerce; and the actions their buttons post.
 *
 * The forms post to admin-post.php, since options.php does not exist in
 * the network admin; the settings are network options.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class SettingsPage extends Base
{
    /**
     * Slug of the page.
     */
    public const PAGE = 'gecka-geolocation';

    /**
     * Name of the array the settings forms post.
     */
    public const FIELD = 'gecka_geolocation';

    /**
     * Namespace of the REST routes.
     */
    public const REST_NAMESPACE = 'gecka-geolocation/v1';

    /**
     * @return void
     */
    public function setup(): void
    {
        // On init and not before: the titles are translated, and WordPress
        // loads a text domain from init on.
        add_action('init', [$this, 'declare']);
        add_action('admin_post_gecka_geolocation_save', [$this, 'save']);
        add_action('admin_post_gecka_geolocation_save_provider', [$this, 'saveProvider']);
        add_action('admin_post_gecka_geolocation_update', [$this, 'update']);
        add_action('admin_post_gecka_geolocation_detect', [$this, 'detect']);
        add_action('admin_post_gecka_geolocation_forget', [$this, 'forget']);
        add_filter((is_multisite() ? 'network_admin_' : '') . 'plugin_action_links_' . plugin_basename($this->plugin->file()), [$this, 'actionLinks']);
        add_action('admin_init', [$this, 'privacyPolicy']);
    }

    /**
     * Declares the page and its tabs to the shared admin menu.
     *
     * @return void
     */
    public function declare(): void
    {
        $page = Menus::options(self::PAGE)
            ->network(is_multisite())
            ->title(__('Geolocation', 'gecka-geolocation'))
            ->capability(Plugin::capability());

        $page->tab('databases')
            ->title(__('Databases', 'gecka-geolocation'))
            ->order(10)
            ->load(function (): void {
                $this->load('databases');
            })
            ->render(function (): void {
                $this->renderTab(function (): void {
                    (new DatabasesView($this->plugin))->render();
                });
            });

        $order = 20;

        foreach ($this->plugin->bundled() as $id => $provider) {
            $page->tab($id)
                ->title($provider->label())
                ->order($order++)
                ->load(function () use ($id): void {
                    $this->load($id);
                })
                ->render(function () use ($provider): void {
                    $this->renderTab(function () use ($provider): void {
                        $this->renderProvider($provider);
                    });
                });
        }

        $page->tab('settings')
            ->title(__('Settings', 'gecka-geolocation'))
            ->order(30)
            ->load(function (): void {
                $this->load('settings');
            })
            ->render(function (): void {
                $this->renderTab(function (): void {
                    $this->renderSettings();
                });
            });
    }

    /**
     * Url of a tab of the page
     *
     * @param string               $tab  databases, settings, or the identifier of a provider.
     * @param array<string, mixed> $args
     *
     * @return string
     */
    public static function url(string $tab = 'databases', array $args = []): string
    {
        return Menus::options(self::PAGE)->url(sanitize_key($tab), $args);
    }

    /**
     * Offers a paragraph for the privacy policy of the site.
     *
     * @return void
     */
    public function privacyPolicy(): void
    {
        wp_add_privacy_policy_content(
            __('Gecka Geolocation', 'gecka-geolocation'),
            wp_kses_post(wpautop(__('This site can tell the country, and sometimes the region or the city, of the IP address a visit comes from, with databases from MaxMind, DB-IP or IPinfo kept on its server. No address is sent to them or to anyone else for that, and Gecka Geolocation keeps none. The features of the site using that location decide what they keep of it.', 'gecka-geolocation'))),
        );
    }

    /**
     * Adds a link to the settings in the row of the plugin.
     *
     * @param array<string, string> $links
     *
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        return array_merge(
            ['settings' => sprintf('<a href="%s">%s</a>', esc_url(self::url('databases')), esc_html__('Settings', 'gecka-geolocation'))],
            $links,
        );
    }

    /**
     * Saves the settings beyond the providers: the default provider,
     * WooCommerce and the logos of the networks.
     *
     * @return void
     */
    public function save(): void
    {
        $this->authorize('gecka_geolocation_save');

        $input = isset($_POST[self::FIELD]) && is_array($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() checked the nonce; the settings class cleans every field.
        $general = ['network_logos' => ! empty($input['network_logos'])];

        if (WooCommerce::available()) {
            $general['woocommerce'] = ! empty($input['woocommerce']);
        }

        $provider = Value::string($input['provider'] ?? null);

        if (isset($input['provider']) && ($provider === '' || $this->plugin->provider($provider) !== null)) {
            $general['provider'] = $provider;
        }

        $this->plugin->settings()->save($general);

        wp_safe_redirect(self::url('settings', ['saved' => 1]));
        exit;
    }

    /**
     * Saves the settings of a provider, then checks what the credentials
     * give access to when they changed, and queues the downloads the change
     * calls for.
     *
     * @return void
     */
    public function saveProvider(): void
    {
        $provider = $this->postedProvider('gecka_geolocation_save_');
        $settings = $provider->settings();
        $fields = array_keys($provider->fields());
        $before = $settings->wanted();
        $credentials = array_map([$settings, 'value'], $fields);
        $input = isset($_POST[self::FIELD]) && is_array($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- postedProvider() checked the nonce; the settings class cleans every field.
        $values = ['editions' => Value::list($input['editions'] ?? null)];
        $args = ['saved' => 1];

        foreach ($provider->fields() as $field => $definition) {
            $posted = trim(Value::string($input[$field] ?? null));

            // A credential wp-config.php defines is not taken from the form;
            // a secret left empty keeps the one saved.
            if ($settings->fromConstant($field) || ($definition['secret'] && $posted === '')) {
                continue;
            }

            $values[$field] = $settings->clean($field, $posted);

            if ($posted !== '' && $values[$field] === '') {
                $args['invalid'] ??= $field;
                unset($values[$field]);
            }
        }

        $settings->save($values);
        $updater = $provider->updater();

        if ($fields !== [] && $credentials !== array_map([$settings, 'value'], $fields) && $settings->hasCredentials()) {
            $args['detected'] = $updater->detect(true) !== null ? 1 : 0;
        }

        $queued = $updater->settingsChanged($before);

        wp_safe_redirect(self::url($queued && empty($args['invalid']) ? 'databases' : $provider->id(), $args));
        exit;
    }

    /**
     * Downloads the databases of a provider missing or outdated now, for a
     * browser without JavaScript.
     *
     * @return void
     */
    public function update(): void
    {
        $provider = $this->postedProvider('gecka_geolocation_update_');
        $updater = $provider->updater();
        $done = $updater->update();
        $outcome = match (true) {
            $done === null => 'busy',
            $done => '1',
            $updater->state()['pending'] !== [] && $updater->errors() === [] => 'queued',
            default => 'failed',
        };

        wp_safe_redirect(self::url('databases', ['updated' => $outcome]));
        exit;
    }

    /**
     * Asks a provider which databases the credentials give access to.
     *
     * @return void
     */
    public function detect(): void
    {
        $provider = $this->postedProvider('gecka_geolocation_detect_');
        $detected = $provider->updater()->detect() !== null;

        wp_safe_redirect(self::url($provider->id(), ['detected' => $detected ? 1 : 0]));
        exit;
    }

    /**
     * Forgets the credentials the settings of a provider hold.
     *
     * @return void
     */
    public function forget(): void
    {
        $provider = $this->postedProvider('gecka_geolocation_forget_');
        $provider->settings()->save(array_fill_keys(array_keys($provider->fields()), ''));

        wp_safe_redirect(self::url($provider->id(), ['forgot' => 1]));
        exit;
    }

    /**
     * Registers the routes updating the databases of a provider, one step a
     * request.
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        foreach (['check' => 'checkRest', 'next' => 'nextRest'] as $route => $method) {
            register_rest_route(
                self::REST_NAMESPACE,
                '/(?P<provider>[a-z0-9_-]+)/update/' . $route,
                [
                    'methods' => \WP_REST_Server::CREATABLE,
                    'callback' => [$this, $method],
                    'permission_callback' => static fn(): bool => current_user_can(Plugin::capability()),
                ],
            );
        }
    }

    /**
     * Asks a provider for the latest releases and queues the databases to
     * download.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     *
     * @return \WP_REST_Response|\WP_Error The databases queued, and the error if any; busy while another request updates.
     */
    public function checkRest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $provider = $this->plugin->mmdb(Value::string($request->get_param('provider')));

        if ($provider === null) {
            return new \WP_Error('rest_no_route', __('Unknown provider.', 'gecka-geolocation'), ['status' => 404]);
        }

        $updater = $provider->updater();

        if (! $updater->lock()) {
            return new \WP_REST_Response(['busy' => true]);
        }

        try {
            $queued = $updater->check();
        } finally {
            $updater->unlock();
        }

        return new \WP_REST_Response(['pending' => $queued ?? [], 'error' => $queued === null ? $updater->state()['error'] : null, 'failed' => $updater->errors() !== []]);
    }

    /**
     * Downloads the next database of the queue of a provider.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     *
     * @return \WP_REST_Response|\WP_Error The database, its error if any, and those left; done when none was queued, busy while another request updates.
     */
    public function nextRest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $provider = $this->plugin->mmdb(Value::string($request->get_param('provider')));

        if ($provider === null) {
            return new \WP_Error('rest_no_route', __('Unknown provider.', 'gecka-geolocation'), ['status' => 404]);
        }

        $updater = $provider->updater();

        if (! $updater->lock()) {
            return new \WP_REST_Response(['busy' => true]);
        }

        try {
            $step = $updater->next();
        } finally {
            $updater->unlock();
        }

        return new \WP_REST_Response($step ?? ['done' => true, 'left' => 0]);
    }

    /**
     * Sets a tab up before anything is printed: the style sheet, the script
     * of the update buttons on the tab of the databases, and the script of
     * the secrets on the tab of a provider with an account.
     *
     * @param string $tab
     *
     * @return void
     */
    private function load(string $tab): void
    {
        add_action(
            'admin_enqueue_scripts',
            function () use ($tab): void {
                wp_enqueue_style('gecka-geolocation-admin', $this->plugin->url('assets/admin.css'), [], Plugin::VERSION);

                if ($tab === 'databases') {
                    wp_enqueue_script('gecka-geolocation-update', $this->plugin->url('assets/update.js'), [], Plugin::VERSION, ['in_footer' => true, 'strategy' => 'defer']);
                }

                if (($this->plugin->bundled()[$tab] ?? null)?->fields()) {
                    wp_enqueue_script('gecka-geolocation-secrets', $this->plugin->url('assets/secrets.js'), [], Plugin::VERSION, ['in_footer' => true, 'strategy' => 'defer']);
                }
            },
        );
    }

    /**
     * Prints a tab inside the block the style sheet of the plugin reads.
     *
     * @param callable $render
     *
     * @return void
     */
    private function renderTab(callable $render): void
    {
        echo '<div class="gecka-geolocation">';
        $render();
        echo '</div>';
    }

    /**
     * Prints the settings form of a provider.
     *
     * @param MmdbProvider $provider
     *
     * @return void
     */
    private function renderProvider(MmdbProvider $provider): void
    {
        $settings = $provider->settings();
        $state = $provider->updater()->state();
        $id = $provider->id();
        $name = self::FIELD;
        $fields = $provider->fields();

        $this->providerNotices($provider);

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="gecka_geolocation_save_provider">';
        printf('<input type="hidden" name="provider" value="%s">', esc_attr($id));
        wp_nonce_field('gecka_geolocation_save_' . $id);

        printf('<p>%s</p>', wp_kses($provider->intro(), ['a' => ['href' => []]]));

        if ($fields !== []) {
            /* translators: %s: name of a provider */
            printf('<h2>%s</h2>', esc_html(sprintf(__('%s account', 'gecka-geolocation'), $provider->label())));
            echo '<table class="form-table" role="presentation">';

            foreach (array_keys($fields) as $field) {
                $this->renderField($provider, $field);
            }

            echo '</table>';
        }

        printf('<h2>%s</h2>', esc_html__('Databases to download', 'gecka-geolocation'));
        printf('<p>%s</p>', esc_html__('Each database is checked every day and downloaded again when a new release is out. A database unticked is deleted.', 'gecka-geolocation'));
        echo '<table class="form-table" role="presentation">';

        $editions = array_keys($provider->editions());
        $free = array_values(array_filter($editions, [$provider, 'free']));
        $groups = count($free) === count($editions)
            ? [__('Free', 'gecka-geolocation') => $editions]
            : [__('Free', 'gecka-geolocation') => $free, __('Paid', 'gecka-geolocation') => array_values(array_diff($editions, $free))];

        foreach ($groups as $label => $list) {
            printf('<tr><th scope="row">%s</th><td><fieldset><legend class="screen-reader-text">%1$s</legend>', esc_html($label));

            foreach ($list as $edition) {
                $access = $fields !== [] ? ($state['access'][$edition] ?? null) : null;

                printf(
                    '<label class="gecka-admin-toggle-label gecka-geolocation-edition"><input type="checkbox" class="gecka-admin-toggle" name="%s[editions][]" value="%s" %s> <span><strong>%s</strong> %s<br><span class="description">%s</span></span></label>',
                    esc_attr($name),
                    esc_attr($edition),
                    checked(in_array($edition, $settings->wanted(), true), true, false),
                    esc_html($provider->editionLabel($edition)),
                    $access === null ? '' : sprintf('<span class="gecka-geolocation-badge gecka-geolocation-badge-%s">%s</span>', $access ? 'yes' : 'no', esc_html($access ? __('in your account', 'gecka-geolocation') : __('not in your account', 'gecka-geolocation'))),
                    esc_html($provider->description($edition)),
                );
            }

            echo '</fieldset></td></tr>';
        }

        echo '</table>';

        // The buttons of the other actions belong to the forms printed after
        // this one, through their form attribute, so that they share a row.
        echo '<p class="submit gecka-admin-buttons">';
        submit_button('', 'primary', 'submit', false);

        if ($fields !== [] && $settings->hasCredentials()) {
            printf(' <button type="submit" form="gecka-geolocation-detect" class="button">%s</button>', esc_html__('Check the account access', 'gecka-geolocation'));
        }

        $stored = array_filter(array_keys($fields), static fn(string $field): bool => ! $settings->fromConstant($field) && $settings->value($field) !== '');

        if ($stored !== []) {
            printf(
                ' <button type="submit" form="gecka-geolocation-forget" class="button" onclick="return confirm(%s)">%s</button>',
                esc_attr((string) wp_json_encode(__('Forget the credentials? The databases in place stay, but are no longer updated.', 'gecka-geolocation'))),
                esc_html__('Forget the credentials', 'gecka-geolocation'),
            );
        }

        echo '</p></form>';

        foreach (['gecka_geolocation_detect' => 'gecka-geolocation-detect', 'gecka_geolocation_forget' => 'gecka-geolocation-forget'] as $action => $form) {
            printf('<form method="post" action="%s" id="%s">', esc_url(admin_url('admin-post.php')), esc_attr($form));
            printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
            printf('<input type="hidden" name="provider" value="%s">', esc_attr($id));
            wp_nonce_field($action . '_' . $id);
            echo '</form>';
        }

        self::renderDirectory($id);
    }

    /**
     * Prints where the databases are kept.
     *
     * @param string $provider Identifier of a provider, empty for the directory itself.
     *
     * @return void
     */
    private static function renderDirectory(string $provider = ''): void
    {
        printf(
            '<p class="description gecka-geolocation-directory">%s</p>',
            wp_kses(
                /* translators: %s: directory on the server */
                sprintf(__('The databases are kept in %s.', 'gecka-geolocation'), '<code>' . esc_html(Storage::directory($provider)) . '</code>'),
                ['code' => []],
            ),
        );
    }

    /**
     * Prints the row of a credential of a provider.
     *
     * A credential wp-config.php defines shows in a disabled field, with the
     * name of the constant. A secret is typed in a text field the style sheet
     * masks, which the password managers leave alone, and a button in the
     * field reveals; the field never carries the secret saved.
     *
     * @param MmdbProvider $provider
     * @param string       $field
     *
     * @return void
     */
    private function renderField(MmdbProvider $provider, string $field): void
    {
        $settings = $provider->settings();
        $definition = $provider->fields()[$field];
        $secret = $definition['secret'];
        $constant = $settings->fromConstant($field);
        $value = $settings->value($field);
        $input = 'gecka-geolocation-' . $provider->id() . '-' . str_replace('_', '-', $field);
        $name = self::FIELD . '[' . $field . ']';

        if ($constant) {
            $description = wp_kses(
                sprintf(
                    $value !== ''
                        /* translators: 1: name of a constant, 2: file */
                        ? __('Defined by %1$s in %2$s.', 'gecka-geolocation')
                        /* translators: 1: name of a constant, 2: file */
                        : __('Defined by %1$s in %2$s, which holds no valid value.', 'gecka-geolocation'),
                    '<code>' . esc_html($provider->constant($field)) . '</code>',
                    '<code>wp-config.php</code>',
                ),
                ['code' => []],
            );
        } elseif (! $secret) {
            $description = '';
        } elseif ($value !== '') {
            $description = esc_html__('Saved. Leave the field empty to keep it.', 'gecka-geolocation');
        } else {
            /* translators: %s: names of constants */
            $description = esc_html(sprintf(__('Kept in the database of the site. To keep it out, define %s in wp-config.php.', 'gecka-geolocation'), implode(', ', array_map([$provider, 'constant'], array_keys($provider->fields())))));
        }

        $control = sprintf(
            '<input type="text" class="regular-text code%s" id="%s" name="%s" value="%s" placeholder="%s" autocomplete="off" autocapitalize="off" spellcheck="false"%s%s data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">',
            $secret ? ' gecka-geolocation-masked' : '',
            esc_attr($input),
            esc_attr($name),
            esc_attr($secret ? '' : $value),
            esc_attr($secret && $value !== '' ? '••••••••••••••••' : ''),
            $constant ? ' disabled' : '',
            $description !== '' ? sprintf(' aria-describedby="%s-description"', esc_attr($input)) : '',
        );

        if ($secret && ! $constant) {
            $control = sprintf(
                '<span class="gecka-geolocation-secret">%1$s<button type="button" class="button gecka-geolocation-reveal hide-if-no-js" aria-pressed="false" aria-controls="%2$s" aria-label="%3$s" data-show="%3$s" data-hide="%4$s"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button></span>',
                $control,
                esc_attr($input),
                esc_attr__('Show', 'gecka-geolocation'),
                esc_attr__('Hide', 'gecka-geolocation'),
            );
        }

        printf(
            '<tr><th scope="row"><label for="%s">%s</label></th><td>%s%s</td></tr>',
            esc_attr($input),
            esc_html($definition['label']),
            $control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above, each value escaped.
            $description !== '' ? sprintf('<p class="description" id="%s-description">%s</p>', esc_attr($input), $description) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
        );
    }

    /**
     * Prints the settings beyond the providers.
     *
     * @return void
     */
    private function renderSettings(): void
    {
        $name = self::FIELD;

        if (Request::query('saved') !== '') {
            wp_admin_notice(__('Settings saved.', 'gecka-geolocation'), ['type' => 'success', 'dismissible' => true]);
        }

        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="gecka_geolocation_save">';
        wp_nonce_field('gecka_geolocation_save');

        printf('<h2>%s</h2>', esc_html__('Provider', 'gecka-geolocation'));
        echo '<table class="form-table" role="presentation">';
        printf('<tr><th scope="row"><label for="gecka-geolocation-provider">%s</label></th><td><select id="gecka-geolocation-provider" name="%s[provider]">', esc_html__('Default provider', 'gecka-geolocation'), esc_attr($name));
        printf('<option value="" %s>%s</option>', selected($this->plugin->settings()->provider(), '', false), esc_html__('The first one with databases in place', 'gecka-geolocation'));

        foreach ($this->plugin->providers() as $id => $provider) {
            printf('<option value="%s" %s>%s</option>', esc_attr($id), selected($this->plugin->settings()->provider(), $id, false), esc_html($provider->label()));
        }

        printf('</select><p class="description">%s</p></td></tr></table>', esc_html__('Answers the plugins and WooCommerce when they name no provider.', 'gecka-geolocation'));

        printf('<h2>%s</h2>', esc_html__('WooCommerce', 'gecka-geolocation'));
        echo '<table class="form-table" role="presentation">';
        printf(
            '<tr><th scope="row">%s</th><td><label class="gecka-admin-toggle-label"><input type="checkbox" class="gecka-admin-toggle" name="%s[woocommerce]" value="1" %s %s> <span>%s</span></label><p class="description">%s</p></td></tr>',
            esc_html__('Geolocation', 'gecka-geolocation'),
            esc_attr($name),
            checked($this->plugin->settings()->woocommerce(), true, false),
            disabled(WooCommerce::available(), false, false),
            esc_html__('Locate the customers of WooCommerce with the default provider', 'gecka-geolocation'),
            esc_html(
                WooCommerce::available()
                    ? __('WooCommerce then needs no MaxMind licence key of its own: its default customer location set to Geolocate, its taxes and its shipping read the country, the state, the city and the postal code from here. A country a CDN header gives WooCommerce wins.', 'gecka-geolocation')
                    : __('WooCommerce is not active.', 'gecka-geolocation'),
            ),
        );
        echo '</table>';

        printf('<h2>%s</h2>', esc_html__('Logos of the networks', 'gecka-geolocation'));
        echo '<table class="form-table" role="presentation">';
        printf(
            '<tr><th scope="row">%s</th><td><label class="gecka-admin-toggle-label"><input type="checkbox" class="gecka-admin-toggle" name="%s[network_logos]" value="1" %s> <span>%s</span></label><p class="description">%s</p></td></tr>',
            esc_html__('Logos', 'gecka-geolocation'),
            esc_attr($name),
            checked($this->plugin->settings()->networkLogos(), true, false),
            esc_html__('Give the plugins the logo of the network of an address', 'gecka-geolocation'),
            wp_kses(
                sprintf(
                    /* translators: %s: address of the source of the logos */
                    __('The server downloads each logo once from <a href="%s">asn-data</a>, a mirror of PeeringDB, and serves it from the uploads of the site: browsers never ask the source. Many networks have no logo there. Turning this off deletes the logos downloaded.', 'gecka-geolocation'),
                    esc_url('https://github.com/tiagozip/asn-data'),
                ),
                ['a' => ['href' => []]],
            ),
        );
        echo '</table>';

        submit_button();
        echo '</form>';

        self::renderDirectory();
    }

    /**
     * Prints the notices the actions of the tab of a provider leave.
     *
     * @param MmdbProvider $provider
     *
     * @return void
     */
    private function providerNotices(MmdbProvider $provider): void
    {
        $invalid = sanitize_key(Request::query('invalid'));
        $fields = $provider->fields();

        if ($invalid !== '' && isset($fields[$invalid])) {
            wp_admin_notice($fields[$invalid]['invalid'], ['type' => 'error']);
        } elseif (Request::query('saved') !== '') {
            wp_admin_notice(__('Settings saved.', 'gecka-geolocation'), ['type' => 'success', 'dismissible' => true]);
        }

        if (Request::query('forgot') !== '') {
            wp_admin_notice(__('The credentials are forgotten.', 'gecka-geolocation'), ['type' => 'success', 'dismissible' => true]);
        }

        $detected = Request::query('detected');

        if ($detected !== '') {
            $error = $provider->updater()->state()['error'];
            $event = $provider->updater()->retryEvent();
            $retry = Value::int(Setup::onMainSite(static fn(): int|false => wp_next_scheduled($event)));

            wp_admin_notice(
                $detected === '1'
                    /* translators: %s: name of a provider */
                    ? sprintf(__('%s accepted the credentials. The databases they give access to are marked below.', 'gecka-geolocation'), $provider->label())
                    /* translators: %s: error */
                    : sprintf(__('The account could not be checked: %s', 'gecka-geolocation'), esc_html($error)) . ($retry ? ' ' . sprintf(/* translators: %s: time */ __('It is checked again at %s, and the databases downloaded once the credentials are accepted.', 'gecka-geolocation'), wp_date(Value::string(get_option('time_format')), $retry)) : ''),
                ['type' => $detected === '1' ? 'success' : 'error', 'dismissible' => true],
            );
        }
    }

    /**
     * The provider a form posted, once the current user is allowed and the
     * nonce of the action for that provider checked; dies otherwise
     *
     * @param string $action Nonce action, the identifier of the provider following.
     *
     * @return MmdbProvider
     */
    private function postedProvider(string $action): MmdbProvider
    {
        $posted = isset($_POST['provider']) && is_string($_POST['provider']) ? sanitize_key($_POST['provider']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Names the nonce action, checked below.
        $provider = $this->plugin->mmdb($posted);

        if ($provider === null) {
            wp_die(esc_html__('Unknown provider.', 'gecka-geolocation'), 400);
        }

        $this->authorize($action . $provider->id());

        return $provider;
    }

    /**
     * Dies unless the current user may manage the plugin and the nonce of
     * the action is right.
     *
     * @param string $action Nonce action.
     *
     * @return void
     */
    private function authorize(string $action): void
    {
        if (! current_user_can(Plugin::capability())) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'gecka-geolocation'), 403);
        }

        check_admin_referer($action);
    }
}
