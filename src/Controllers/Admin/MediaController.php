<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;

/**
 * Admin controller for the image media library.
 *
 * Handles listing, uploading, and deleting product images stored under
 * public/uploads/products/. Uploaded files are validated for size, MIME type,
 * and optionally resized to MAX_WIDTH pixels using the Imagick extension when
 * it is available.
 *
 * All mutating actions (upload, delete) require a valid CSRF token. The delete
 * action also applies a path-traversal guard so that only files physically
 * inside UPLOAD_DIR can be removed.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Core\Request                Provides file(), post(), and validateCsrf().
 */
final class MediaController extends BaseController
{
    /** Filesystem path to the product-image upload directory. */
    public const UPLOAD_DIR = __DIR__ . '/../../../public/uploads/products/';

    /** Maximum accepted upload size in bytes (20 MB). */
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** MIME types accepted for upload. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Maximum image width in pixels; wider images are resized down. */
    private const MAX_WIDTH = 1200;

    // -------------------------------------------------------------------------
    // GET /admin/media — media library page
    // -------------------------------------------------------------------------

    /**
     * Render the media library index page listing all uploaded product images.
     *
     * Scans UPLOAD_DIR for JPEG, PNG, and WebP files and passes them to the
     * view sorted newest-first so recently uploaded images appear at the top.
     *
     * @param Request              $request  Incoming HTTP request (unused but required by the router contract).
     * @param array<string,string> $_params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/media/index.
     *
     * @example
     *   (new MediaController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $images    = self::scanImages();
        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/media/index', [
                'images'    => $images,
                'csrfToken' => $csrfToken,
                'pageTitle' => 'Media Library',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/media/list — JSON image list (for dynamic reloads)
    // -------------------------------------------------------------------------

    /**
     * Return a JSON array of all product images currently in UPLOAD_DIR.
     *
     * Each entry contains 'filename', 'url', and 'bytes' keys. Images are
     * ordered newest-first by filesystem modification time.
     *
     * @param Request              $request  Incoming HTTP request (unused but required by the router contract).
     * @param array<string,string> $_params  Route parameters (none for this route).
     *
     * @return Response JSON array of image descriptors.
     *
     * @example
     *   (new MediaController())->list($request, []);
     *   // [{"filename":"product_abc.jpg","url":"/public/uploads/products/product_abc.jpg","bytes":48210}, ...]
     */
    public function list(Request $request, array $_params = []): Response
    {
        return Response::json(self::scanImages());
    }

    // -------------------------------------------------------------------------
    // POST /admin/media/upload — handle file upload
    // -------------------------------------------------------------------------

