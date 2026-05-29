<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Fluent HTTP response builder.
 *
 * Encapsulates the HTTP status code, response headers, and body. Callers
 * build a Response using the static factory methods or the constructor, then
 * call {@see send()} at the end of the request lifecycle to write headers and
 * body to the PHP output buffer.
 *
 * The builder is immutable-ish: the with* methods return the same instance
 * (fluent style) rather than cloning, which is appropriate for single-request
 * usage where an in-flight response is not shared.
 *
 * @example
 *   return Response::html('<h1>Hello</h1>');
 *   return Response::json(['ok' => true]);
 *   return Response::redirect('/admin');
 *   return Response::notFound();
 */
final class Response
{
    /**
     * @param string               $body    Raw response body (HTML, JSON, empty…).
     * @param int                  $status  HTTP status code, defaults to 200.
     * @param array<string,string> $headers Additional headers to send.
     */
    public function __construct(
        private string $body    = '',
        private int    $status  = 200,
        private array  $headers = [],
    ) {}

    // -----------------------------------------------------------------------
    // Static factories
    // -----------------------------------------------------------------------

    /**
     * Create an HTML response.
     *
     * Sets Content-Type to text/html; charset=UTF-8.
     *
     * @param string $content HTML body content.
     * @param int    $status  HTTP status code, defaults to 200.
     *
     * @return self Configured HTML response.
     *
     * @example
     *   return Response::html($this->render('home/index', $data));
     */
    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Create a JSON response.
     *
     * JSON-encodes $data with JSON_THROW_ON_ERROR and sets Content-Type to
     * application/json. Throws if the data cannot be encoded.
     *
     * @param mixed $data   Any JSON-serialisable value.
     * @param int   $status HTTP status code, defaults to 200.
     *
     * @return self Configured JSON response.
     *
     * @throws \JsonException When $data cannot be encoded as JSON.
     *
     * @example
     *   return Response::json(['quote_id' => $id, 'status' => 'created'], 201);
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new self($body, $status, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Create a redirect response.
     *
     * The Location header is set to $url. Use a relative path for same-site
     * redirects or an absolute URL for external redirects. Defaults to 302
     * (Found); pass 301 for permanent redirects.
     *
     * @param string $url    Destination URL or path, e.g. '/admin/login'.
     * @param int    $status HTTP redirect status code (301, 302, 303, 307, 308).
     *
     * @return self Configured redirect response.
     *
     * @example
     *   return Response::redirect('/admin/login');
     *   return Response::redirect('https://example.com', 301);
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, [
            'Location' => $url,
        ]);
    }

    /**
     * Create a 404 Not Found response with a plain-text body.
     *
     * @return self 404 response.
     *
     * @example
     *   return Response::notFound();
     */
    public static function notFound(): self
    {
        return self::html(
            '<!DOCTYPE html><html><body><h1>404 Not Found</h1>'
            . '<p>The page you requested could not be found.</p></body></html>',
            404,
        );
    }

    /**
     * Create a 500 Internal Server Error response with a plain-text body.
     *
     * @return self 500 response.
     *
     * @example
     *   return Response::serverError();
     */
    public static function serverError(): self
    {
        return self::html(
            '<!DOCTYPE html><html><body><h1>500 Internal Server Error</h1>'
            . '<p>Something went wrong. Please try again later.</p></body></html>',
            500,
        );
    }

    // -----------------------------------------------------------------------
    // Fluent mutators
    // -----------------------------------------------------------------------

    /**
     * Add or overwrite a single response header.
     *
     * @param string $name  Header name, e.g. 'Cache-Control'.
     * @param string $value Header value.
     *
     * @return self The same response instance for chaining.
     *
     * @example
     *   return Response::html($html)->withHeader('Cache-Control', 'no-store');
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Override the HTTP status code.
     *
     * @param int $status Any valid HTTP status code.
     *
     * @return self The same response instance for chaining.
     *
     * @example
     *   return Response::html($html)->withStatus(201);
     */
    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Replace the response body.
     *
     * @param string $body Raw response body.
     *
     * @return self The same response instance for chaining.
     *
     * @example
     *   $response->withBody('<p>Updated content</p>');
     */
    public function withBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    // -----------------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------------

    /**
     * Send the response to the client.
     *
     * Emits the HTTP status line via http_response_code(), sends all
     * accumulated headers via header(), then prints the body. Should be
     * called exactly once, at the very end of the request.
     *
     * @return void
     *
     * @example
     *   $response->send();
     */
    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
