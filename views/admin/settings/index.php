<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Site Settings</h1>
        <p style="color:#666; font-size:0.9rem">Manage content and behaviour without deploying code</p>
    </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px;
    <?= $_SESSION['flash']['type'] === 'success'
        ? 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1'
        : 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb' ?>">
    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
</div>
<?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php
/**
 * Shorthand helper: emit the current value of a setting key, defaulting to
 * an empty string when not yet stored in the database.
 *
 * @param string $key Setting key from site_settings.
 * @return string HTML-escaped value suitable for use in a value="…" attribute.
 */
$val = static fn (string $key) use ($settings): string =>
    htmlspecialchars($settings[$key] ?? '');
?>

<form method="POST" action="/admin/settings">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

    <!-- Top save -->
    <div style="margin-bottom:1.5rem; text-align:right">
        <button type="submit" class="admin-btn admin-btn-primary" style="padding:0.75rem 2rem">
            Save All Settings
        </button>
    </div>

    <!-- ================================================================
         1. HOMEPAGE
         ================================================================ -->
    <div class="admin-card" style="margin-bottom:1.5rem">
        <div class="admin-card-header">
            <p class="admin-card-title">Homepage</p>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="hero_headline_en">Hero Headline (English)</label>
                <input type="text" id="hero_headline_en" name="hero_headline_en"
                       value="<?= $val('hero_headline_en') ?>"
                       placeholder="Fresh Flowers, Delivered with Love">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="hero_headline_es">Hero Headline (Spanish)</label>
                <input type="text" id="hero_headline_es" name="hero_headline_es"
                       value="<?= $val('hero_headline_es') ?>"
                       placeholder="Flores Frescas, Entregadas con Amor">
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-top:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="hero_subtext_en">Hero Subtext (English)</label>
                <input type="text" id="hero_subtext_en" name="hero_subtext_en"
                       value="<?= $val('hero_subtext_en') ?>"
                       placeholder="Hand-arranged bouquets for every occasion">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="hero_subtext_es">Hero Subtext (Spanish)</label>
                <input type="text" id="hero_subtext_es" name="hero_subtext_es"
                       value="<?= $val('hero_subtext_es') ?>"
                       placeholder="Arreglos artesanales para cada ocasión">
            </div>
        </div>
    </div>

    <!-- ================================================================
         2. BUTTONS & LABELS
         ================================================================ -->
    <div class="admin-card" style="margin-bottom:1.5rem">
        <div class="admin-card-header">
            <p class="admin-card-title">Buttons &amp; Labels</p>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="order_button_text_en">Order Button Text (English)</label>
                <input type="text" id="order_button_text_en" name="order_button_text_en"
                       value="<?= $val('order_button_text_en') ?>"
                       placeholder="Order Now">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="order_button_text_es">Order Button Text (Spanish)</label>
                <input type="text" id="order_button_text_es" name="order_button_text_es"
                       value="<?= $val('order_button_text_es') ?>"
                       placeholder="Ordenar Ahora">
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-top:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="doordash_button_label_en">DoorDash Button Label (English)</label>
                <input type="text" id="doordash_button_label_en" name="doordash_button_label_en"
                       value="<?= $val('doordash_button_label_en') ?>"
                       placeholder="Order on DoorDash">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="doordash_button_label_es">DoorDash Button Label (Spanish)</label>
                <input type="text" id="doordash_button_label_es" name="doordash_button_label_es"
                       value="<?= $val('doordash_button_label_es') ?>"
                       placeholder="Pedir en DoorDash">
            </div>
        </div>

        <div style="display:flex; gap:2rem; margin-top:1.25rem">
            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-family:'Lato',sans-serif; font-size:0.9rem">
                <input type="checkbox"
                       name="show_doordash_button"
                       value="1"
                       <?= ($settings['show_doordash_button'] ?? '0') === '1' ? 'checked' : '' ?>
                       style="width:16px; height:16px; accent-color:var(--color-primary)">
                Show DoorDash Button
            </label>
            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-family:'Lato',sans-serif; font-size:0.9rem">
                <input type="checkbox"
                       name="show_whatsapp_button"
                       value="1"
                       <?= ($settings['show_whatsapp_button'] ?? '0') === '1' ? 'checked' : '' ?>
                       style="width:16px; height:16px; accent-color:var(--color-primary)">
                Show WhatsApp Button
            </label>
        </div>
    </div>

    <!-- ================================================================
         3. PROMOTION SIGNUP
         ================================================================ -->
    <div class="admin-card" style="margin-bottom:1.5rem">
        <div class="admin-card-header">
            <p class="admin-card-title">Promotion Signup</p>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="promo_strip_text_en">Promo Strip Text (English)</label>
                <input type="text" id="promo_strip_text_en" name="promo_strip_text_en"
                       value="<?= $val('promo_strip_text_en') ?>"
                       placeholder="Sign up for exclusive offers!">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="promo_strip_text_es">Promo Strip Text (Spanish)</label>
                <input type="text" id="promo_strip_text_es" name="promo_strip_text_es"
                       value="<?= $val('promo_strip_text_es') ?>"
                       placeholder="¡Regístrate para ofertas exclusivas!">
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-top:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="signup_title_en">Signup Section Title (English)</label>
                <input type="text" id="signup_title_en" name="signup_title_en"
                       value="<?= $val('signup_title_en') ?>"
                       placeholder="Join the VIP List">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="signup_title_es">Signup Section Title (Spanish)</label>
                <input type="text" id="signup_title_es" name="signup_title_es"
                       value="<?= $val('signup_title_es') ?>"
                       placeholder="Únete a la Lista VIP">
            </div>
        </div>
    </div>

    <!-- ================================================================
         4. ABOUT PAGE
         ================================================================ -->
    <div class="admin-card" style="margin-bottom:1.5rem">
        <div class="admin-card-header">
            <p class="admin-card-title">About Page</p>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="about_text_en">About Text (English)</label>
                <textarea id="about_text_en" name="about_text_en" rows="6"
                          placeholder="Tell your story in English…"><?= $val('about_text_en') ?></textarea>
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="about_text_es">About Text (Spanish)</label>
                <textarea id="about_text_es" name="about_text_es" rows="6"
                          placeholder="Cuente su historia en español…"><?= $val('about_text_es') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ================================================================
         5. BUSINESS — page titles + VIP threshold
         ================================================================ -->
    <div class="admin-card" style="margin-bottom:1.5rem">
        <div class="admin-card-header">
            <p class="admin-card-title">Business</p>
        </div>

        <!-- Products page -->
        <p style="font-size:0.75rem; font-family:'Montserrat',sans-serif; text-transform:uppercase; letter-spacing:0.06em; color:#aaa; margin-bottom:0.75rem">Products Page</p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="products_page_title_en">Page Title (English)</label>
                <input type="text" id="products_page_title_en" name="products_page_title_en"
                       value="<?= $val('products_page_title_en') ?>"
                       placeholder="Our Flowers">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="products_page_title_es">Page Title (Spanish)</label>
                <input type="text" id="products_page_title_es" name="products_page_title_es"
                       value="<?= $val('products_page_title_es') ?>"
                       placeholder="Nuestras Flores">
            </div>
        </div>

        <!-- Order page -->
        <p style="font-size:0.75rem; font-family:'Montserrat',sans-serif; text-transform:uppercase; letter-spacing:0.06em; color:#aaa; margin-bottom:0.75rem">Order Page</p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="order_page_title_en">Page Title (English)</label>
                <input type="text" id="order_page_title_en" name="order_page_title_en"
                       value="<?= $val('order_page_title_en') ?>"
                       placeholder="Place an Order">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="order_page_title_es">Page Title (Spanish)</label>
                <input type="text" id="order_page_title_es" name="order_page_title_es"
                       value="<?= $val('order_page_title_es') ?>"
                       placeholder="Realizar un Pedido">
            </div>
        </div>

        <!-- About page -->
        <p style="font-size:0.75rem; font-family:'Montserrat',sans-serif; text-transform:uppercase; letter-spacing:0.06em; color:#aaa; margin-bottom:0.75rem">About Page</p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="about_page_title_en">Page Title (English)</label>
                <input type="text" id="about_page_title_en" name="about_page_title_en"
                       value="<?= $val('about_page_title_en') ?>"
                       placeholder="About Us">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="about_page_title_es">Page Title (Spanish)</label>
                <input type="text" id="about_page_title_es" name="about_page_title_es"
                       value="<?= $val('about_page_title_es') ?>"
                       placeholder="Sobre Nosotros">
            </div>
        </div>

        <!-- Contact page -->
        <p style="font-size:0.75rem; font-family:'Montserrat',sans-serif; text-transform:uppercase; letter-spacing:0.06em; color:#aaa; margin-bottom:0.75rem">Contact Page</p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.25rem">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="contact_page_title_en">Page Title (English)</label>
                <input type="text" id="contact_page_title_en" name="contact_page_title_en"
                       value="<?= $val('contact_page_title_en') ?>"
                       placeholder="Contact Us">
            </div>
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="contact_page_title_es">Page Title (Spanish)</label>
                <input type="text" id="contact_page_title_es" name="contact_page_title_es"
                       value="<?= $val('contact_page_title_es') ?>"
                       placeholder="Contáctenos">
            </div>
        </div>

        <!-- VIP threshold -->
        <p style="font-size:0.75rem; font-family:'Montserrat',sans-serif; text-transform:uppercase; letter-spacing:0.06em; color:#aaa; margin-bottom:0.75rem">VIP Programme</p>
        <div style="max-width:240px">
            <div class="admin-form-group" style="margin-bottom:0">
                <label for="vip_spend_threshold">VIP Spend Threshold ($)</label>
                <input type="number" id="vip_spend_threshold" name="vip_spend_threshold"
                       value="<?= $val('vip_spend_threshold') ?>"
                       min="0" step="0.01"
                       placeholder="200.00">
            </div>
        </div>
    </div>

    <!-- Bottom save -->
    <div style="text-align:right; margin-top:0.5rem">
        <button type="submit" class="admin-btn admin-btn-primary" style="padding:0.75rem 2rem">
            Save All Settings
        </button>
    </div>

</form>

</div><!-- /.admin-content -->
