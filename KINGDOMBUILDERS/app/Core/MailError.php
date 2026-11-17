<?php
declare(strict_types=1);
namespace KB;

/** Safe, credential-free SMTP failure information for administrators and the queue. */
final class MailError extends \RuntimeException {
    public function __construct(string $message, public readonly string $stage, public readonly ?int $smtpCode = null, public readonly bool $retryable = true) {
        parent::__construct($message);
    }
}
