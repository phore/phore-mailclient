<?php
declare(strict_types=1);
namespace Phore\MailClient;
final readonly class EmailBatch
{
    /** @param list<Email> $emails */
    public function __construct(public array $emails, public string $nextCursor) {}
}