    /**
     * Accept a multipart file upload, validate it, move it to UPLOAD_DIR, and
     * optionally resize it to MAX_WIDTH.
     *
     * Validation steps, each returning a 422 JSON error on failure:
     *   1. CSRF token must be valid.
     *   2. A file must be present in the 'image' field.
     *   3. PHP must have received the file without error (UPLOAD_ERR_OK).
     *   4. File size must not exceed MAX_BYTES (5 MB).
     *   5. MIME type detected by mime_content_type() must be in ALLOWED_MIME.
     *
     * On success the file is moved to UPLOAD_DIR, resized if Imagick is
     * available, and the new filename, public URL, and byte size are returned.
     *
     * @param Request              $request  HTTP request; expects multipart 'image' file field.
     * @param array<string,string> $_params  Route parameters (none for this route).
     *
     * @return Response JSON object with 'success', 'filename', 'url', and 'bytes' on success,
     *                  or 'success' => false and 'error' string on failure.
     *
     * @example
     *   // Successful upload
     *   // {"success":true,"filename":"product_abc123.jpg","url":"/public/uploads/products/product_abc123.jpg","bytes":48210}
     *   // Validation failure
     *   // {"success":false,"error":"Image must be smaller than 5 MB."}
     */
    public function upload(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['success' => false, 'error' => 'Invalid security token.'], 422);
        }

        $file = $request->file('image');

        if ($file === null) {
            return Response::json(['success' => false, 'error' => 'No file uploaded.'], 422);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['success' => false, 'error' => 'Upload failed (PHP error code ' . $file['error'] . ').'], 422);
        }

        if ($file['size'] > self::MAX_BYTES) {
            return Response::json(['success' => false, 'error' => 'Image must be smaller than 20 MB.'], 422);
        }

        $mime = mime_content_type($file['tmp_name']);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return Response::json(['success' => false, 'error' => 'Only JPEG, PNG, and WebP images are allowed.'], 422);
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        };

        $filename = uniqid('product_', true) . '.' . $ext;

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], self::UPLOAD_DIR . $filename)) {
            return Response::json(['success' => false, 'error' => 'Failed to save the uploaded file.'], 422);
        }

        self::resizeImage(self::UPLOAD_DIR . $filename);

        return Response::json([
            'success'  => true,
            'filename' => $filename,
            'url'      => '/public/uploads/products/' . $filename,
            'bytes'    => filesize(self::UPLOAD_DIR . $filename),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/media/delete — delete a single image
    // -------------------------------------------------------------------------

    /**
     * Permanently delete a product image from UPLOAD_DIR.
     *
     * Applies two layers of safety:
     *   1. basename() is called on the submitted filename to strip any directory
     *      components before the path is assembled.
     *   2. realpath() comparison verifies the resolved path still sits inside
     *      UPLOAD_DIR, blocking any remaining path-traversal attempts.
     *
     * Returns 404 JSON if the named file does not exist; 200 JSON on success.
     *
     * @param Request              $request  HTTP request; expects POST field 'filename'.
     * @param array<string,string> $_params  Route parameters (none for this route).
     *
     * @return Response JSON object with 'success' true, or 'success' => false and 'error' string.
     *
     * @example
     *   // Success
     *   // {"success":true}
     *   // File not found
     *   // {"success":false,"error":"File not found."}
     */
    public function delete(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['success' => false, 'error' => 'Invalid security token.'], 422);
        }

        $filename = basename(trim((string) $request->post('filename', '')));

        if ($filename === '') {
            return Response::json(['success' => false, 'error' => 'No filename given.'], 422);
        }

        $path      = self::UPLOAD_DIR . $filename;
        $uploadDir = realpath(self::UPLOAD_DIR);
        $realPath  = realpath($path);

        // Path-traversal guard: the resolved path must start with UPLOAD_DIR.
        if ($realPath === false || $uploadDir === false || strncmp($realPath, $uploadDir, strlen($uploadDir)) !== 0) {
            return Response::json(['success' => false, 'error' => 'Invalid filename.'], 422);
        }

        if (!is_file($path)) {
            return Response::json(['success' => false, 'error' => 'File not found.'], 404);
        }

        unlink($path);

        return Response::json(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Scan UPLOAD_DIR for product images and return them sorted newest-first.
     *
     * Matches files with extensions jpg, jpeg, png, and webp using glob's
     * GLOB_BRACE flag. Each entry in the returned array contains the bare
     * filename, the public URL path, and the file size in bytes.
     *
     * @return array<int, array{filename: string, url: string, bytes: int}> Sorted image descriptors.
     *
     * @example
     *   $images = self::scanImages();
     *   // [['filename' => 'product_abc.jpg', 'url' => '/public/uploads/products/product_abc.jpg', 'bytes' => 48210], ...]
     */
    private static function scanImages(): array
    {
        $files = glob(self::UPLOAD_DIR . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return array_map(
            fn ($f) => [
                'filename' => basename($f),
                'url'      => '/public/uploads/products/' . basename($f),
                'bytes'    => filesize($f),
            ],
            $files
        );
    }

    /**
     * Resize an image file in-place to MAX_WIDTH pixels wide using Imagick.
     *
     * Does nothing if the Imagick extension is not loaded, the image is already
     * within MAX_WIDTH, or an ImagickException is thrown during processing.
     * The aspect ratio is always preserved (height is set to 0 / auto).
     *
     * @param string $path Absolute filesystem path to the image file to resize.
     *
     * @return void
     *
     * @example
     *   self::resizeImage('/var/www/public/uploads/products/product_abc.jpg');
     */
    private static function resizeImage(string $path): void
    {
        if (!extension_loaded('imagick')) {
            return;
        }

        try {
            $img = new \Imagick($path);

            if ($img->getImageWidth() <= self::MAX_WIDTH) {
                $img->destroy();
                return;
            }

            $img->resizeImage(self::MAX_WIDTH, 0, \Imagick::FILTER_LANCZOS, 1);
            $img->writeImage($path);
            $img->destroy();
        } catch (\ImagickException) {
            // Silently ignore resize failures — the original file is already saved.
        }
    }
}
