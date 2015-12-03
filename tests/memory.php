<?php

/**
 * Test script. This checks for memory leaks.
 */
include '../vendor/autoload.php';
gc_enable();

// Check if secret file exists. If not create a basic one
// and tell the user to save their password and email
// address in the file.
if ( ! file_exists( __DIR__ . '/secret.ini' ) ) {
    file_put_contents(
        __DIR__ . '/secret.ini',
        sprintf(
            "%s =\n%s =\n%s =",
            "email",
            "password",
            "folder"
        ));
    echo "Please edit the contents of secret.ini\n";
    exit;
}

// Create attachments folder if it doesn't exist
if ( ! file_exists( __DIR__ .'/attachments' ) ) {
    mkdir( __DIR__ .'/attachments', TRUE );
}

$index = 1;
$config = parse_ini_file( __DIR__ .'/secret.ini' );
$email = ( isset( $config[ 'email' ] ) ) ? $config[ 'email' ] : "";
$folder = ( isset( $config[ 'folder' ] ) ) ? $config[ 'folder' ] : "";
$password = ( isset( $config[ 'password' ] ) ) ? $config[ 'password' ] : "";
$mailbox = new \PhpImap\Mailbox(
    "{imap.gmail.com:993/imap/ssl}$folder",
    $email,
    $password,
    __DIR__ .'/attachments',
    "UTF-8",
    TRUE );

$mailbox->debug( "Starting search" );
$mailIds = $mailbox->searchMailBox( 'ALL' );
$count = count( $mailIds );
$mailbox->debug( "Fetched $count mailbox IDs" );

foreach ( $mailIds as $mailId ) {
    $mailbox->debug( "Fetching mail $index of $count" );
    $mail = $mailbox->getMail( $mailId );
    $mailbox->debug( "After mail was pulled" );
    unset( $mail );
    $mailbox->debug( "After mail was unset()" );
    $mailbox->debug( str_repeat( "-", 20 ) );
    $index++;
    gc_collect_cycles();
    usleep( 100000 );
}
