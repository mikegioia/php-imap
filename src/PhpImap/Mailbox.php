<?php

namespace PhpImap;

use stdClass
  , Exception
  , PhpImap\Imap
  , PhpImap\Mail
  , PhpImap\Attachment
  , Zend\Mail\Storage\Message
  , RecursiveIteratorIterator as Iterator
  , Zend\Mail\Exception\InvalidArgumentException;

/**
 * Author of this library:
 * @see https://github.com/mikegioia/php-imap
 * @author Mike Gioia http://mikegioia.com
 * Original author:
 * @see https://github.com/barbushin/php-imap
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 */
class Mailbox
{
    protected $imapHost;
    protected $imapLogin;
    protected $imapFolder;
    protected $imapPassword;
    protected $attachmentsDir;
    protected $imapParams = [];
    protected $imapOptions = 0;
    protected $imapRetriesNum = 0;

    private $debugMode = FALSE;

    // Internal reference to IMAP connection
    static protected $imapStream;

    /**
     * Sets up a new mailbox object with the IMAP credentials to connect.
     * @param string $hostname
     * @param string $login
     * @param string $password
     * @param string $folder
     * @param string $attachmentsDir
     * @param bool $debugMode
     */
    function __construct(
        $hostname,
        $login,
        $password,
        $folder = 'INBOX',
        $attachmentsDir = NULL,
        $debugMode = FALSE )
    {
        $this->imapLogin = $login;
        $this->imapHost = $hostname;
        $this->imapFolder = $folder;
        $this->debugMode = $debugMode;
        $this->imapPassword = $password;

        if ( $attachmentsDir ) {
            if ( ! is_dir( $attachmentsDir ) ) {
                throw new Exception( "Directory '$attachmentsDir' not found" );
            }

            $this->attachmentsDir = rtrim( realpath( $attachmentsDir ), '\\/' );
        }
    }

    /**
     * Get IMAP mailbox connection stream
     * @return NULL|Zend\Mail\Protocol\Imap
     */
    public function getImapStream()
    {
        if ( ! self::$imapStream ) {
            self::$imapStream = $this->initImapStream();
        }

        return self::$imapStream;
    }

    protected function initImapStream()
    {
        $imapStream = new Imap([
            'ssl' => 'SSL',
            'host' => $this->imapHost,
            'user' => $this->imapLogin,
            'folder' => $this->imapFolder,
            'password' => $this->imapPassword
        ]);

        if ( ! $imapStream ) {
            throw new Exception( 'Failed to connect to IMAP mailbox' );
        }

        return $imapStream;
    }

    protected function disconnect()
    {
        $imapStream = $this->getImapStream();

        if ( $imapStream ) {
            $imapStream->close();
        }
    }

    /**
     * Get information about the current mailbox. Flags and a count
     * of messages are returned in the response array.
     *
     * Returns the information in an object with following properties:
     *   Name - folder name
     *   Count - number of messages in mailbox
     *   Flags - any flags on the folder
     *
     * @return stdClass
     */
    public function status( $folder = NULL )
    {
        $info = new stdClass();
        $folder = $folder ?: $this->imapFolder;
        $examine = $this->getImapStream()->examineFolder( $folder );

        // Set response defaults
        $info->count = 0;
        $info->flags = [];
        $info->name = $folder;

        if ( ! $examine ) {
            return $info;
        }

        $info->flags = $examine[ 'flags' ];
        $info->count = $examine[ 'exists' ];

        return $info;
    }

    /**
     * Gets a listing of the folders in the selected mailbox.
     *
     * This function returns an object containing listing the folders.
     * The object has the following properties:
     *   messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return RecursiveIteratorIterator
     */
    public function getFolders( $rootFolder = NULL )
    {
        $rootFolder = $rootFolder ?: $this->imapFolder;
        $folders = $this->getImapStream()->getFolders( $rootFolder );

        if ( ! $folders ) {
            return [];
        }

        return new Iterator( $folders, Iterator::SELF_FIRST );
    }

