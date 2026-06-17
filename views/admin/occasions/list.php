<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Occasions</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($occasions) ?> occasion<?= count($occasions) !== 1 ? 's' : '' ?></p>
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
     EXISTING OCCASIONS TABLE
     ============================================================ -->
<div class="admin-card" style="padding:0; margin-bottom:2rem">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="padding-left:1.5rem">Name (EN)</th>
                    <th>Name (ES)</th>
                    <th>Slug</th>
                    <th style="text-align:center">Sort</th>
                    <th style="text-align:center">Status</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
                <?php foreach ($occasions as $occ): ?>
                <tbody x-data="<?= htmlspecialchars(json_encode([
                        'editing'   => false,
                        'nameEn'    => $occ['name_en'],
                        'nameEs'    => $occ['name_es'] ?? '',
                        'slug'      => $occ['slug'],
                        'sortOrder' => (int) $occ['sort_order'],
                        'active'    => (bool) $occ['active'],
                        'headingEn' => $occ['heading_en'] ?? '',
                        'headingEs' => $occ['heading_es'] ?? '',
                        'blurbEn'   => $occ['blurb_en'] ?? '',
                        'blurbEs'   => $occ['blurb_es'] ?? '',
                    ]), ENT_QUOTES, 'UTF-8') ?>">
                <tr>

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
                        <code x-show="!editing" style="font-size:0.8rem; background:#f4f6f9; padding:0.2rem 0.4rem; border-radius:3px" x-text="slug"></code>
                        <input x-show="editing" type="text" x-model="slug" placeholder="slug"
                               style="padding:0.4rem 0.6rem; border:1.5px solid #dde1e7; border-radius:4px; font-size:0.875rem; width:100%; font-family:monospace">
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
                              action="/admin/occasions/<?= (int) $occ['id'] ?>"
                              x-show="editing"
                              style="display:inline-block">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                            <input type="hidden" name="name_en" :value="nameEn">
                            <input type="hidden" name="name_es" :value="nameEs">
                            <input type="hidden" name="slug" :value="slug">
                            <input type="hidden" name="sort_order" :value="sortOrder">
                            <input type="hidden" name="active" :value="active ? '1' : '0'">
                            <input type="hidden" name="heading_en" :value="headingEn">
                            <input type="hidden" name="heading_es" :value="headingEs">
                            <input type="hidden" name="blurb_en"   :value="blurbEn">
                            <input type="hidden" name="blurb_es"   :value="blurbEs">
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
                              action="/admin/occasions/<?= (int) $occ['id'] ?>/delete"
                              style="display:inline-block; margin-left:0.375rem"
                              x-show="!editing"
                              data-confirm="Delete occasion &quot;<?= htmlspecialchars(addslashes($occ['name_en'])) ?>&quot;? This cannot be undone.">
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

                <!-- Inline copy editor (shares the row's Alpine state) -->
                <tr x-show="editing" x-cloak>
                    <td colspan="6" style="padding:0.25rem 1.5rem 1.25rem; background:#fafbfc">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem 1rem">
                            <div class="admin-form-group" style="margin:0">
                                <label>Heading (EN) <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">— page title; defaults to the name</span></label>
                                <input type="text" x-model="headingEn" placeholder="Anniversary Flowers" style="width:100%">
                            </div>
                            <div class="admin-form-group" style="margin:0">
                                <label>Heading (ES)</label>
                                <input type="text" x-model="headingEs" placeholder="Flores de Aniversario" style="width:100%">
                            </div>
                            <div class="admin-form-group" style="margin:0">
                                <label>Blurb (EN) <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">— intro / meta description</span></label>
                                <textarea x-model="blurbEn" rows="2" style="width:100%"></textarea>
                            </div>
                            <div class="admin-form-group" style="margin:0">
                                <label>Blurb (ES)</label>
                                <textarea x-model="blurbEs" rows="2" style="width:100%"></textarea>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
                <?php endforeach; ?>

                <?php if (empty($occasions)): ?>
                <tbody>
                <tr>
                    <td colspan="6" style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        No occasions yet. Use the form below to add one.
                    </td>
                </tr>
                </tbody>
                <?php endif; ?>
        </table>
    </div>
</div>

<!-- ============================================================
     ADD OCCASION FORM
     ============================================================ -->
<div class="admin-card">
    <p class="admin-card-title" style="margin-bottom:1.25rem">Add Occasion</p>

    <form method="POST" action="/admin/occasions">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

        <div style="display:grid; grid-template-columns:1fr 1fr 1fr auto auto auto; gap:0.875rem; align-items:end">

            <div class="admin-form-group" style="margin-bottom:0">
                <label for="new_name_en">Name (EN) <span style="color:#e53e3e">*</span></label>
                <input type="text" id="new_name_en" name="name_en" required placeholder="Birthday">
            </div>

            <div class="admin-form-group" style="margin-bottom:0">
                <label for="new_name_es">Name (ES)</label>
                <input type="text" id="new_name_es" name="name_es" placeholder="Cumpleaños">
            </div>

            <div class="admin-form-group" style="margin-bottom:0">
                <label for="new_slug">Slug <span style="color:#e53e3e">*</span></label>
                <input type="text" id="new_slug" name="slug" required placeholder="birthday"
                       pattern="[a-z0-9-]+" title="lowercase letters, numbers, and hyphens only"
                       style="font-family:monospace">
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

        </div>

        <!-- Optional page copy (heading + blurb, EN/ES) shown on the occasion page -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem 1rem; margin-top:1rem">
            <div class="admin-form-group" style="margin:0">
                <label for="new_heading_en">Heading (EN) <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">— page title; defaults to the name</span></label>
                <input type="text" id="new_heading_en" name="heading_en" placeholder="Birthday Flowers">
            </div>
            <div class="admin-form-group" style="margin:0">
                <label for="new_heading_es">Heading (ES)</label>
                <input type="text" id="new_heading_es" name="heading_es" placeholder="Flores de Cumpleaños">
            </div>
            <div class="admin-form-group" style="margin:0">
                <label for="new_blurb_en">Blurb (EN) <span style="color:#999; font-weight:400; text-transform:none; letter-spacing:0">— intro / meta description</span></label>
                <textarea id="new_blurb_en" name="blurb_en" rows="2"></textarea>
            </div>
            <div class="admin-form-group" style="margin:0">
                <label for="new_blurb_es">Blurb (ES)</label>
                <textarea id="new_blurb_es" name="blurb_es" rows="2"></textarea>
            </div>
        </div>

        <div style="margin-top:1.25rem">
            <button type="submit" class="admin-btn admin-btn-primary">
                Add Occasion
            </button>
        </div>
    </form>
</div>

</div><!-- /.admin-content -->
