<?php

namespace App\Support\MailPreviews;

use Closure;

/** One email as the preview page shows it: rendered in a language, and how to send it for real. */
final readonly class MailPreview
{
    public function __construct(
        public string $key,
        public string $label,
        public string $locale,
        public string $subject,
        public string $html,
        private Closure $sendUsing,
    ) {}

    public function sendTo(string $email): void
    {
        ($this->sendUsing)($email);
    }
}
