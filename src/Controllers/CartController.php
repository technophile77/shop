<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Addon;
use App\Models\FlowerColor;
use App\Models\FlowerType;
use App\Models\FlowerTypeColor;
use App\Models\PaperColor;
use App\Models\Product;
use App\Models\ProductFlowerType;
use App\Support\Analytics;
use App\Support\CartPricing;
use App\Support\CartSession;
use App\Support\CartValidation;
use App\Support\Destination;
use App\Support\FlowerColorResolver;
use App\Support\Shop;

/**
 * Handles the customer shopping cart for direct-purchase bouquets.
 *
 * Routes served:
 *   GET  /cart          — view cart contents.
 *   POST /cart/add      — add a configured product line item.
 *   POST /cart/update   — change the qty of an existing line.
 *   POST /cart/remove   — remove a line from the cart.
 *
 * All state-changing actions validate the CSRF token before proceeding.
 * After POST actions the controller issues a 302 redirect to the lang-prefixed
 * cart GET URL so the browser does not re-submit on reload.
 *
 * The canonical line-item shape (consumed by Phase 4 checkout) is documented
 * in {@see \App\Support\Cart}.
 *
 * @see \App\Support\CartSession    Thin session wrapper for cart state.
 * @see \App\Support\CartPricing    Pricing helpers used in the view.
 * @see \App\Support\CartValidation Selection validation before add.
 * @see \App\Controllers\BaseController::render()
 */
