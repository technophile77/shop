<?php

declare(strict_types=1);

use App\Core\Lang;

/**
 * Translates a dot-notation key using the currently active locale.
 *
 * This is a global shorthand for Lang::get().  It is autoloaded via
 * Composer's "files" mechanism so it is available everywhere without an
 * explicit require.
 *
 * @param string $key Dot-notation translation key, e.g. 'nav.home'.
 *
 * @return string Translated string, or $key when the key is not found.
 *
 * @see \App\Core\Lang::get()
 *
 * @example
 *   echo __t('nav.home');   // 'Home' (EN) or 'Inicio' (ES)
 *   echo __t('order.submit'); // 'Send My Request' (EN)
 */
function __t(string $key): string
{
    return Lang::get($key);
}
