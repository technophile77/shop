<?php
/**
 * views/admin/addons/form.php — Admin create/edit form for a single add-on.
 *
 * Rendered inside views/layouts/admin.php via AddonsController::newForm() or
 * AddonsController::editForm(). Uses Cropper.js (CDN) for the thumbnail crop
 * workflow: admin selects a source image from the media library, drags a crop
 * box, clicks "Apply Crop", which uploads the result to /admin/media/upload
 * and stores the resulting filename in the hidden image_path field.
 *
 * Variables injected:
 *   array  $addon     Existing add-on row (empty array for new add-ons).
 *   bool   $isNew     True when creating a new add-on.
 *   string $csrfToken Session CSRF token.
 *   string $pageTitle 'New Add-On' or 'Edit Add-On'.
 *
 * @see \App\Controllers\Admin\AddonsController
 */
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>

<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">
            <?= $isNew ? 'New Add-On' : 'Edit Add-On' ?>
        </h1>
        <?php if (!$isNew && !empty($addon['name_en'])): ?>
        <p style="color:#666; font-size:0.9rem"><?= htmlspecialchars($addon['name_en']) ?></p>
        <?php endif; ?>
    </div>
    <a href="/admin/addons"
       class="admin-btn admin-btn-ghost"
       style="font-size:0.75rem">
        ← Back to Add-Ons
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
      action="<?= $isNew ? '/admin/addons' : '/admin/addons/' . (int) $addon['id'] ?>">

    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

    <div style="display:grid; grid-template-columns:2fr 1fr; gap:1.5rem; align-items:start">

        <!-- ================================================================
             LEFT COLUMN
             ================================================================ -->
        <div>

            <!-- Add-On Names -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Add-On Name</p>

                <div class="admin-form-group">
                    <label for="name_en">Name (English) <span style="color:#e53e3e">*</span></label>
                    <input type="text"
                           id="name_en"
                           name="name_en"
                           value="<?= htmlspecialchars($addon['name_en'] ?? '') ?>"
                           placeholder="e.g. Ribbon"
                           required>
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="name_es">Name (Spanish)</label>
                    <input type="text"
                           id="name_es"
                           name="name_es"
                           value="<?= htmlspecialchars($addon['name_es'] ?? '') ?>"
                           placeholder="e.g. Lazo">
                </div>
            </div>

            <!-- Thumbnail Crop Widget -->
            <?php
            $addonXdata = 'addonForm('
                . json_encode($addon['image_path'] ?? '', JSON_UNESCAPED_SLASHES)
                . ', '
                . json_encode($csrfToken ?? '', JSON_UNESCAPED_SLASHES)
                . ')';
            ?>
            <div class="admin-card"
                 x-data="<?= htmlspecialchars($addonXdata, ENT_QUOTES, 'UTF-8') ?>">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Thumbnail</p>

                <!-- Hidden field submitted with the form -->
                <input type="hidden" name="image_path" :value="croppedFilename">

                <!-- State 1: cropped image confirmed -->
                <div x-show="croppedFilename && !sourceSelected">
                    <img :src="'/public/uploads/products/' + croppedFilename"
                         style="width:120px; height:120px; object-fit:cover; border-radius:6px; border:1.5px solid #dde1e7"
                         alt="Add-on thumbnail">
                    <div style="margin-top:0.5rem; display:flex; gap:0.5rem">
                        <button type="button" class="admin-btn admin-btn-ghost"
                                style="padding:0.3rem 0.75rem; font-size:0.75rem"
                                @click="openPicker()">Change</button>
                        <button type="button" class="admin-btn"
                                style="padding:0.3rem 0.75rem; font-size:0.75rem; background:#fee2e2; color:#b91c1c; border-color:#fca5a5"
                                @click="removeCrop()">Remove</button>
                    </div>
                </div>

                <!-- State 2: no image yet -->
                <div x-show="!croppedFilename && !sourceSelected">
                    <button type="button" class="admin-btn admin-btn-ghost"
                            style="padding:0.5rem 1rem; font-size:0.85rem"
                            @click="openPicker()">Choose from Library</button>
                    <p style="font-size:0.78rem; color:#999; margin-top:0.35rem">
                        Select a photo from the library, then drag to crop the thumbnail area.
                    </p>
                </div>

                <!-- State 3: source selected, crop UI active -->
                <!-- x-show (not x-if) keeps the img in the DOM so $refs.cropImg is always resolvable -->
                <div x-show="sourceSelected" style="margin-top:0.25rem">
                    <p style="font-size:0.8rem; color:#555; margin-bottom:0.5rem">
                        Drag the box to select the crop area, then click <strong>Apply Crop</strong>.
                    </p>
                    <!-- cropImg must always be in the DOM (not x-if) so $refs.cropImg is always resolvable -->
                    <div style="max-width:500px; max-height:400px; overflow:hidden; border-radius:6px; border:1px solid #dde1e7">
                        <img x-ref="cropImg"
                             :src="sourceSelected ? '/public/uploads/products/' + sourceSelected : ''"
                             style="display:block; max-width:100%"
                             @load="initCropper()"
                             alt="Source image for cropping">
                    </div>
                    <div style="display:flex; gap:0.75rem; margin-top:0.75rem; align-items:center; flex-wrap:wrap">
                        <button type="button" class="admin-btn admin-btn-primary"
                                style="padding:0.5rem 1.25rem; font-size:0.85rem"
                                @click="applyCrop()"
                                :disabled="cropUploading">
                            <span x-show="!cropUploading">Apply Crop</span>
                            <span x-show="cropUploading">Uploading&hellip;</span>
                        </button>
                        <button type="button" class="admin-btn admin-btn-ghost"
                                style="padding:0.5rem 1rem; font-size:0.85rem"
                                @click="removeCrop()">Cancel</button>
                    </div>
                    <p x-show="cropError" x-text="cropError"
                       style="font-size:0.8rem; color:#b91c1c; margin-top:0.5rem"></p>
                </div>

                <!-- Library picker overlay -->
                <div x-show="pickerOpen" x-cloak
                     style="position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.55);
                            display:flex; align-items:center; justify-content:center"
                     @keydown.escape.window="pickerOpen = false"
                     @click.self="pickerOpen = false">
                    <div style="background:#fff; border-radius:10px; padding:1.5rem; width:720px;
                                max-width:95vw; max-height:85vh; display:flex; flex-direction:column; gap:1rem;
                                box-shadow:0 20px 60px rgba(0,0,0,0.3)">

                        <div style="display:flex; justify-content:space-between; align-items:center">
                            <h2 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.3rem; font-weight:600">
                                Choose Source Image
                            </h2>
                            <button type="button" @click="pickerOpen = false"
                                    style="font-size:1.5rem; line-height:1; color:#666; background:none; border:none; cursor:pointer">
                                &times;
                            </button>
                        </div>

                        <!-- Upload new within picker -->
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
                                    <div @click="selectSource(img)"
                                         style="cursor:pointer; border-radius:6px; overflow:hidden;
                                                border:2.5px solid #e8e8e8; transition:border-color 0.15s">
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

            </div><!-- /.admin-card (thumbnail) -->

