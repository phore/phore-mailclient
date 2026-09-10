#!/usr/bin/env bash
set -euo pipefail
sudo apt-get update -qq
sudo apt-get install -y dovecot-imapd
sudo systemctl stop dovecot
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' -keyout /tmp/phore-key.pem -out /tmp/phore-cert.pem
sudo cp /tmp/phore-cert.pem /usr/local/share/ca-certificates/phore-test.crt
sudo update-ca-certificates
sudo mkdir -p /tmp/phore-mail
sudo chown nobody:nogroup /tmp/phore-mail
printf 'test:{PLAIN}test-secret\n' > /tmp/phore-users
cat > /tmp/phore-dovecot.conf <<'CONF'
protocols = imap
listen = 127.0.0.1
ssl = required
ssl_cert = </tmp/phore-cert.pem
ssl_key = </tmp/phore-key.pem
auth_mechanisms = plain login
mail_location = maildir:/tmp/phore-mail/Maildir
first_valid_uid = 1
log_path = /tmp/phore-dovecot.log
passdb {
  driver = passwd-file
  args = /tmp/phore-users
}
userdb {
  driver = static
  args = uid=nobody gid=nogroup home=/tmp/phore-mail
}
namespace inbox {
  inbox = yes
  separator = /
  mailbox Drafts {
    auto = create
    special_use = \Drafts
  }
  mailbox Trash {
    auto = create
    special_use = \Trash
  }
}
service imap-login {
  inet_listener imap {
    port = 0
  }
  inet_listener imaps {
    port = 1993
    ssl = yes
  }
}
CONF
sudo dovecot -c /tmp/phore-dovecot.conf
