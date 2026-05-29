<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">
            <?= $isNew ? 'New Product' : 'Edit Product' ?>
        </h1>
        <?php if (!$isNew && !empty($product['name_en'])): ?>
        <p style="color:#666; font-size:0.9rem"><?= htmlspecialchars($product['name_en']) ?></p>
        <?php endif; ?>
    </div>
    <a href="/admin/products"
       class="admin-btn admin-btn-ghost"
       style="font-size:0.75rem">
        ← Back to Products
    </a>
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

<form method="POST"
      action="<?= $isNew ? '/admin/products' : '/admin/products/' . (int) $product['id'] ?>"
      enctype="multipart/form-data">

    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

    <div style="display:grid; grid-template-columns:2fr 1fr; gap:1.5rem; align-items:start">

        <!-- ================================================================
             LEFT COLUMN — text fields
             ================================================================ -->
        <div>

            <!-- Product Names -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Product Name</p>

                <div class="admin-form-group">
                    <label for="name_en">Name (English) <span style="color:#e53e3e">*</span></label>
                    <input type="text"
                           id="name_en"
                           name="name_en"
                           value="<?= htmlspecialchars($product['name_en'] ?? '') ?>"
                           placeholder="e.g. Garden Bouquet"
                           required>
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="name_es">Name (Spanish)</label>
                    <input type="text"
                           id="name_es"
                           name="name_es"
                           value="<?= htmlspecialchars($product['name_es'] ?? '') ?>"
                           placeholder="e.g. Ramo de Jardín">
                </div>
            </div>

            <!-- Descriptions -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Description</p>

                <div class="admin-form-group">
                    <label for="description_en">Description (English)</label>
                    <textarea id="description_en"
                              name="description_en"
                              rows="4"
                              placeholder="Describe this product in English…"><?= htmlspecialchars($product['description_en'] ?? '') ?></textarea>
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="description_es">Description (Spanish)</label>
                    <textarea id="description_es"
                              name="description_es"
                              rows="4"
                              placeholder="Describa este producto en español…"><?= htmlspecialchars($product['description_es'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Photo -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Product Photo</p>

                <?php if (!$isNew && !empty($product['image_path'])): ?>
                <div style="margin-bottom:1rem">
                    <p style="font-size:0.8rem; color:#666; margin-bottom:0.5rem">Current photo:</p>
                    <img src="/public/uploads/products/<?= htmlspecialchars($product['image_path']) ?>"
                         style="width:120px; height:90px; object-fit:cover; border-radius:6px; border:1.5px solid #dde1e7"
                         alt="Current product photo">
                </div>
                <?php endif; ?>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="image">
                        <?= (!$isNew && !empty($product['image_path'])) ? 'Replace Photo' : 'Upload Photo' ?>
                    </label>
                    <input type="file"
                           id="image"
                           name="image"
                           accept="image/jpeg,image/png,image/webp"
                           data-preview="#img-preview"
                           style="width:100%; padding:0.5rem 0; font-size:0.9rem">
                    <p style="font-size:0.78rem; color:#999; margin-top:0.35rem">
                        JPEG, PNG, or WebP · max 5 MB · resized to 1200 px wide automatically
                    </p>
                    <div class="img-preview-wrap">
                        <img id="img-preview" class="img-preview" alt="Preview">
                    </div>
                </div>
            </div>

        </div><!-- /left column -->

        <!-- ================================================================
             RIGHT COLUMN — settings & metadata
             ================================================================ -->
        <div>

            <!-- Status & Visibility -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Status</p>

                <div style="display:flex; flex-direction:column; gap:0.875rem">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-family:'Lato',sans-serif; font-size:0.9rem; text-transform:none; letter-spacing:0; color:#333; font-weight:400">
                        <input type="checkbox"
                               name="active"
                               value="1"
                               <?= (!$isNew && isset($product['active'])) ? ($product['active'] ? 'checked' : '') : 'checked' ?>
                               style="width:16px; height:16px; accent-color:var(--color-primary)">
                        Active (visible on site)
                    </label>

                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-family:'Lato',sans-serif; font-size:0.9rem; text-transform:none; letter-spacing:0; color:#333; font-weight:400">
                        <input type="checkbox"
                               name="featured"
                               value="1"
                               <?= (!empty($product['featured'])) ? 'checked' : '' ?>
                               style="width:16px; height:16px; accent-color:var(--color-primary)">
                        Featured (shown on homepage)
                    </label>
                </div>
            </div>

            <!-- Category -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Category</p>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="category_id">Category <span style="color:#e53e3e">*</span></label>
                    <select id="category_id" name="category_id" required>
                        <option value="">— Select a category —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                            <?= (isset($product['category_id']) && (int) $product['category_id'] === (int) $cat['id'])
                                    ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name_en']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Pricing -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Pricing</p>

                <div class="admin-form-group">
                    <label for="price_from">Price From ($)</label>
                    <input type="number"
                           id="price_from"
                           name="price_from"
                           value="<?= htmlspecialchars((string) ($product['price_from'] ?? '')) ?>"
                           min="0"
                           step="0.01"
                           placeholder="0.00">
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="price_to">Price To ($) <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">(optional range end)</span></label>
                    <input type="number"
                           id="price_to"
                           name="price_to"
                           value="<?= htmlspecialchars((string) ($product['price_to'] ?? '')) ?>"
                           min="0"
                           step="0.01"
                           placeholder="0.00">
                </div>
            </div>

            <!-- Sort Order -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Display Order</p>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="sort_order">Sort Order <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">(lower = first)</span></label>
                    <input type="number"
                           id="sort_order"
                           name="sort_order"
                           value="<?= (int) ($product['sort_order'] ?? 0) ?>"
                           min="0"
                           step="1">
                </div>
            </div>

            <!-- Save button -->
            <button type="submit" class="admin-btn admin-btn-primary" style="width:100%; justify-content:center; padding:0.875rem">
                <?= $isNew ? 'Save Product' : 'Update Product' ?>
            </button>

        </div><!-- /right column -->

    </div><!-- /grid -->

</form>

</div><!-- /.admin-content -->
