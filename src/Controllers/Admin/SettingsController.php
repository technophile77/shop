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
 * Exposes a single-page form that bulk-updates the known site_settings keys.
 * Only keys from the explicit allow-list are written; arbitrary POST keys are
 * ignored to prevent mass-assignment of unexpected database rows. A text key
 * missing from a submission keeps its stored value, so a save never wipes a
 * setting the submitted form did not carry (see {@see valuesFromPost()}).
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
        'home_page_title_en',
        'home_page_title_es',

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
        'about_meta_desc_en',
        'about_meta_desc_es',

        // Business
        'products_page_title_en',
        'products_page_title_es',
        'products_meta_desc_en',
        'products_meta_desc_es',
        'order_page_title_en',
        'order_page_title_es',
        'about_page_title_en',
        'about_page_title_es',
        'contact_page_title_en',
        'contact_page_title_es',
        'vip_spend_threshold',
    ];

    /**
     * Keys from KNOWN_KEYS that are rendered as HTML checkboxes.
     *
     * Unchecked checkboxes send no POST field at all, so presence in POST maps
     * to '1' and absence to '0' instead of leaving the stored value alone.
     *
     * @var list<string>
     */
    private const CHECKBOX_KEYS = [
        'show_doordash_button',
        'show_whatsapp_button',
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
     * @param array<string,string> $_params Route parameters (none for this route).
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
     * Persist the submitted setting values.
     *
     * Rejects the request with an error flash when the CSRF token is invalid.
     * Otherwise reads every KNOWN_KEYS field through Request::post() (so
     * string values arrive trimmed), lets valuesFromPost() decide which keys to
     * write, and saves each pair with Settings::set(). Text fields that were
     * not submitted are not written, so their stored values survive the save;
     * this is what protects the settings a stale copy of the form (cached by a
     * browser before some inputs existed) leaves out. Checkbox keys
     * (show_doordash_button, show_whatsapp_button) are written as present=1 /
     * absent=0 because unchecked HTML checkboxes send no POST field at all.
     * After the batch write, Settings::reload() refreshes the in-request cache
     * so subsequent reads within the same request reflect the saved values.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/settings, carrying a flash message
     *                  that reports either the invalid token or the success.
     *
     * @example
     *   (new SettingsController())->update($request, []);
     *
     * @see self::valuesFromPost() Decides which keys are written, and with what.
     */
    public function update(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid security token. Please try again.'];
            return $this->redirect('/admin/settings');
        }

        // post() yields null for every field the form did not send.
        $post = [];
        foreach (self::KNOWN_KEYS as $key) {
            $post[$key] = $request->post($key);
        }

        foreach (self::valuesFromPost($post) as $key => $value) {
            Settings::set($key, $value);
        }

        Settings::reload();

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Settings saved successfully.'];
        return $this->redirect('/admin/settings');
    }

    // -------------------------------------------------------------------------
    // POST → values mapping (pure)
    // -------------------------------------------------------------------------

    /**
     * Map a submitted form to the setting values that should be persisted.
     *
     * Pure function — no I/O, no superglobals — so the save rules can be tested
     * without a database. Only KNOWN_KEYS can appear in the result; any other
     * field in $post is ignored (mass-assignment protection).
     *
     *  - Text keys are included only when submitted as a string. A key missing
     *    from the submission is omitted, which leaves its stored row unchanged:
     *    a stale copy of the admin form, cached by a browser before the
     *    home_page_title_*, about_meta_desc_* and products_meta_desc_* inputs
     *    existed, must not wipe those settings.
     *    A submitted empty string is included as '' because the owner cleared
     *    the field on purpose; Settings::get() then falls back to its default.
     *  - Checkbox keys (CHECKBOX_KEYS) are always included: '1' when submitted,
     *    '0' when absent, because unchecked HTML checkboxes send nothing.
     *
     * @param array<string,mixed> $post Submitted fields keyed by field name,
     *                                  e.g. from Request::post(). A null value
     *                                  counts as absent. A non-string value for
     *                                  a text key (such as an array posted as
     *                                  field[]) is ignored.
     *
     * @return array<string,string> Setting key => value to hand to
     *                              Settings::set(), in KNOWN_KEYS order.
     *
     * @example
     *   SettingsController::valuesFromPost([
     *       'about_text_en'        => 'Meet Perla.',
     *       'hero_subtext_en'      => '',
     *       'show_whatsapp_button' => '1',
     *       'rogue_key'            => 'ignored',
     *   ]);
     *   // [
     *   //     'hero_subtext_en'      => '',
     *   //     'show_doordash_button' => '0',
     *   //     'show_whatsapp_button' => '1',
     *   //     'about_text_en'        => 'Meet Perla.',
     *   // ]
     *
     * @see self::update() Calls this and writes each returned pair.
     */
    public static function valuesFromPost(array $post): array
    {
        $values = [];

        foreach (self::KNOWN_KEYS as $key) {
            $raw = $post[$key] ?? null;

            if (in_array($key, self::CHECKBOX_KEYS, true)) {
                // Unchecked boxes are absent from POST — treat missing as '0'.
                $values[$key] = $raw !== null ? '1' : '0';
            } elseif (is_string($raw)) {
                $values[$key] = $raw;
            }
        }

        return $values;
    }
}