<script>
/**
 * Alpine.js component for the add-on thumbnail crop widget.
 *
 * Combines a media-library picker (identical to imagePicker() in the products
 * form) with Cropper.js to let the admin select a source image, drag a crop
 * box, and upload the result to /admin/media/upload. The resulting filename
 * is stored in the hidden image_path field and submitted with the form.
 *
 * Flow: openPicker → selectSource → initCropper (via @load) → applyCrop
 *       → uploadCrop → croppedFilename set, crop UI hidden.
 *
 * @param {string} initial  Existing image_path filename, or empty string.
 * @param {string} csrf     Session CSRF token for upload requests.
 * @returns {object} Alpine data object.
 */
function addonForm(initial, csrf) {
    return {
        // Picker state
        sourceSelected: '',
        pickerOpen:     false,
        images:         [],
        loading:        false,
        uploading:      false,
        uploadError:    '',

        // Cropper state
        cropper:         null,

        // Final stored value
        croppedFilename: initial,
        cropUploading:   false,
        cropError:       '',

        openPicker() {
            this.pickerOpen = true;
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

        /**
         * Called when the user clicks an image in the library picker.
         * Sets the source image and closes the picker; initCropper() fires
         * automatically via the <img @load> handler once the image loads.
         *
         * @param {{filename: string, url: string}} img
         */
        selectSource(img) {
            this.sourceSelected = img.filename;
            this.pickerOpen     = false;
            this.cropError      = '';
        },

        /**
         * Initialise or re-initialise Cropper.js on the source image element.
         * Called by <img x-ref="cropImg" @load="initCropper()">.
         * Aspect ratio is locked to 1:1 for a square thumbnail.
         */
        initCropper() {
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
            const imgEl = this.$refs.cropImg;
            if (!imgEl) return;
            this.cropper = new Cropper(imgEl, {
                viewMode:     1,
                aspectRatio:  1,
                autoCropArea: 0.8,
                movable:      true,
                zoomable:     false,
            });
        },

        /**
         * Extract the cropped canvas at 400×400 and trigger upload.
         */
        applyCrop() {
            if (!this.cropper) return;
            const canvas = this.cropper.getCroppedCanvas({ width: 400, height: 400 });
            canvas.toBlob(blob => {
                if (blob) this.uploadCrop(blob);
            }, 'image/jpeg', 0.9);
        },

        /**
         * Upload the cropped JPEG blob to /admin/media/upload.
         * On success, stores the returned filename and hides the crop UI.
         *
         * @param {Blob} blob The cropped image blob.
         */
        async uploadCrop(blob) {
            this.cropUploading = true;
            this.cropError     = '';
            const fd = new FormData();
            fd.append('image',       blob, 'addon_crop.jpg');
            fd.append('_csrf_token', csrf);
            try {
                const res  = await fetch('/admin/media/upload', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    this.croppedFilename = data.filename;
                    this.sourceSelected  = '';
                    if (this.cropper) { this.cropper.destroy(); this.cropper = null; }
                } else {
                    this.cropError = data.error || 'Crop upload failed.';
                }
            } catch {
                this.cropError = 'Network error — please try again.';
            } finally {
                this.cropUploading = false;
            }
        },

        /**
         * Clear all crop/source state and destroy the Cropper instance.
         * Resets to the "choose from library" prompt.
         */
        removeCrop() {
            if (this.cropper) { this.cropper.destroy(); this.cropper = null; }
            this.croppedFilename = '';
            this.sourceSelected  = '';
            this.cropError       = '';
        },

        /**
         * Handle an in-picker file upload, then select it as the source image.
         *
         * @param {Event} e File input change event.
         */
        async uploadFile(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.uploading   = true;
            this.uploadError = '';
            const fd = new FormData();
            fd.append('image',       file);
            fd.append('_csrf_token', csrf);
            try {
                const res  = await fetch('/admin/media/upload', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    this.images.unshift({ filename: data.filename, url: data.url, bytes: data.bytes });
                    this.selectSource({ filename: data.filename });
                } else {
                    this.uploadError = data.error || 'Upload failed.';
                }
            } catch {
                this.uploadError = 'Network error — please try again.';
            } finally {
                this.uploading   = false;
                e.target.value   = '';
            }
        },
    };
}
</script>

        </div><!-- /left column -->

        <!-- ================================================================
             RIGHT COLUMN
             ================================================================ -->
        <div>

            <!-- Status -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Status</p>

                <div style="display:flex; flex-direction:column; gap:0.875rem">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-family:'Lato',sans-serif; font-size:0.9rem; text-transform:none; letter-spacing:0; color:#333; font-weight:400">
                        <input type="checkbox"
                               name="active"
                               value="1"
                               <?= (!$isNew && isset($addon['active'])) ? ($addon['active'] ? 'checked' : '') : 'checked' ?>
                               style="width:16px; height:16px; accent-color:var(--color-primary)">
                        Active (visible on order form)
                    </label>
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
                           value="<?= (int) ($addon['sort_order'] ?? 0) ?>"
                           min="0"
                           step="1">
                </div>
            </div>

            <!-- Pricing & Options -->
            <div class="admin-card">
                <p class="admin-card-title" style="margin-bottom:1.25rem">Pricing &amp; Options</p>

                <div class="admin-form-group">
                    <label for="price">Price (USD) <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">(0 = included free)</span></label>
                    <input type="number"
                           id="price"
                           name="price"
                           value="<?= number_format((float) ($addon['price'] ?? 0), 2) ?>"
                           min="0"
                           step="0.01"
                           placeholder="0.00">
                </div>

                <div style="margin-bottom:0">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-family:'Lato',sans-serif; font-size:0.9rem; text-transform:none; letter-spacing:0; color:#333; font-weight:400">
                        <input type="checkbox"
                               name="has_custom_text"
                               value="1"
                               <?= !empty($addon['has_custom_text']) ? 'checked' : '' ?>
                               style="width:16px; height:16px; accent-color:var(--color-primary)">
                        Has custom text (ribbon)
                    </label>
                    <p style="font-size:0.78rem; color:#999; margin-top:0.35rem; margin-left:1.625rem">
                        Check if the customer should supply a short message for this add-on (e.g. a ribbon inscription).
                    </p>
                </div>
            </div>

            <!-- Save button -->
            <button type="submit" class="admin-btn admin-btn-primary" style="width:100%; justify-content:center; padding:0.875rem">
                <?= $isNew ? 'Save Add-On' : 'Update Add-On' ?>
            </button>

        </div><!-- /right column -->

    </div><!-- /grid -->

</form>

</div><!-- /.admin-content -->
