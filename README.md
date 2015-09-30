ImapMailbox is PHP class to access mailbox by POP3/IMAP/NNTP using IMAP extension

### Features

* Connect to mailbox by POP3/IMAP/NNTP (see [imap_open](http://php.net/imap_open))
* Get mailbox status (see [imap_check](http://php.net/imap_check))
* Receive emails (+attachments, +html body images)
* Search emails by custom criteria (see [imap_search](http://php.net/imap_search))
* Change email status (see [imap_setflag_full](http://php.net/imap_setflag_full))
* Delete email

### Installation by Composer

    {
        "require": {
            "mikegioia/php-imap": "~2.0"
        }
    }

Or

    $ composer require mikegioia/php-imap ~2.0

### [Usage Example](https://github.com/mikegioia/php-imap/blob/master/example/index.php)

```php
$mailbox = new \PhpImap\Mailbox(
    '{imap.gmail.com:993/imap/ssl}INBOX',
    'some@gmail.com',
    '*********',
    __DIR__ );
$mailIds = $mailbox->searchMailBox( 'ALL' );

if ( !$mailIds ) {
    die( 'Mailbox is empty' );
}

$mailId = reset( $mailIds );
$mail = $mailbox->getMail( $mailId );

var_dump( $mail );
var_dump( $mail->getAttachments() );
```

### Notes

This project was forked from Sergey Barbushin's PHP-IMAP library because I
wanted something formatted in my coding style, to use namespaces, and to
use conventions of my own. I also wanted a way to change the default path
that attachments were stored so this includes a fix for that as well.

This is in no way intended as a suggested
alternative to Sergey's library, but I did update the licensing and copyright
info to reflect that modifications henceforward are made by the new authors.

This will diverge and probably never incorporate future changes from the
parent project, but I feel it's complete enough that that doesn't matter.
This project retains the original BSD 3-Clause license.