<?php
declare(strict_types=1);

use Phore\MailClient\Internal\ImapTransport;
use Phore\MailClient\Test\Provider\ReadOnlyProbe;

require dirname(__DIR__,2) . '/vendor/autoload.php';
require __DIR__ . '/ReadOnlyProbe.php';

// Live read-only checks: no APPEND, STORE, MOVE or attachment downloads.
// Official endpoint: https://hilfe.web.de/pop-imap/imap/imap-serverdaten.html
$email = getenv('EMAIL'); $password = getenv('EMAIL_PASSWD');
if ($email === false || $email === '' || $password === false || $password === '') {
    fwrite(STDERR,"Required GitHub secrets EMAIL and/or EMAIL_PASSWD are unavailable.\n"); exit(1);
}
$stage = 'TCP/TLS preflight';
// Avoid warnings containing server data; the catch reports only our fixed stage name.
set_error_handler(static function (int $severity, string $message): never {
    throw new RuntimeException('Provider runtime warning.');
});
try {
    $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'imap.web.de']]);
    $socket = @stream_socket_client('tls://imap.web.de:993',$errno,$error,20,STREAM_CLIENT_CONNECT,$context);
    if ($socket === false) { throw new RuntimeException('TLS preflight failed.'); }
    fclose($socket);
    echo "PASS: WEB.DE TCP/TLS preflight with certificate verification.\n";
    $stage = 'IMAP authentication';
    $transport = new ImapTransport('imap.web.de',$email,$password,993);
    echo "PASS: WEB.DE IMAP TLS connection and authentication succeeded.\n";
    $stage = 'read-only INBOX/MIME/cursor/flag checks';
    $account = hash('sha256','imap.web.de:993:' . $email);
    foreach (ReadOnlyProbe::run($transport,$account) as $result) { echo $result . "\n"; }
} catch (Throwable $exception) {
    // Never print exception messages, usernames, responses, credentials or traces.
    fwrite(STDERR,"FAIL: WEB.DE " . $stage . ". No message data logged.\n"); exit(1);
}
