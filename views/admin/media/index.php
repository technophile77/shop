<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Media Library</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($images) ?> image<?= count($images) !== 1 ? 's' : '' ?></p>
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

<!-- ============================================================
     UPLOAD CARD
     ============================================================ -->
<div class="admin-card" style="margin-bottom:2rem"
     x-data="mediaUploader('<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>')">

    <p class="admin-card-title" style="margin-bottom:1.25rem">Upload Image</p>

    <div style="display:flex; align-items:center; gap:1.25rem; flex-wrap:wrap">
        <label style="cursor:pointer">
            <input type="file"
                   accept="image/jpeg,image/png,image/webp"
                   style="display:none"
                   @change="upload($event)">
            <span class="admin-btn admin-btn-primary"
                  style="display:inline-block; pointer-events:none">
                Choose File
            </span>
        </label>

        <span x-show="uploading" style="color:#555; font-size:0.875rem">Uploading&hellip;</span>
        <span x-show="!uploading && error"
              style="color:#b91c1c; font-size:0.875rem"
              x-text="error"></span>
        <span x-show="!uploading && !error"
              style="color:#666; font-size:0.875rem">
            Accepts JPEG, PNG, WebP &middot; max 20 MB &middot; auto-resized to 1200 px wide
        </span>
    </div>
</div>

<!-- ============================================================
     IMAGE GRID CARD
     ============================================================ -->
<div class="admin-card" style="padding:0">

    <?php if (empty($images)): ?>
    <div style="padding:3rem 1.5rem; text-align:center; color:var(--color-muted)">
        No images yet. Use the form above to upload one.
    </div>
    <?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:1px; background:#e8e8e8">
        <?php foreach ($images as $img): ?>
        <div style="background:#fff; padding:1rem; display:flex; flex-direction:column; gap:0.5rem">

            <img src="<?= htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8') ?>"
                 style="width:100%; height:120px; object-fit:cover; border-radius:4px"
                 alt="">

            <code style="font-size:0.72rem; color:#555; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; display:block"
                  title="<?= htmlspecialchars($img['filename'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($img['filename'], ENT_QUOTES, 'UTF-8') ?>
            </code>

            <span style="font-size:0.75rem; color:var(--color-muted)">
                <?= number_format($img['bytes'] / 1024, 1) ?> KB
            </span>

            <form method="POST"
                  action="/admin/media/delete"
                  data-confirm="Delete this image? Products using it will lose their photo.">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="filename" value="<?= htmlspecialchars($img['filename'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit"
                        class="admin-btn"
                        style="padding:0.35rem 0.75rem; font-size:0.7rem; width:100%;
                               background:#fee2e2; color:#b91c1c; border-color:#fca5a5">
                    Delete
                </button>
            </form>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

</div><!-- /.admin-content -->

<script>
/**
 * Alpine.js component for AJAX image uploads in the Media Library.
 *
 * Sends the selected file to /admin/media/upload as multipart/form-data,
 * including the CSRF token, then reloads the page on success or surfaces
 * the server-returned error message.
 *
 * @param {string} csrf - CSRF token value embedded at render time.
 * @returns {object} Alpine component data object with `uploading`, `error`, and `upload`.
 *
 * @example
 * // Used via x-data on the upload card wrapper:
 * // <div x-data="mediaUploader('TOKEN')">
 */
function mediaUploader(csrf) {
    return {
        uploading: false,
        error: '',
        /**
         * Handle file-input change: POST the chosen file and reload on success.
         *
         * @param {Event} e - The native change event from the file input.
         * @returns {Promise<void>}
         */
        async upload(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.uploading = true;
            this.error = '';
            const fd = new FormData();
            fd.append('image', file);
            fd.append('_csrf_token', csrf);
            const res = await fetch('/admin/media/upload', { method: 'POST', body: fd });
            const data = await res.json();
            this.uploading = false;
            if (data.success) {
                window.location.reload();
            } else {
                this.error = data.error || 'Upload failed.';
            }
            e.target.value = '';
        }
    };
}
</script>
