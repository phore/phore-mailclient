<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use DateTimeImmutable;

final readonly class MailSearch
{
    private function __construct(
        public ?string $fromAddress = null,
        public ?string $subject = null,
        public ?DateTimeImmutable $sinceDate = null,
    ) {}

    public static function all(): self { return new self(); }

    public static function from(string $address): self
    {
        return new self(fromAddress: (new MailAddress($address))->address);
    }

    public function subjectContains(string $subject): self
    {
        return new self($this->fromAddress, $subject, $this->sinceDate);
    }

    public function since(DateTimeImmutable $date): self
    {
        return new self($this->fromAddress, $this->subject, $date);
    }
}