    /**
     * This function performs a search on the mailbox currently opened in the
     * given IMAP stream. For example, to match all unanswered mails sent by
     * Mom, you'd use: "UNANSWERED FROM mom". Searches appear to be case
     * insensitive. This list of criteria is from a reading of the UW c-client
     * source code and may be incomplete or inaccurate (see also RFC2060,
     * section 6.4.4).
     *
     * @param string $criteria String, delimited by spaces, in which the
     * following keywords are allowed. Any multi-word arguments (e.g. FROM
     * "joey smith") must be quoted. Results will match all criteria entries.
     *
     *   ALL - return all mails matching the rest of the criteria
     *   ANSWERED - match mails with the \\ANSWERED flag set
     *   BCC "string" - match mails with "string" in the Bcc: field
     *   BEFORE "date" - match mails with Date: before "date"
     *   BODY "string" - match mails with "string" in the body of the mail
     *   CC "string" - match mails with "string" in the Cc: field
     *   DELETED - match deleted mails
     *   FLAGGED - match mails with the \\FLAGGED (sometimes referred to as
     *     Important or Urgent) flag set
     *   FROM "string" - match mails with "string" in the From: field
     *   KEYWORD "string" - match mails with "string" as a keyword
     *   NEW - match new mails
     *   OLD - match old mails
     *   ON "date" - match mails with Date: matching "date"
     *   RECENT - match mails with the \\RECENT flag set
     *   SEEN - match mails that have been read (the \\SEEN flag is set)
     *   SINCE "date" - match mails with Date: after "date"
     *   SUBJECT "string" - match mails with "string" in the Subject:
     *   TEXT "string" - match mails with text "string"
     *   TO "string" - match mails with "string" in the To:
     *   UNANSWERED - match mails that have not been answered
     *   UNDELETED - match mails that are not deleted
     *   UNFLAGGED - match mails that are not flagged
     *   UNKEYWORD "string" - match mails that do not have the keyword "string"
     *   UNSEEN - match mails which have not been read yet
     *
     * @return array Mail IDs
     */
    public function search( $criteria = 'ALL' )
    {
        $mailIds = $this->getImapStream()->search([ $criteria ]);

        return $mailIds ?: [];
    }

    /**
     * Returns a count of messages for the selected folder.
     * @param array $flags
     * @return integer
     */
    public function count( $flags = NULL )
    {
        return $this->getImapStream()->countMessages( $flags );
    }

    /**
     * Fetch mail headers for listed mail IDs. Returns an object in
     * the following format:
     *   uid: integer,
     *   size: integer,
     *   flags: [
     *       seen: bool,
     *       draft: bool,
     *       recent: bool,
     *       deleted: bool,
     *       flagged: bool,
     *       answered: bool
     *   ],
     *   message: Message object
     *   messageNum: Sequence number
     *   headers: [
     *       to: Recipient(s), string
     *       from: Who sent it, string
     *       id: Unique identifier, string
     *       date: When it was sent, string
     *       subject: Message's subject, string
     *       contentType: Content type of the message
     *       inReplyTo: ID of message this replying to
     *       references: List of messages this one references
     *   ]
     *
     * @param integer $id
     * @return stdClass
     */
    public function getMessageInfo( $id )
    {
        // Set up the new message
        $messageInfo = new stdClass();
        $messageInfo->messgeNum = $id;
        $messageInfo->flags = new stdClass();
        $messageInfo->headers = new stdClass();

        // Get the message info
        $message = $this->getImapStream()->getMessage( $id );
        // Store some internal properties
        $messageInfo->message = $message;
        $messageInfo->size = $this->getImapStream()->getSize( $id );
        $messageInfo->uid = $this->getImapStream()->getUniqueId( $id );

        // Use this to lookup the headers
        $headers = $message->getHeaders();
        $headerMap = [
            'To' => 'to',
            'From' => 'from',
            'Date' => 'date',
            'Message-ID' => 'id',
            'Subject' => 'subject',
            'References' => 'references',
            'In-Reply-To' => 'inReplyTo',
            'Content-Type' => 'contentType'
        ];

        // Add the headers. This could throw exceptions during the
        // header parsing that we want to catch.
        foreach ( $headerMap as $field => $key ) {
            $messageInfo->headers->$key = ( $headers->has( $field ) )
                ? $headers->get( $field )
                : NULL;
        }

        // Add in the flags
        $flags = $message->getFlags();
        $flagMap = [
            '\Seen' => 'seen',
            '\Draft' => 'draft',
            '\Recent' => 'recent',
            '\Deleted' => 'deleted',
            '\Flagged' => 'flagged',
            '\Answered' => 'answered'
        ];

        foreach ( $flagMap as $field => $key ) {
            $messageInfo->flags->$key = isset( $flags[ $field ] );
        }

        return $messageInfo;
    }

