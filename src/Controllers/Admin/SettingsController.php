<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Admin controller for the Site Settings page.
 *
 * Exposes a single-page form that bulk-updates all known site_settings keys.
 * Only keys from the explicit allow-list are written; arbitrary POST keys are
 * ignored to prevent mass-assignment of unexpected database rows.
 *
 * Flash messages are stored in $_SESSION['flash'] and read by the view.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware before this controller is invoked.
 *
 * @see \App\Core\Settings  Provides ::all(), ::set(), and ::reload().
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 */
final class SettingsController extends BaseController
{
    /**
     * All site_settings keys managed by this form.
     *
     * Only these keys are read from POST and written to the database.
     * Add new keys here when the schema grows.
     *
     * @var list<string>
     */
    private const KNOWN_KEYS = [
        // Homepage
        'hero_headline_en',
        'hero_headline_es',
        'hero_subtext_en',
        'hero_subtext_es',

        // Buttons & Labels
        'order_button_text_en',
        'order_button_text_es',
        'doordash_button_label_en',
        'doordash_button_label_es',
        'show_doordash_button',
        'show_whatsapp_button',

        // Promotion Signup
        'promo_strip_text_en',
        'promo_strip_text_es',
        'signup_title_en',
        'signup_title_es',

        // About Page
        'about_text_en',
        'about_text_es',

        // Business
        'products_page_title_en',
        'products_page_title_es',
        'order_page_title_en',
        'order_page_title_es',
        'about_page_title_en',
        'about_page_title_es',
        'contact_page_title_en',
        'contact_page_title_es',
        'vip_spend_threshold',
    ];

    // -------------------------------------------------------------------------
    // GET /admin/settings
    // -------------------------------------------------------------------------

    /**
     * Render the site settings form.
     *
     * Loads all settings from the database via Settings::all() so the form
     * is pre-populated with current values. The $settings array is passed
     * directly to the view; unknown keys present in the database are ignored
     * by the template since it only renders the keys in KNOWN_KEYS.
     *
     * @param Request              $request HTTP request (unused for GET).
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/settings/index.
     *
     * @example
     *   (new SettingsController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $settings  = Settings::all();
        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/settings/index', [
                'settings'  => $settings,
                'csrfToken' => $csrfToken,
                'pageTitle' => 'Site Settings',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/settings — bulk update
    // -------------------------------------------------------------------------

    /**
     * Persist all submitted setting values.
     *
     * Iterates over KNOWN_KEYS and calls Settings::set() for each key that
     * appears in the POST body. Checkbox keys (show_doordash_button,
     * show_whatsapp_button) are treated as present=1 / absent=0 because
     * unchecked HTML checkboxes send no POST field at all.
     * After the batch write, Settings::reload() refreshes the in-request cache
     * so subsequent reads within the same request reflect the saved values.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/settings.
     *
     * @example
     *   (new SettingsController())->update($request, []);
     */
    public function update(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid security token. Please try again.'];
            return $this->redirect('/admin/settings');
        }

        /** Keys whose presence in POST maps to 1, absence to 0 (HTML checkboxes). */
        $checkboxKeys = ['show_doordash_button', 'show_whatsapp_button'];

        foreach (self::KNOWN_KEYS as $key) {
            if (in_array($key, $checkboxKeys, true)) {
                // Unchecked boxes are absent from POST — treat missing as '0'.
                $value = $request->post($key) !== null ? '1' : '0';
            } else {
                $raw   = $request->post($key);
                $value = $raw !== null ? (string) $raw : '';
            }

            Settings::set($key, $value);
        }

        Settings::reload();

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Settings saved successfully.'];
        return $this->redirect('/admin/settings');
    }
}