final class CartController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /cart — view
    // -------------------------------------------------------------------------

    /**
     * Render the cart page with all current line items and pricing totals.
     *
     * Passes per-line totals (keyed by signature) and the cart subtotal so the
     * view does not need to call CartPricing directly. An empty-cart state is
     * handled inside the view.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response HTML cart page.
     *
     * @example
     *   (new CartController())->view($request, []);
     */
    public function view(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $items     = CartSession::items();
        $csrfToken = $request->csrfToken();

        // Pre-compute per-line totals keyed by signature for easy lookup in view.
        $lineTotals = [];
        foreach ($items as $item) {
            $sig              = $item['signature'] ?? '';
            $lineTotals[$sig] = CartPricing::lineTotal($item);
        }

        // Build id => localised-name maps so the view can render the colors and
        // paper stored on each line (lines store IDs only; catalogs are the
        // source of truth for display names).
        $nameOf = static function (array $rows) use ($lang): array {
            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row['id']] = $row['name_' . $lang] ?? $row['name_en'] ?? '';
            }
            return $map;
        };
        $flowerTypeNames  = $nameOf(FlowerType::allActive());
        $flowerColorNames = $nameOf(FlowerColor::allActive());
        $paperColorNames  = $nameOf(PaperColor::allActive());

        $html = $this->render('public/cart', [
            'items'            => $items,
            'lineTotals'       => $lineTotals,
            'subtotal'         => CartPricing::subtotal($items),
            'destination'      => Destination::get(),
            'lang'             => $lang,
            'csrfToken'        => $csrfToken,
            'pageTitle'        => __t('cart.heading'),
            'metaDesc'         => '',
            'flowerTypeNames'  => $flowerTypeNames,
            'flowerColorNames' => $flowerColorNames,
            'paperColorNames'  => $paperColorNames,
        ]);

        return Response::html($html);
    }

    // -------------------------------------------------------------------------
    // POST /cart/add
    // -------------------------------------------------------------------------

    /**
     * Add a configured bouquet line item to the cart.
     *
     * Steps:
     *  1. Validate CSRF token; on failure flash error and redirect back.
     *  2. Load the product; 404-redirect if missing or not buyable.
     *  3. Build the raw selection from POST data.
     *  4. Build the validation context from catalog models.
     *  5. Run CartValidation::validateSelection(); on errors flash and redirect.
     *  6. Assemble the canonical line item with price/name snapshots.
     *  7. Save via CartSession::addLine(); redirect to /{lang}/cart.
     *
     * POST fields expected:
     *   product_id       — int
     *   qty              — int (defaults to 1)
     *   paper_color_id   — int|'' (optional)
     *   color_ids[$typeId][] — int[] per flower type (array of checkboxes)
     *   addon_ids[]      — int[] selected add-on IDs
     *   addon_text[$addonId] — string custom text per add-on
     *
     * @param Request              $request HTTP request with POST body.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response 302 redirect (to cart on success, back on failure).
     *
     * @example
     *   (new CartController())->add($request, []);
     */
    public function add(Request $request, array $params = []): Response
    {
        $lang = Lang::current();

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/' . $lang . '/cart');
        }

        // ── Load product ──────────────────────────────────────────────────────
        $productId = (int) $request->post('product_id', 0);
        $product   = $productId > 0 ? Product::find($productId) : null;

        if ($product === null || !Shop::isBuyable($product)) {
            $this->setFlash('error', 'This product is not available for purchase.');
            return $this->redirect('/' . $lang . '/products');
        }

        // ── Build raw selection from POST ─────────────────────────────────────
        $qty = max(1, (int) $request->post('qty', 1));

        // color_ids[$typeId][] — posted as a nested array of checkboxes.
        $rawColorIds  = $request->post('color_ids', []);
        $rawColorIds  = is_array($rawColorIds) ? $rawColorIds : [];
        $rawAddonIds  = $request->post('addon_ids', []);
        $rawAddonIds  = is_array($rawAddonIds) ? $rawAddonIds : [];
        $rawAddonText = $request->post('addon_text', []);
        $rawAddonText = is_array($rawAddonText) ? $rawAddonText : [];
        $rawAddonQty  = $request->post('addon_qty', []);
        $rawAddonQty  = is_array($rawAddonQty) ? $rawAddonQty : [];

        $paperColorIdRaw = $request->post('paper_color_id', '');
        $paperColorId    = ($paperColorIdRaw !== '' && $paperColorIdRaw !== null)
            ? (int) $paperColorIdRaw
            : null;

        // Build the colors array: one entry per flower type submitted.
        $colorsSelection = [];
        foreach ($rawColorIds as $typeId => $colorIdList) {
            $typeId      = (int) $typeId;
            $colorIdList = FlowerColorResolver::normalizeIdList(
                is_array($colorIdList) ? $colorIdList : []
            );
            if ($typeId > 0) {
                $colorsSelection[] = [
                    'flower_type_id' => $typeId,
                    'color_ids'      => $colorIdList,
                    'mixed'          => count($colorIdList) > 1,
                ];
            }
        }

        // Build the addons array: one entry per checked add-on.
        $addonsSelection = [];
        foreach (FlowerColorResolver::normalizeIdList($rawAddonIds) as $addonId) {
            $customText = isset($rawAddonText[$addonId])
                ? trim((string) $rawAddonText[$addonId])
                : null;
            $quantity = isset($rawAddonQty[$addonId]) ? max(1, (int) $rawAddonQty[$addonId]) : 1;
            $addonsSelection[] = [
                'addon_id'    => $addonId,
                'custom_text' => ($customText !== '') ? $customText : null,
                'quantity'    => $quantity,
            ];
        }

        $selection = [
            'product_id'     => $productId,
            'qty'            => $qty,
            'paper_color_id' => $paperColorId,
            'colors'         => $colorsSelection,
            'addons'         => $addonsSelection,
        ];

        // ── Build validation context from catalog models ───────────────────────
        $typeIds     = ProductFlowerType::flowerTypeIdsForProduct($productId);
        $typeColorMap = FlowerTypeColor::map();
        $paperColors  = PaperColor::allActive();
        $paperColorIds = array_map('intval', array_column($paperColors, 'id'));
        $allAddons    = Addon::allActive();
        $addonsById   = [];
        foreach ($allAddons as $addon) {
            $addonsById[(int) $addon['id']] = $addon;
        }

        $flowerCount = (int) ($product['flower_count'] ?? 0);

        $context = [
            'productFlowerTypeIds' => $typeIds,
            'flowerTypeColorMap'   => $typeColorMap,
            'paperColorIds'        => $paperColorIds,
            'addonsById'           => $addonsById,
            'flowerCount'          => $flowerCount,
        ];

        // ── Validate selection ────────────────────────────────────────────────
        $errors = CartValidation::validateSelection($selection, $context);
        if ($errors !== []) {
            $this->setFlash('error', $errors[0]);
            // Redirect back to the occasion page for this product — use HTTP
            // Referer when available, else fall back to the products page.
            $back = $request->header('Referer') ?? ('/' . $lang . '/products');
            return $this->redirect($back);
        }

        // ── Assemble canonical line item with snapshots ───────────────────────
        $addonSnapshots = [];
        foreach ($addonsSelection as $a) {
            $addonId    = (int) ($a['addon_id'] ?? 0);
            $addonRow   = $addonsById[$addonId] ?? [];
            $customText = $a['custom_text'] ?? null;
            // Only quantity-enabled add-ons keep a quantity; others are forced to 1.
            $hasQuantity = !empty($addonRow['has_quantity']);
            $quantity    = $hasQuantity ? max(1, (int) ($a['quantity'] ?? 1)) : 1;
            $addonSnapshots[] = [
                'addon_id'    => $addonId,
                'name_en'     => (string) ($addonRow['name_en'] ?? ''),
                'name_es'     => isset($addonRow['name_es']) ? (string) $addonRow['name_es'] : null,
                'price'       => (float) ($addonRow['price'] ?? 0.0),
                'quantity'    => $quantity,
                'custom_text' => $customText,
            ];
        }

        $line = [
            'product_id'     => $productId,
            'name_en'        => (string) ($product['name_en'] ?? ''),
            'name_es'        => isset($product['name_es']) ? (string) $product['name_es'] : null,
            'image_path'     => isset($product['image_path']) ? (string) $product['image_path'] : null,
            'unit_price'     => round((float) ($product['price_from'] ?? 0.0), 2),
            'flower_count'   => $flowerCount > 0 ? $flowerCount : null,
            'qty'            => $qty,
            'paper_color_id' => $paperColorId,
            'colors'         => $colorsSelection,
            'addons'         => $addonSnapshots,
        ];

        CartSession::addLine($line);

        // Queue an add_to_cart / AddToCart event to fire on the /cart page this
        // redirects to (the one-time session queue survives the redirect).
        $_SESSION['analytics_pending'][] = Analytics::addToCart($line);

        return $this->redirect('/' . $lang . '/cart');
    }

    // -------------------------------------------------------------------------
    // POST /cart/update
    // -------------------------------------------------------------------------

    /**
     * Update the quantity of an existing cart line.
     *
     * Reads 'signature' and 'qty' from POST. Setting qty to 0 or negative
     * removes the line (delegated to CartSession::update via Cart::updateQty).
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response 302 redirect to /{lang}/cart.
     *
     * @example
     *   (new CartController())->update($request, []);
     */
    public function update(Request $request, array $params = []): Response
    {
        $lang = Lang::current();

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/' . $lang . '/cart');
        }

        $signature = (string) $request->post('signature', '');
        $qty       = (int) $request->post('qty', 0);

        if ($signature !== '') {
            CartSession::update($signature, $qty);
        }

        return $this->redirect('/' . $lang . '/cart');
    }

    // -------------------------------------------------------------------------
    // POST /cart/remove
    // -------------------------------------------------------------------------

    /**
     * Remove a line item from the cart.
     *
     * Reads 'signature' from POST and delegates to CartSession::remove().
     * No-ops silently when the signature is not found.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response 302 redirect to /{lang}/cart.
     *
     * @example
     *   (new CartController())->remove($request, []);
     */
    public function remove(Request $request, array $params = []): Response
    {
        $lang = Lang::current();

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/' . $lang . '/cart');
        }

        $signature = (string) $request->post('signature', '');

        if ($signature !== '') {
            CartSession::remove($signature);
        }

        return $this->redirect('/' . $lang . '/cart');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Store a flash message in the session for the next page render.
     *
     * @param 'success'|'error' $type    Severity level.
     * @param string            $message Human-readable message.
     *
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