    /**
     * Get the message data.
     * @param $id
     * @return Mail
     */
    public function getMessage( $id )
    {
        $mail = new Mail();
        $messageInfo = $this->getMessageInfo( $id );

        // Store some common properties
        $mail->id = $id;
        $mail->messageId = ( isset( $head->id ) )
            ? $head->id->getFieldValue()
            : NULL;
        $head = $messageInfo->headers;
        $time = ( isset( $head->date ) )
            ? strtotime( preg_replace( '/\(.*?\)/', '', $head->date->getFieldValue() ) )
            : time();
        $mail->date = date( 'Y-m-d H:i:s', $time );
        $mail->subject = ( isset( $head->subject ) )
            ? $head->subject->getFieldValue()
            : NULL;
        // Try to get the from address and name
        $from = $this->getAddresses( $head, 'from' );
        $mail->fromName = ( $from )
            ? $from[ 0 ]->getName()
            : NULL;
        $mail->fromAddress = ( $from )
            ? $from[ 0 ]->getEmail()
            : NULL;
        // The next two fields are the remaining addresses
        $mail->to = ( isset( $head->to ) )
            ? $this->getAddresses( $head, 'to' )
            : [];
        $mail->toString = ( isset( $head->to ) )
            ? $head->to->getFieldValue()
            : '';
        $mail->cc = ( isset( $head->cc ) )
            ? $this->getAddresses( $head, 'cc' )
            : [];
        // This is a message ID that the message is replying to
        $mail->inReplyTo = ( isset( $head->inReplyTo ) )
            ? $head->inReplyTo->getFieldValue()
            : NULL;

        foreach ( $messageInfo->message as $a ) {
            $h = $a->getHeaders();
            //print_r($h);
            if ( $h->has( 'contentTransferEncoding' ) ) {
                $encoding = $h->get( 'contentTransferEncoding' )->getFieldValue();
                echo $h->get( 'contentTransferEncoding' )->getTransferEncoding(), "\n";
                if ( $encoding === 'base64' )
                {
                    $data = preg_replace( '~[^a-zA-Z0-9+=/]+~s', '', $a->getContent() );
                    $data = base64_decode( $data );
                }
                else if ( $encoding === 'quoted-printable' )
                {
                    //print_r($h);
                    $data = quoted_printable_decode( $a->getContent() );
                    //echo $data;
                }
            }
            else
            {
                echo "asdasd\n";
                echo $a->getContent(), "\n";
            }
            //if ( $h->has( 'xAttachmentId' ) ) {
            //    file_put_contents(
            //        '/home/mike/Desktop/'. $h->get( 'xAttachmentId' )->getFieldValue() .".jpg",
            //        base64_decode( $a->getContent() ) );
            //}
        }

        return $mail;

        // Add all of the mail parts. This will save attachments to
        // the mail object.

        /*
        $mailStructure = imap_fetchstructure(
            $this->getImapStream(),
            $mailId,
            FT_UID );

        if ( empty( $mailStructure->parts ) ) {
            $this->initMailPart( $mail, $mailStructure, 0, $markAsSeen );
        }
        else {
            foreach ( $mailStructure->parts as $partNum => $partStructure ) {
                $this->initMailPart(
                    $mail,
                    $partStructure,
                    $partNum + 1,
                    $markAsSeen );
            }
        }
        */

        return $mail;
    }

    /**
     * Takes in a headers object and returns an array of addresses
     * for a particular field. If $returnString is true, then the
     * comma-separated list of RFC822 name/emails is returned.
     * @param \Headers $headers
     * @param string $field
     * @return array|string
     */
    protected function getAddresses( $headers, $field, $returnString = FALSE )
    {
        $addresses = [];

        if ( isset( $headers->$field ) && count( $headers->$field ) ) {
             foreach ( $headers->$field->getAddressList() as $address ) {
                $addresses[] = $address;
             }
        }

        return $addresses;
    }

