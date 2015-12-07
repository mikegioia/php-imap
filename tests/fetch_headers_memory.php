<?php

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

$config = parse_ini_file( __DIR__ .'/secret.ini' );
$email = ( isset( $config[ 'email' ] ) ) ? $config[ 'email' ] : "";
$folder = ( isset( $config[ 'folder' ] ) ) ? $config[ 'folder' ] : "";
$password = ( isset( $config[ 'password' ] ) ) ? $config[ 'password' ] : "";

// Open the connection
$imapStream = imap_open(
    "{imap.gmail.com:993/imap/ssl}$folder",
    $email,
    $password );

if ( ! $imapStream ) {
    echo "Error: ", imap_last_error(), PHP_EOL;
    exit( 1 );
}

// For each mail ID call fetch headers and check the VSZ
// Use args sz,rss,vsz for more info.
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

// Search the folder for mail IDs
$mailIds = imap_search( $imapStream, 'ALL', SE_UID, 'UTF-8' );
$count = count( $mailIds );
echo "Start Memory: ", memory_get_usage( TRUE ), ", ";
echo "Start Peak: ", memory_get_peak_usage( TRUE ), PHP_EOL;

foreach ( $mailIds as $mailId ) {
    echo "\rMailID: $mailId / $count, ";
    echo "Memory: ", memory_get_usage( TRUE ), ", ";
    echo "Peak: ", memory_get_peak_usage( TRUE );
    //$headers = imap_fetchheader( $imapStream, $mailId, FT_UID );
    $headers = NULL;
    unset( $headers );
    usleep( 100000 );
}

echo "\n\nDone!\n";