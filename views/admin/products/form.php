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
      action="<?= $isNew ? '/admin/products' : '/admin/products/' . (int) $product['id'] ?>">

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

            <!-- Photo Picker -->
            <?php $pickerXdata = 'imagePicker(' . json_encode($product['image_path'] ?? '') . ', ' . json_encode($csrfToken ?? '') . ')'; ?>
            <div class="admin-card"
                 x-data="<?= htmlspecialchars($pickerXdata, ENT_QUOTES, 'UTF-8') ?>">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Product Photo</p>

                <input type="hidden" name="image_path" :value="selected">

                <div x-show="selected" style="margin-bottom:1rem">
                    <img :src="'/public/uploads/products/' + selected"
                         style="width:120px; height:90px; object-fit:cover; border-radius:6px; border:1.5px solid #dde1e7"
                         alt="Selected product photo">
                    <div style="margin-top:0.5rem; display:flex; gap:0.5rem">
                        <button type="button" class="admin-btn admin-btn-ghost"
                                style="padding:0.3rem 0.75rem; font-size:0.75rem"
                                @click="openPicker()">Change</button>
                        <button type="button" class="admin-btn"
                                style="padding:0.3rem 0.75rem; font-size:0.75rem; background:#fee2e2; color:#b91c1c; border-color:#fca5a5"
                                @click="selected = ''">Remove</button>
                    </div>
                </div>
                <div x-show="!selected">
                    <button type="button" class="admin-btn admin-btn-ghost"
                            style="padding:0.5rem 1rem; font-size:0.85rem"
                            @click="openPicker()">Choose from Library</button>
                    <p style="font-size:0.78rem; color:#999; margin-top:0.35rem">
                        Upload images via the <a href="/admin/media" target="_blank" style="color:var(--color-primary)">Media Library</a>.
                    </p>
                </div>

                <!-- Picker overlay -->
                <div x-show="open" x-cloak
                     style="position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.55);
                            display:flex; align-items:center; justify-content:center"
                     @keydown.escape.window="open = false"
                     @click.self="open = false">
                    <div style="background:#fff; border-radius:10px; padding:1.5rem; width:720px;
                                max-width:95vw; max-height:85vh; display:flex; flex-direction:column; gap:1rem;
                                box-shadow:0 20px 60px rgba(0,0,0,0.3)">

                        <div style="display:flex; justify-content:space-between; align-items:center">
                            <h2 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.3rem; font-weight:600">
                                Choose Image
                            </h2>
                            <button type="button" @click="open = false"
                                    style="font-size:1.5rem; line-height:1; color:#666; background:none; border:none; cursor:pointer">
                                &times;
                            </button>
                        </div>

                        <!-- Upload new image within picker -->
                        <div style="display:flex; align-items:center; gap:0.75rem; padding:0.75rem;
                                    background:#f8f8f8; border-radius:6px; flex-wrap:wrap">
                            <label style="font-size:0.8rem; color:#555; font-weight:500">Upload new:</label>
                            <input type="file" accept="image/jpeg,image/png,image/webp"
                                   @change="uploadFile($event)"
                                   style="font-size:0.82rem; flex:1; min-width:180px">
                            <span x-show="uploading" style="font-size:0.8rem; color:#666">Uploading&hellip;</span>
                            <span x-show="uploadError" x-text="uploadError"
                                  style="font-size:0.8rem; color:#b91c1c"></span>
                        </div>

                        <!-- Image grid -->
                        <div style="overflow-y:auto; flex:1; min-height:200px">
                            <div x-show="loading" style="text-align:center; color:#999; padding:3rem">Loading&hellip;</div>
                            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:8px">
                                <template x-for="img in images" :key="img.filename">
                                    <div @click="select(img)"
                                         style="cursor:pointer; border-radius:6px; overflow:hidden;
                                                border:2.5px solid transparent; transition:border-color 0.15s"
                                         :style="selected === img.filename
                                             ? 'border-color:var(--color-primary); box-shadow:0 0 0 2px var(--color-primary)'
                                             : 'border-color:#e8e8e8'">
                                        <img :src="img.url"
                                             style="width:100%; height:90px; object-fit:cover; display:block">
                                        <p style="font-size:0.62rem; color:#666; padding:3px 5px; margin:0;
                                                  overflow:hidden; white-space:nowrap; text-overflow:ellipsis"
                                           x-text="img.filename"></p>
                                    </div>
                                </template>
                                <div x-show="!loading && images.length === 0"
                                     style="grid-column:1/-1; text-align:center; color:#999; padding:3rem">
                                    No images yet. Upload one above.
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div><!-- /.admin-card (image picker) -->

<script>
/**
 * Alpine.js component for the product image picker.
 *
 * Manages the picker overlay, fetches the media library list, handles
 * in-picker AJAX uploads, and exposes the selected filename via the
 * hidden image_path form field.
 *
 * @param {string} initial  Currently saved image filename, or empty string.
 * @param {string} csrf     CSRF token for the upload request.
 * @returns {object} Alpine data object.
 */
