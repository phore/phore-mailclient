<?php
declare(strict_types=1);
namespace Phore\MailClient;

/** Discard the folder's cached IDs and deliberately restart with cursor: null. */
final class SyncResetRequired extends \RuntimeException
{
    public function __construct(public readonly string $folder)
    { parent::__construct('Folder UIDVALIDITY changed; discard cached folder IDs and restart synchronization explicitly.'); }
}
