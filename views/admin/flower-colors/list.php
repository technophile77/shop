<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Flower Colors</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($flowerColors) ?> color<?= count($flowerColors) !== 1 ? 's' : '' ?></p>
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
     EXISTING FLOWER COLORS TABLE
     ============================================================ -->
<div class="admin-card" style="padding:0; margin-bottom:2rem">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="padding-left:1.5rem">Name (EN)</th>
                    <th>Name (ES)</th>
                    <th>Hex</th>
                    <th style="text-align:center">Sort</th>
                    <th style="text-align:center">Status</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($flowerColors as $fc): ?>
                <tr x-data="<?= htmlspecialchars(json_encode([
                        'editing'   => false,
                        'nameEn'    => $fc['name_en'],
                        'nameEs'    => $fc['name_es'] ?? '',
                        'hex'       => $fc['hex'] ?? '',
                        'sortOrder' => (int) $fc['sort_order'],
                        'active'    => (bool) $fc['active'],
                    ]), ENT_QUOTES, 'UTF-8') ?>">

                    <td style="padding-left:1.5rem">
                        <strong x-show="!editing" x-text="nameEn"></strong>
                        <input x-show="editing" type="text" x-model="nameEn" placeholder="Name (EN)"
                               style="padding:0.4rem 0.6rem; border:1.5px solid #dde1e7; border-radius:4px; font-size:0.875rem; width:100%">
                    </td>

                    <td style="color:#555">
                        <span x-show="!editing" x-text="nameEs || '—'"></span>
                        <input x-show="editing" type="text" x-model="nameEs" placeholder="Name (ES)"
                               style="padding:0.4rem 0.6rem; border:1.5px solid #dde1e7; border-radius:4px; font-size:0.875rem; width:100%">
                    </td>

                    <td>
                        <div x-show="!editing" style="display:flex; align-items:center; gap:0.5rem">
                            <span x-show="hex"
                                  :style="'display:inline-block; width:16px; height:16px; border-radius:3px; background:' + hex + '; border:1px solid rgba(0,0,0,0.15)'">
                            </span>
                            <code style="font-size:0.8rem; background:#f4f6f9; padding:0.2rem 0.4rem; border-radius:3px" x-text="hex || '—'"></code>
                        </div>
                        <input x-show="editing" type="text" x-model="hex" placeholder="#D32F2F"
                               pattern="#[0-9A-Fa-f]{6}"
                               title="Six-digit hex code, e.g. #D32F2F"
                               style="padding:0.4rem 0.6rem; border:1.5px solid #dde1e7; border-radius:4px; font-size:0.875rem; width:110px; font-family:monospace">
                    </td>

                    <td style="text-align:center">
                        <span x-show="!editing" x-text="sortOrder"></span>
                        <input x-show="editing" type="number" x-model.number="sortOrder" min="0"
                               style="padding:0.4rem 0.6rem; border:1.5px solid #dde1e7; border-radius:4px; font-size:0.875rem; width:70px; text-align:center">
                    </td>

                    <td style="text-align:center">
                        <span x-show="!editing" class="badge" :class="active ? 'badge-active' : 'badge-draft'" x-text="active ? 'Active' : 'Hidden'"></span>
                        <label x-show="editing" style="display:inline-flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.85rem">
                            <input type="checkbox" x-model="active"
                                   style="width:15px; height:15px; accent-color:var(--color-primary)">
                            Active
                        </label>
                    </td>

                    <td style="padding-right:1.5rem; white-space:nowrap">

                        <!-- EDIT toggle -->
                        <button type="button"
                                class="admin-btn admin-btn-ghost"
                                style="padding:0.4rem 0.875rem; font-size:0.7rem"
                                x-show="!editing"
                                @click="editing = true">
                            Edit
                        </button>

                        <!-- SAVE (inline update form) -->
                        <form method="POST"
                              action="/admin/flower-colors/<?= (int) $fc['id'] ?>"
                              x-show="editing"
                              style="display:inline-block">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                            <input type="hidden" name="name_en" :value="nameEn">
                            <input type="hidden" name="name_es" :value="nameEs">
                            <input type="hidden" name="hex" :value="hex">
                            <input type="hidden" name="sort_order" :value="sortOrder">
                            <input type="hidden" name="active" :value="active ? '1' : '0'">
                            <button type="submit"
                                    class="admin-btn admin-btn-primary"
                                    style="padding:0.4rem 0.875rem; font-size:0.7rem">
                                Save
                            </button>
                            <button type="button"
                                    class="admin-btn admin-btn-ghost"
                                    style="padding:0.4rem 0.875rem; font-size:0.7rem; margin-left:0.25rem"
                                    @click="editing = false">
                                Cancel
                            </button>
                        </form>

                        <!-- DELETE -->
                        <form method="POST"
                              action="/admin/flower-colors/<?= (int) $fc['id'] ?>/delete"
                              style="display:inline-block; margin-left:0.375rem"
                              x-show="!editing"
                              data-confirm="Delete flower color &quot;<?= htmlspecialchars(addslashes($fc['name_en'])) ?>&quot;? This cannot be undone.">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                            <button type="submit"
                                    class="admin-btn"
                                    style="padding:0.4rem 0.875rem; font-size:0.7rem;
                                           background:#fee2e2; color:#b91c1c; border-color:#fca5a5">
                                Delete
                            </button>
                        </form>

                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($flowerColors)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        No flower colors yet. Use the form below to add one.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================
     ADD FLOWER COLOR FORM
     ============================================================ -->
<div class="admin-card">
    <p class="admin-card-title" style="margin-bottom:1.25rem">Add Flower Color</p>

    <form method="POST" action="/admin/flower-colors">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

        <div style="display:grid; grid-template-columns:1fr 1fr auto auto auto auto; gap:0.875rem; align-items:end">

            <div class="admin-form-group" style="margin-bottom:0">
                <label for="new_name_en">Name (EN) <span style="color:#e53e3e">*</span></label>
                <input type="text" id="new_name_en" name="name_en" required placeholder="Red">
            </div>

            <div class="admin-form-group" style="margin-bottom:0">
                <label for="new_name_es">Name (ES)</label>
                <input type="text" id="new_name_es" name="name_es" placeholder="Rojo">
            </div>

            <div class="admin-form-group" style="margin-bottom:0">
                <label for="new_hex">Hex <span style="color:#999; font-weight:400; font-size:0.75rem">(optional)</span></label>
                <input type="text" id="new_hex" name="hex" placeholder="#D32F2F"
                       pattern="#[0-9A-Fa-f]{6}"
                       title="Six-digit hex code, e.g. #D32F2F"
                       style="width:120px; font-family:monospace">
            </div>

            <div class="admin-form-group" style="margin-bottom:0">
                <label for="new_sort_order">Sort</label>
                <input type="number" id="new_sort_order" name="sort_order" value="0" min="0"
                       style="width:80px">
            </div>

            <div class="admin-form-group" style="margin-bottom:0">
                <label style="white-space:nowrap">Active</label>
                <div style="padding:0.75rem 0">
                    <input type="checkbox" name="active" value="1" checked
                           style="width:16px; height:16px; accent-color:var(--color-primary)">
                </div>
            </div>

            <div style="padding-bottom:1px">
                <button type="submit" class="admin-btn admin-btn-primary">
                    Add Color
                </button>
            </div>

        </div>
    </form>
</div>

</div><!-- /.admin-content -->
