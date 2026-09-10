<?php
declare(strict_types=1);

use Phore\MailClient\MailClient;

require dirname(__DIR__,2) . '/vendor/autoload.php';

// Separate provider smoke test: no SELECT, FETCH, APPEND, STORE or MOVE.
// Official endpoint: https://hilfe.web.de/pop-imap/imap/imap-serverdaten.html
$email = getenv('EMAIL'); $password = getenv('EMAIL_PASSWD');
if ($email === false || $email === '' || $password === false || $password === '') {
    fwrite(STDERR,"Required GitHub secrets EMAIL and/or EMAIL_PASSWD are unavailable.\n"); exit(1);
}
try {
    $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'imap.web.de']]);
    $socket = @stream_socket_client('tls://imap.web.de:993',$errno,$error,20,STREAM_CLIENT_CONNECT,$context);
    if ($socket === false) {
        fwrite(STDERR,"FAIL: WEB.DE TCP/TLS preflight failed; authentication was not attempted.\n"); exit(1);
    }
    fclose($socket);
    echo "PASS: WEB.DE TCP/TLS preflight with certificate verification.\n";
    $client = MailClient::connect(host:'imap.web.de',username:$email,password:$password,port:993,mode:MailClient::MODE_MANUAL);
    echo "PASS: WEB.DE IMAP TLS connection and authentication succeeded. No messages accessed.\n";
} catch (Throwable $exception) {
    // Do not print exceptions, usernames, server responses, credentials or traces.
    if (str_starts_with($exception->getMessage(),'IMAP connection failed: ')) { fwrite(STDERR,$exception->getMessage() . "\n"); }
    fwrite(STDERR,"FAIL: WEB.DE TLS connection or authentication failed. Check IMAP access, credentials/app password and runner connectivity.\n"); exit(1);
}
