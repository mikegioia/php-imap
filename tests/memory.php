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

$pid = getmypid();
$message = <<<STR
My PID is: $pid
Run this in another terminal:
$> watch -n 1 ps -o vsz $pid

STR;
echo $message;
for ( $i = 9; $i >= 1; $i-- ) {
    echo "\r$i";
    sleep( 1 );
}
echo "\rStarting...", PHP_EOL;

$index = 1;
$config = parse_ini_file( __DIR__ .'/secret.ini' );
$email = ( isset( $config[ 'email' ] ) ) ? $config[ 'email' ] : "";
$folder = ( isset( $config[ 'folder' ] ) ) ? $config[ 'folder' ] : "";
$password = ( isset( $config[ 'password' ] ) ) ? $config[ 'password' ] : "";
$mailbox = new \PhpImap\Mailbox(
    "imap.gmail.com",
    $email,
    $password,
    $folder,
    __DIR__ .'/attachments',
    TRUE );

$mailbox->debug( "Starting search" );
$messageIds = $mailbox->search( 'ALL' );
$count = count( $messageIds );
$mailbox->debug( "Fetched $count message IDs" );

$startFrom = 0;//$startFrom = 640;

foreach ( $messageIds as $messageId ) {
    if ( $messageId < $startFrom ) {
        $index++;
        continue;
    }
    $mailbox->debug( "Fetching message $index of $count" );
    // Get the info, headers, and flags
    $info = $mailbox->getMessageInfo( $messageId );
    $mailbox->debug( "After message info was fetched" );
    $info = NULL;
    unset( $info );
    $mailbox->debug( "After info was unset()" );
    // Pull the contents of the message
    $message = $mailbox->getMessage( $messageId );
    $mailbox->debug( "After message info was downloaded" );
    $message = NULL;
    unset( $message );
    $mailbox->debug( "After message was unset()" );
    // Cleanup
    $mailbox->debug( str_repeat( "-", 20 ) );
    $index++;
    gc_collect_cycles();
    usleep( 100000 );
}