function imagePicker(initial, csrf) {
    return {
        selected:    initial,
        open:        false,
        images:      [],
        loading:     false,
        uploading:   false,
        uploadError: '',

        openPicker() {
            this.open = true;
            if (this.images.length === 0) this.loadImages();
        },

        async loadImages() {
            this.loading = true;
            try {
                const res   = await fetch('/admin/media/list');
                this.images = await res.json();
            } finally {
                this.loading = false;
            }
        },

        select(img) {
            this.selected = img.filename;
            this.open     = false;
        },

        async uploadFile(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.uploading   = true;
            this.uploadError = '';
            const fd = new FormData();
            fd.append('image', file);
            fd.append('_csrf_token', csrf);
            try {
                const res  = await fetch('/admin/media/upload', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    this.images.unshift({ filename: data.filename, url: data.url, bytes: data.bytes });
                    this.select({ filename: data.filename });
                } else {
                    this.uploadError = data.error || 'Upload failed.';
                }
            } catch {
                this.uploadError = 'Network error — please try again.';
            } finally {
                this.uploading = false;
                e.target.value = '';
            }
        },
    };
}
</script>

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

            <!-- Flower Count -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Flower Details</p>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="flower_count">Flower Count <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">(optional)</span></label>
                    <input type="number" id="flower_count" name="flower_count"
                           value="<?= htmlspecialchars((string) ($product['flower_count'] ?? '')) ?>"
                           min="1" step="1" placeholder="e.g. 12">
                </div>
            </div>

            <!-- Occasions -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Occasions</p>
                <div style="display:flex; flex-direction:column; gap:0.5rem">
                <?php foreach ($occasions as $occ): ?>
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.875rem; font-weight:400; font-family:'Lato',sans-serif; text-transform:none; letter-spacing:0; color:#333">
                        <input type="checkbox" name="occasion_ids[]"
                               value="<?= (int) $occ['id'] ?>"
                               <?= in_array((int) $occ['id'], $selectedOccasionIds ?? [], true) ? 'checked' : '' ?>
                               style="width:15px; height:15px; accent-color:var(--color-primary)">
                        <?= htmlspecialchars($occ['name_en']) ?>
                    </label>
                <?php endforeach; ?>
                <?php if (empty($occasions)): ?>
                    <p style="font-size:0.8rem; color:#999">No occasions configured yet.</p>
                <?php endif; ?>
                </div>
            </div>

            <!-- Flower Types -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Flower Types</p>
                <div style="display:flex; flex-direction:column; gap:0.5rem">
                <?php foreach ($flowerTypes as $ft): ?>
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.875rem; font-weight:400; font-family:'Lato',sans-serif; text-transform:none; letter-spacing:0; color:#333">
                        <input type="checkbox" name="flower_type_ids[]"
                               value="<?= (int) $ft['id'] ?>"
                               <?= in_array((int) $ft['id'], $selectedFlowerTypeIds ?? [], true) ? 'checked' : '' ?>
                               style="width:15px; height:15px; accent-color:var(--color-primary)">
                        <?= htmlspecialchars($ft['name_en']) ?>
                    </label>
                <?php endforeach; ?>
                <?php if (empty($flowerTypes)): ?>
                    <p style="font-size:0.8rem; color:#999">No flower types configured yet.</p>
                <?php endif; ?>
                </div>
            </div>

            <!-- Pictured Colors -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Photo Colors</p>
                <p style="font-size:0.78rem; color:#888; margin-bottom:0.875rem">Colors shown in the product photo — used as defaults when customers customize.</p>

                <div class="admin-form-group">
                    <label for="pictured_flower_color_id">Flower Color in Photo</label>
                    <select id="pictured_flower_color_id" name="pictured_flower_color_id">
                        <option value="">— None —</option>
                        <?php foreach ($flowerColors as $fc): ?>
                        <option value="<?= (int) $fc['id'] ?>"
                            <?= (isset($product['pictured_flower_color_id']) && (int) $product['pictured_flower_color_id'] === (int) $fc['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fc['name_en']) ?><?= $fc['hex'] ? ' (' . htmlspecialchars($fc['hex']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="pictured_paper_color_id">Paper Color in Photo</label>
                    <select id="pictured_paper_color_id" name="pictured_paper_color_id">
                        <option value="">— None —</option>
                        <?php foreach ($paperColors as $pc): ?>
                        <option value="<?= (int) $pc['id'] ?>"
                            <?= (isset($product['pictured_paper_color_id']) && (int) $product['pictured_paper_color_id'] === (int) $pc['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pc['name_en']) ?><?= $pc['hex'] ? ' (' . htmlspecialchars($pc['hex']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
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
