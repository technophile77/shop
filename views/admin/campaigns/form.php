<?php
/**
 * views/admin/campaigns/form.php — New campaign creator.
 *
 * Rendered inside the 'admin' layout. Collects campaign details, audience
 * targeting, budget, creative A, and (via Alpine.js toggle) an optional
 * creative B for A/B testing.
 *
 * Variables available:
 *   bool   $fbConfigured  True when META_* env vars are all present.
 *   string $csrfToken     CSRF token for the form.
 *
 * @see \App\Controllers\Admin\CampaignsController::newForm()
 * @see \App\Controllers\Admin\CampaignsController::create()
 */
?>
<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">New Campaign</h1>
        <p style="color:#666; font-size:0.9rem">Create a Facebook and/or Instagram ad campaign</p>
    </div>
    <a href="/admin/campaigns" class="admin-btn admin-btn-ghost" style="font-size:0.75rem">&larr; Back to Campaigns</a>
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

<?php if (!$fbConfigured): ?>
<div style="background:#fffbeb; border:1px solid #fbbf24; border-radius:8px; padding:1.25rem 1.5rem; margin-bottom:1.5rem; font-size:0.9rem; color:#78350f">
    <strong style="color:#92400e">API Not Configured</strong> &mdash;
    If the API is not configured, campaigns are saved as &ldquo;draft&rdquo; and you can launch them manually from
    <a href="https://adsmanager.facebook.com" target="_blank" rel="noopener" style="color:#92400e">Facebook Ads Manager</a>.
    The creative assets and copy are saved here for reference.
</div>
<?php endif; ?>

