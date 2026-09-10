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
    $client = MailClient::connect(host:'imap.web.de',username:$email,password:$password,port:993,mode:MailClient::MODE_MANUAL);
    echo "PASS: WEB.DE IMAP TLS connection and authentication succeeded. No messages accessed.\n";
} catch (Throwable) {
    // Do not print exceptions, usernames, server responses, credentials or traces.
    fwrite(STDERR,"FAIL: WEB.DE TLS connection or authentication failed. Check IMAP access, credentials/app password and runner connectivity.\n"); exit(1);
}
