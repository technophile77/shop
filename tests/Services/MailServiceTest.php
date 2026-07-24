<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Services\MailService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MailService.
 *
 * Covers the parts that can be exercised without opening a connection: the
 * transport wiring applied to a PHPMailer instance, the sendmail_path parsing
 * that decides whether the fallback transport is available at all, and the
 * branded HTML wrapper. The network-dependent send() loop is verified against
 * the live host instead — see CLAUDE.md's deployment notes.
 *
 * @see \App\Services\MailService
 */
class MailServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // configureSmtp()
    // -------------------------------------------------------------------------

    public function testConfigureSmtpSelectsSmtpTransport(): void
    {
        $mail = new PHPMailer();
        MailService::configureSmtp($mail, [
            'host' => 'mail.example.com',
            'port' => 2525,
            'user' => 'sender@example.com',
            'pass' => 'secret',
        ]);

        $this->assertSame('smtp', $mail->Mailer);
        $this->assertSame('mail.example.com', $mail->Host);
        $this->assertSame(2525, $mail->Port);
        $this->assertSame('sender@example.com', $mail->Username);
        $this->assertTrue($mail->SMTPAuth);
    }

    public function testConfigureSmtpRequestsStartTls(): void
    {
        // Submission credentials must never cross an unencrypted link, so the
        // STARTTLS upgrade is not optional on this transport.
        $mail = new PHPMailer();
        MailService::configureSmtp($mail, [
            'host' => 'mail.example.com',
            'port' => 587,
            'user' => 'sender@example.com',
            'pass' => 'secret',
        ]);

        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $mail->SMTPSecure);
    }

    // -------------------------------------------------------------------------
    // configureSendmail()
    // -------------------------------------------------------------------------

    public function testConfigureSendmailSelectsSendmailTransport(): void
    {
        $mail = new PHPMailer();
        MailService::configureSendmail($mail);

        $this->assertSame('sendmail', $mail->Mailer);
    }

    public function testSendmailTransportCarriesNoCredentials(): void
    {
        // The whole point of the fallback is that no password goes on the wire.
        $mail = new PHPMailer();
        MailService::configureSendmail($mail);

        $this->assertSame('', $mail->Password);
        $this->assertFalse($mail->SMTPAuth);
    }

    // -------------------------------------------------------------------------
    // sendmailBinary()
    // -------------------------------------------------------------------------

    public function testSendmailBinaryStripsArguments(): void
    {
        $this->assertSame(
            '/usr/sbin/sendmail',
            MailService::sendmailBinary('/usr/sbin/sendmail -t -i')
        );
    }

    public function testSendmailBinaryHandlesPathWithoutArguments(): void
    {
        $this->assertSame(
            '/usr/lib/sendmail',
            MailService::sendmailBinary('/usr/lib/sendmail')
        );
    }

    public function testSendmailBinaryHandlesTabSeparatedArguments(): void
    {
        $this->assertSame(
            '/usr/sbin/sendmail',
            MailService::sendmailBinary("/usr/sbin/sendmail\t-t -i")
        );
    }

    public function testSendmailBinaryTrimsSurroundingWhitespace(): void
    {
        $this->assertSame(
            '/usr/sbin/sendmail',
            MailService::sendmailBinary('  /usr/sbin/sendmail -t -i  ')
        );
    }

    public function testSendmailBinaryReturnsEmptyForUnsetPath(): void
    {
        $this->assertSame('', MailService::sendmailBinary(''));
    }

    public function testSendmailBinaryReturnsEmptyForWhitespaceOnlyPath(): void
    {
        // A whitespace-only value would otherwise yield a '' binary that
        // is_executable() could not distinguish from a genuine path.
        $this->assertSame('', MailService::sendmailBinary("  \t "));
    }

    // -------------------------------------------------------------------------
    // sendmailAvailable()
    // -------------------------------------------------------------------------

    public function testSendmailAvailableAgreesWithTheParsedIniPath(): void
    {
        // Derive the expectation from the INI value directly rather than from
        // the method under test, so this stays a real check on both machines
        // (sendmail_path is set on the pair.com host, empty on Windows).
        $binary   = MailService::sendmailBinary((string) ini_get('sendmail_path'));
        $expected = $binary !== '' && is_executable($binary);

        $this->assertSame($expected, MailService::sendmailAvailable());
    }

    // -------------------------------------------------------------------------
    // buildHtml()
    // -------------------------------------------------------------------------

    public function testBuildHtmlEmbedsContentVerbatim(): void
    {
        // Content is composed by the app, not by a visitor — callers escape
        // their own interpolations, so the wrapper must not double-escape.
        $html = MailService::buildHtml('<p>Deposit &amp; balance confirmed</p>');

        $this->assertStringContainsString('<p>Deposit &amp; balance confirmed</p>', $html);
    }

    public function testBuildHtmlProducesACompleteDocument(): void
    {
        $html = MailService::buildHtml('<p>Hi</p>');

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<meta charset="UTF-8">', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testBuildHtmlIncludesTheUnsubscribeContextFooter(): void
    {
        $html = MailService::buildHtml('<p>Hi</p>');

        $this->assertStringContainsString('All rights reserved', $html);
        $this->assertStringContainsString('you have interacted with us', $html);
    }
}
