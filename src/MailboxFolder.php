<?php
declare(strict_types=1);
namespace Phore\MailClient;

enum MailboxFolder: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Junk = 'junk';
}