    protected function initMailPart(
        Mail $mail,
        $partStructure,
        $partNum,
        $markAsSeen = TRUE )
    {
        $params = [];
        $options = FT_UID;

        if ( ! $markAsSeen) {
            $options |= FT_PEEK;
        }

        $this->debug( "Before fetching IMAP body ($partNum)" );
        $data = ( $partNum )
            ? imap_fetchbody(
                $this->getImapStream(),
                $mail->id,
                $partNum,
                $options )
            : imap_body( $this->getImapStream(), $mail->id, $options );

        if ( $partStructure->encoding == 1 ) {
            $data = imap_utf8( $data );
        }
        elseif ( $partStructure->encoding == 2 ) {
            $data = imap_binary( $data );
        }
        elseif ( $partStructure->encoding == 3 ) {
            // https://github.com/barbushin/php-imap/issues/88
            $data = preg_replace( '~[^a-zA-Z0-9+=/]+~s', '', $data );
            $data = imap_base64( $data );
        }
        elseif ( $partStructure->encoding == 4 ) {
            $data = quoted_printable_decode( $data );
        }

        if ( ! empty( $partStructure->parameters ) ) {
            foreach ( $partStructure->parameters as $param ) {
                $params[ strtolower( $param->attribute ) ] = $param->value;
            }
        }

        if ( ! empty( $partStructure->dparameters ) ) {
            foreach ( $partStructure->dparameters as $param ) {
                $matched = preg_match(
                    '~^(.*?)\*~',
                    $param->attribute,
                    $matches );
                $paramName = strtolower(
                     ( $matched ) ? $matches[ 1 ] : $param->attribute );

                if ( isset( $params[ $paramName ] ) ) {
                    $params[ $paramName ] .= $param->value;
                }
                else {
                    $params[ $paramName ] = $param->value;
                }
            }
        }

        // Process attachments. This will look for an ID saved to the mail part
        // and if one doesn't exist, it'll be created. The created IDs should
        // be reproducible.
        $this->debug( "After processing data" );
        $attachmentId = ( $partStructure->ifid )
            ? trim( $partStructure->id, " <>" )
            : $this->generateAttachmentId( $params, $mail, $partNum );
        $this->debug( "Generated attachment ID" );

        if ( $attachmentId ) {
            if ( empty( $params[ 'filename' ] ) && empty( $params[ 'name' ] ) ) {
                $fileName = $attachmentId .'.'. strtolower( $partStructure->subtype );
            }
            else {
                $fileName = ( ! empty( $params[ 'filename' ] ) )
                    ? $params[ 'filename' ]
                    : $params[ 'name' ];
                $fileName = $this->decodeMimeStr( $fileName, $this->serverEncoding );
                $fileName = $this->decodeRFC2231( $fileName, $this->serverEncoding );
            }

            $this->debug( "Set up filename for attachment" );
            $attachment = new Attachment();
            $attachment->name = $fileName;
            $attachment->id = $attachmentId;
            $attachment->origName = ( ! empty( $params[ 'name' ] ) )
                ? $params[ 'name' ]
                : NULL;
            $attachment->origFileName = ( ! empty( $params[ 'filename' ] ) )
                ? $params[ 'filename' ]
                : NULL;
            $this->debug( "New attachment created" );

            // Attachments are saved in YYYY/MM directories
            if ( $this->attachmentsDir) {
                $replace = [
                    '/\s/' => '_',
                    '/[^0-9a-zа-яіїє_\.]/iu' => '',
                    '/_+/' => '_',
                    '/(^_)|(_$)/' => ''
                ];
                $fileSysName = preg_replace(
                    '~[\\\\/]~',
                    '',
                    $mail->id .'_'. $attachmentId .'_'. preg_replace(
                        array_keys( $replace ),
                        $replace,
                        $fileName
                    ));
                // Truncate the sys name if it's too long. This will throw an
                // error in file_put_contents.
                $fileSysName = substr( $fileSysName, 0, 250 );
                // Create the YYYY/MM directory to put the attachment into
                $fullDatePath = sprintf(
                    "%s%s%s%s%s",
                    $this->attachmentsDir,
                    DIRECTORY_SEPARATOR,
                    date( 'Y', strtotime( $mail->date ) ),
                    DIRECTORY_SEPARATOR,
                    date( 'm', strtotime( $mail->date ) ));
                @mkdir( $fullDatePath, 0755, TRUE );
                $attachment->filePath = $fullDatePath
                    . DIRECTORY_SEPARATOR
                    . $fileSysName;
                $this->debug( "Before writing attachment to disk" );
                file_put_contents( $attachment->filePath, $data );
                $this->debug( "After file_put_contents finished" );
            }

            $mail->addAttachment( $attachment );
        }
        else {
            if ( ! empty( $params[ 'charset' ] ) ) {
                $data = $this->convertStringEncoding(
                    $data,
                    $params[ 'charset' ],
                    $this->serverEncoding );
            }

            if ( $partStructure->type === 0 && $data ) {
                if ( strtolower( $partStructure->subtype ) == 'plain' ) {
                    $mail->textPlain .= $data;
                }
                else {
                    $mail->textHtml .= $data;
                }
            }
            elseif ( $partStructure->type == 2 && $data ) {
                $mail->textPlain .= trim( $data );
            }
        }

        unset( $data );
        $this->debug( "Unset data (imap_body) variable" );

        if ( ! empty( $partStructure->parts ) ) {
            foreach ( $partStructure->parts as $subPartNum => $subPartStructure ) {
                $this->debug( "Recursively calling initMailPart" );

                if ( $partStructure->type == 2
                    && $partStructure->subtype == 'RFC822' )
                {
                    $this->initMailPart(
                        $mail,
                        $subPartStructure,
                        $partNum,
                        $markAsSeen );
                }
                else {
                    $this->initMailPart(
                        $mail,
                        $subPartStructure,
                        $partNum .'.'. ( $subPartNum + 1 ),
                        $markAsSeen );
                }
            }
        }
    }

