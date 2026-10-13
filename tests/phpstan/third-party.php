<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * What PHPStan needs to know of WooCommerce, which the analysis does not
 * load.
 */

class WooCommerce
{
    /**
     * @var WC_Countries|null
     */
    public $countries;
}

class WC_Countries
{
    /**
     * @param string $cc
     *
     * @return array<string, string>|false
     */
    public function get_states($cc = null)
    {
        return [];
    }
}

/**
 * @return WooCommerce
 */
function WC()
{
    return new WooCommerce();
}
