<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

/** @internal Optional read-only transport extension; existing Transport implementations remain compatible. */
interface SyncTransport extends Transport
{
    /** @return array<int,list<string>> Existing requested UIDs and their flags; absent UIDs were removed. */
    public function syncFlags(array $uids): array;
}