    protected function decodeMimeStr( $string, $charset = 'utf-8' )
    {
        $newString = '';
        $elements = imap_mime_header_decode( $string );

        for ( $i = 0; $i < count( $elements ); $i++ ) {
            if ( $elements[ $i ]->charset == 'default' ) {
                $elements[ $i ]->charset = 'iso-8859-1';
            }

            $newString .= $this->convertStringEncoding(
                $elements[ $i ]->text,
                $elements[ $i ]->charset,
                $charset );
        }

        return $newString;
    }

    function isUrlEncoded( $string )
    {
        $hasInvalidChars = preg_match( '#[^%a-zA-Z0-9\-_\.\+]#', $string );
        $hasEscapedChars = preg_match( '#%[a-zA-Z0-9]{2}#', $string );

        return ! $hasInvalidChars && $hasEscapedChars;
    }

    /**
     * Create an ID for a mail attachment. This takes the attributes name,
     * date, filename, etc and hashes the result.
     * @param array $params
     * @return string
     */
    protected function generateAttachmentId( $params, $mail, $partNum )
    {
        // If both are missing, then this isn't an attachment
        if ( ! isset( $params[ 'filename' ] )
            && ! isset( $params[ 'name' ] ) )
        {
            return NULL;
        }

        // Unique ID is a concatenation of the unique ID and a
        // hash of the combined date, from address, subject line,
        // part number, and message ID.
        return md5(
            sprintf(
                "%s-%s-%s-%s-%s",
                $mail->date,
                $mail->fromAddress,
                $mail->subject,
                $partNum,
                $mail->messageId
            ));
    }

    protected function decodeRFC2231( $string, $charset = 'utf-8' )
    {
        if ( preg_match( "/^(.*?)'.*?'(.*?)$/", $string, $matches ) ) {
            $data = $matches[ 2 ];
            $encoding = $matches[ 1 ];

            if ( $this->isUrlEncoded( $data ) ) {
                $string = $this->convertStringEncoding(
                    urldecode( $data ),
                    $encoding,
                    $charset );
            }
        }

        return $string;
    }

    /**
     * Converts a string from one encoding to another.
     * @param string $string
     * @param string $fromEncoding
     * @param string $toEncoding
     * @return string Converted string if conversion was successful, or the
     *   original string if not
     */
    protected function convertStringEncoding( $string, $fromEncoding, $toEncoding )
    {
        $convertedString = NULL;

        if ( $string && $fromEncoding != $toEncoding ) {
            $convertedString = @iconv(
                $fromEncoding,
                $toEncoding . '//IGNORE',
                $string );

            if ( ! $convertedString && extension_loaded( 'mbstring' ) ) {
                $convertedString = @mb_convert_encoding(
                    $string,
                    $toEncoding,
                    $fromEncoding );
            }
        }

        return ( $convertedString ) ?: $string;
    }

    public function debug( $message )
    {
        if ( ! $this->debugMode ) {
            return;
        }

        $date = new \DateTime();

        echo sprintf(
            "[%s] %s MB peak, %s MB cur -- %s%s",
            $date->format( 'Y-m-d H:i:s' ),
            number_format(
                memory_get_peak_usage( TRUE ) / 1024 / 1024,
                2 ),
            number_format(
                memory_get_usage( TRUE ) / 1024 / 1024,
                2 ),
            $message,
            PHP_EOL );
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}