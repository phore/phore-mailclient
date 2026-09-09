<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class MailAddress implements Stringable
{
    public function __construct(public string $address, public ?string $name = null)
    {
        if (str_contains($address, "\r") || str_contains($address, "\n") || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        if ($name !== null && (str_contains($name, "\r") || str_contains($name, "\n"))) {
            throw new InvalidArgumentException('Display names must not contain line breaks.');
        }
    }

    public function __toString(): string
    {
        return $this->name === null || $this->name === ''
            ? $this->address
            : sprintf('%s <%s>', $this->name, $this->address);
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->address, $other->address) === 0;
    }
}