<form method="POST" action="/admin/campaigns" enctype="multipart/form-data" x-data="{ isAb: false }">

    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

    <div style="display:grid; grid-template-columns:2fr 1fr; gap:1.5rem; align-items:start">

        <!-- ==============================================================
             LEFT COLUMN
             ============================================================== -->
        <div>

            <!-- Campaign Details -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Campaign Details</p>

                <div class="admin-form-group">
                    <label for="name">Campaign Name <span style="color:#e53e3e">*</span></label>
                    <input type="text" id="name" name="name" placeholder="e.g. Mother's Day Sale 2026" required>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem">
                    <div class="admin-form-group">
                        <label for="platform">Platform</label>
                        <select id="platform" name="platform">
                            <option value="both" selected>Facebook &amp; Instagram</option>
                            <option value="facebook">Facebook only</option>
                            <option value="instagram">Instagram only</option>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <label for="objective">Objective</label>
                        <select id="objective" name="objective">
                            <option value="TRAFFIC" selected>Traffic</option>
                            <option value="LEAD_GENERATION">Lead Generation</option>
                            <option value="REACH">Reach</option>
                            <option value="CONVERSIONS">Conversions</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:0">
                    <div class="admin-form-group" style="margin-bottom:0">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date">
                    </div>
                    <div class="admin-form-group" style="margin-bottom:0">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date">
                    </div>
                </div>
            </div>

            <!-- Audience -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Audience</p>

                <div class="admin-form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" placeholder="e.g. Tulsa, OK">
                    <p style="font-size:0.78rem; color:#999; margin-top:0.35rem">City name or lat,lng radius string (e.g. &ldquo;36.154,-95.993,25&rdquo;)</p>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:0">
                    <div class="admin-form-group" style="margin-bottom:0">
                        <label for="age_min">Minimum Age</label>
                        <input type="number" id="age_min" name="age_min" value="18" min="18" max="65">
                    </div>
                    <div class="admin-form-group" style="margin-bottom:0">
                        <label for="age_max">Maximum Age</label>
                        <input type="number" id="age_max" name="age_max" value="65" min="18" max="65">
                    </div>
                </div>
            </div>

            <!-- Creative A -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Creative A <span style="color:#e53e3e">*</span></p>

                <div class="admin-form-group">
                    <label for="image_a">Ad Image <span style="color:#e53e3e">*</span></label>
                    <input type="file" id="image_a" name="image_a" accept="image/jpeg,image/png,image/webp" required>
                    <p style="font-size:0.78rem; color:#999; margin-top:0.35rem">JPEG, PNG, or WebP &middot; max 5 MB &middot; recommended 1200 &times; 628 px</p>
                </div>

                <div class="admin-form-group">
                    <label for="ad_headline_a">Headline</label>
                    <input type="text" id="ad_headline_a" name="ad_headline_a" placeholder="e.g. Fresh Flowers for Mom" maxlength="255">
                </div>

                <div class="admin-form-group">
                    <label for="ad_copy_a">Ad Copy / Body</label>
                    <textarea id="ad_copy_a" name="ad_copy_a" rows="3" placeholder="Write your ad copy here&hellip;"></textarea>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:0">
                    <div class="admin-form-group" style="margin-bottom:0">
                        <label for="ad_cta_a">CTA Button</label>
                        <select id="ad_cta_a" name="ad_cta_a">
                            <option value="LEARN_MORE">Learn More</option>
                            <option value="SHOP_NOW">Shop Now</option>
                            <option value="ORDER_NOW">Order Now</option>
                            <option value="GET_QUOTE">Get Quote</option>
                        </select>
                    </div>
                    <div class="admin-form-group" style="margin-bottom:0">
                        <label for="ad_link_a">Destination URL</label>
                        <input type="url" id="ad_link_a" name="ad_link_a" placeholder="https://yoursite.com/order">
                    </div>
                </div>
            </div>

            <!-- A/B Test Toggle -->
            <div class="admin-card">
                <label style="display:flex; align-items:flex-start; gap:0.625rem; cursor:pointer; font-family:'Lato',sans-serif; font-size:0.9rem; text-transform:none; letter-spacing:0; color:#333; font-weight:400; margin-bottom:1.25rem">
                    <input type="checkbox" x-model="isAb" name="is_ab_test" value="1"
                           style="width:16px; height:16px; margin-top:2px; flex-shrink:0; accent-color:var(--color-primary)">
                    <span>
                        <strong>Create A/B test</strong> &mdash;
                        two ads, same audience. Facebook automatically optimises delivery toward the better-performing variant.
                    </span>
                </label>

                <!-- Creative B (shown only when A/B is enabled) -->
                <div x-show="isAb"
                     x-transition
                     style="padding:1.5rem; background:var(--color-bg-light); border-radius:8px; border:1.5px dashed var(--color-primary)">
                    <h4 style="margin:0 0 1.25rem; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.1rem; font-weight:600; color:#1a1a1a">
                        Creative B (Alternative Variant)
                    </h4>

                    <div class="admin-form-group">
                        <label for="image_b">Ad Image B</label>
                        <input type="file" id="image_b" name="image_b" accept="image/jpeg,image/png,image/webp">
                        <p style="font-size:0.78rem; color:#999; margin-top:0.35rem">JPEG, PNG, or WebP &middot; max 5 MB</p>
                    </div>

                    <div class="admin-form-group">
                        <label for="headline_b">Headline B</label>
                        <input type="text" id="headline_b" name="headline_b" placeholder="e.g. Handcrafted Just for Her" maxlength="255">
                    </div>

                    <div class="admin-form-group">
                        <label for="copy_b">Ad Copy B</label>
                        <textarea id="copy_b" name="copy_b" rows="3" placeholder="Alternative ad copy&hellip;"></textarea>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:0">
                        <div class="admin-form-group" style="margin-bottom:0">
                            <label for="cta_b">CTA Button B</label>
                            <select id="cta_b" name="cta_b">
                                <option value="LEARN_MORE">Learn More</option>
                                <option value="SHOP_NOW">Shop Now</option>
                                <option value="ORDER_NOW">Order Now</option>
                                <option value="GET_QUOTE">Get Quote</option>
                            </select>
                        </div>
                        <div class="admin-form-group" style="margin-bottom:0">
                            <label for="link_b">Destination URL B</label>
                            <input type="url" id="link_b" name="link_b" placeholder="https://yoursite.com/order">
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /left column -->

        <!-- ==============================================================
             RIGHT COLUMN
             ============================================================== -->
        <div>

            <!-- Budget -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Budget</p>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="daily_budget_dollars">Daily Budget ($) <span style="color:#e53e3e">*</span></label>
                    <input type="number" id="daily_budget_dollars" name="daily_budget_dollars"
                           value="10.00" min="1" step="0.01" required>
                    <p style="font-size:0.78rem; color:#999; margin-top:0.35rem">Minimum $1.00/day</p>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="admin-btn admin-btn-primary" style="width:100%; justify-content:center; padding:0.875rem">
                <?= $fbConfigured ? 'Launch Campaign' : 'Save as Draft' ?>
            </button>

            <p style="font-size:0.78rem; color:#999; margin-top:0.75rem; text-align:center">
                <?= $fbConfigured
                    ? 'Campaign will be submitted to Meta immediately.'
                    : 'Campaign saved as draft &mdash; launch manually from Ads Manager.' ?>
            </p>

        </div><!-- /right column -->

    </div><!-- /grid -->

</form>

</div><!-- /.admin-content -->
