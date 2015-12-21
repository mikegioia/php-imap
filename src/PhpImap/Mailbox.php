<?php

namespace PhpImap;

use stdClass
  , Exception
  , Zend\Mime
  , PhpImap\Imap
  , PhpImap\Message
  , PhpImap\Attachment
  , Zend\Mail\Storage\Part
  , RecursiveIteratorIterator as Iterator
  , Zend\Mail\Exception\InvalidArgumentException;

/**
 * Author of this library:
 * @see https://github.com/mikegioia/php-imap
 * @author Mike Gioia http://mikegioia.com
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
     * given IMAP stream. For example, to match all unanswered messages sent by
     * Mom, you'd use: "UNANSWERED FROM mom". Searches appear to be case
     * insensitive. This list of criteria is from a reading of the UW c-client
     * source code and may be incomplete or inaccurate (see also RFC2060,
     * section 6.4.4).
     *
     * @param string $criteria String, delimited by spaces, in which the
     * following keywords are allowed. Any multi-word arguments (e.g. FROM
     * "joey smith") must be quoted. Results will match all criteria entries.
     *
     *   ALL - return all messages matching the rest of the criteria
     *   ANSWERED - match messages with the \\ANSWERED flag set
     *   BCC "string" - match messages with "string" in the Bcc: field
     *   BEFORE "date" - match messages with Date: before "date"
     *   BODY "string" - match messages with "string" in the body of the message
     *   CC "string" - match messages with "string" in the Cc: field
     *   DELETED - match deleted messages
     *   FLAGGED - match messages with the \\FLAGGED (sometimes referred to as
     *     Important or Urgent) flag set
     *   FROM "string" - match messages with "string" in the From: field
     *   KEYWORD "string" - match messages with "string" as a keyword
     *   NEW - match new messages
     *   OLD - match old messages
     *   ON "date" - match messages with Date: matching "date"
     *   RECENT - match messages with the \\RECENT flag set
     *   SEEN - match messages that have been read (the \\SEEN flag is set)
     *   SINCE "date" - match messages with Date: after "date"
     *   SUBJECT "string" - match messages with "string" in the Subject:
     *   TEXT "string" - match messages with text "string"
     *   TO "string" - match messages with "string" in the To:
     *   UNANSWERED - match messages that have not been answered
     *   UNDELETED - match messages that are not deleted
     *   UNFLAGGED - match messages that are not flagged
     *   UNKEYWORD "string" - match messages that do not have the keyword "string"
     *   UNSEEN - match messages which have not been read yet
     *
     * @return array Message IDs
     */
    public function search( $criteria = 'ALL' )
    {
        $messageIds = $this->getImapStream()->search([ $criteria ]);

        return $messageIds ?: [];
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
     * Fetch headers for listed message IDs. Returns an object in
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
     * Get the message data stored in a wrapper object.
     * @param $id
     * @return Message
     */
    public function getMessage( $id )
    {
        $message = new Message();
        $messageInfo = $this->getMessageInfo( $id );

        // Store some common properties
        $message->id = $id;
        $message->messageId = ( isset( $head->id ) )
            ? $head->id->getFieldValue()
            : NULL;
        $head = $messageInfo->headers;
        $time = ( isset( $head->date ) )
            ? strtotime( preg_replace( '/\(.*?\)/', '', $head->date->getFieldValue() ) )
            : time();
        $message->date = date( 'Y-m-d H:i:s', $time );
        $message->subject = ( isset( $head->subject ) )
            ? $head->subject->getFieldValue()
            : NULL;
        // Try to get the from address and name
        $from = $this->getAddresses( $head, 'from' );
        $message->fromName = ( $from )
            ? $from[ 0 ]->getName()
            : NULL;
        $message->fromAddress = ( $from )
            ? $from[ 0 ]->getEmail()
            : NULL;
        // The next two fields are the remaining addresses
        $message->to = ( isset( $head->to ) )
            ? $this->getAddresses( $head, 'to' )
            : [];
        $message->toString = ( isset( $head->to ) )
            ? $head->to->getFieldValue()
            : '';
        $message->cc = ( isset( $head->cc ) )
            ? $this->getAddresses( $head, 'cc' )
            : [];
        // This is a message ID that the message is replying to
        $message->inReplyTo = ( isset( $head->inReplyTo ) )
            ? $head->inReplyTo->getFieldValue()
            : NULL;
        // Set an internal reference to the IMAP protocol message
        $message->setImapMessage( $messageInfo->message );

        // If this is NOT a multipart message, store the plain text
        if ( ! $messageInfo->message->isMultipart() ) {
            $message->textPlain = $this->convertContent(
                $messageInfo->message->getContent(),
                $messageInfo->message->getHeaders() );
            return $message;
        }

        // Add all of the message parts. This will save attachments to
        // the message object. We can iterate over the Message object
        // and get each part that way.
        foreach ( $messageInfo->message as $part ) {
            $partHead = $part->getHeaders();
            $contentType = ( $partHead->has( 'content-type' ) )
                ? $partHead->get( 'content-type' )->getType()
                : NULL;

            // Check to see if this message is a container for sub-parts.
            // If it is we want to process those subparts.
            if ( strtolower( $contentType ) === 'message/rfc822' ) {
                $part = new Part([
                    'raw' => $part->getContent()
                ]);
            }

            $this->processPart( $message, $part );
        }

        return $message;
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

    protected function processPart( &$message, $part, $headers = NULL, $content = NULL )
    {
        $headers = $headers ?: $part->getHeaders();

        // If it's a file attachment we want to process all of
        // the attachments and save them to $message->attachments.
        if ( $headers->has( 'x-attachment-id' )
            || $headers->has( 'content-disposition' ) )
        {
            $this->processAttachment( $message, $part );
            // Get filename?
            // Get content-type? Where is name for file?
            // $attachment = $this->processAttachment()
            // if ( $attachment ) $message->attachments[] = $attachment;
            //if ( $h->has( 'xAttachmentId' ) ) {
            //    file_put_contents(
            //        '/home/mike/Desktop/'. $h->get( 'xAttachmentId' )->getFieldValue() .".jpg",
            //        base64_decode( $a->getContent() ) );
            //}
        }
        // Check if the part is text/plain or text/html and save
        // those as properties on $message.
        else {
            $this->processContent( $message, $part );
        }
    }

    protected function processContent( &$message, $part )
    {
        $textTypes = [
            Mime\Mime::TYPE_TEXT,
            Mime\Mime::TYPE_HTML
        ];
        $multipartTypes = [
            Mime\Mime::MULTIPART_MIXED,
            Mime\Mime::MULTIPART_ALTERNATIVE
        ];
        $contentType = $part->getHeaderField( 'content-type' );

        if ( in_array( $contentType, $multipartTypes ) ) {
            $boundary = $part->getHeaderField( 'content-type', 'boundary' );

            if ( $boundary ) {
                $subParts = Mime\Decode::splitMessageStruct(
                    $part->getContent(),
                    $boundary );

                foreach ( $subParts as $subPart ) {
                    $typeHeader = $subPart[ 'header' ]->get( 'content-type' );
                    $subContentType = $typeHeader->getType();

                    // If it's an attachment, run it through the attachment
                    // processor, otherwise treat it like text.
                    if ( in_array( $subContentType, $textTypes ) ) {
                        $this->processTextContent(
                            $message,
                            $typeHeader->getType(),
                            $this->convertContent(
                                $subPart[ 'body' ],
                                $subPart[ 'header' ] ),
                            $typeHeader->getParameter( 'charset' ));
                    }
                    else {
                        $this->processAttachment(
                            $message,
                            new Part([
                                'content' => $subPart[ 'body' ],
                                'headers' => $subPart[ 'header' ]
                            ]));
                    }
                }
            }
        }
        elseif ( in_array( $contentType, $textTypes ) ) {
            $this->processTextContent(
                $message,
                $contentType,
                $this->convertContent( $part->getContent(), $part->getHeaders() ),
                $part->getHeaderField( 'content-type', 'charset' ));
        }
        else {
            echo "NEW CONTENT TYPE FOUND\n";
            print_r($part);
            exit;
        }
    }

    protected function processTextContent( &$message, $contentType, $content, $charset )
    {
        if ( $contentType === Mime\Mime::TYPE_TEXT ) {
            $message->textPlain .= $this->convertEncoding( $content, $charset, 'UTF-8' );
        }
        else if ( $contentType === Mime\Mime::TYPE_HTML ) {
            $message->textHtml .= $this->convertEncoding( $content, $charset, 'UTF-8' );
        }
    }

    protected function processAttachment( &$message, $part )
    {
        $name = NULL;
        $filename = NULL;
        $attachment = new Attachment();
        $headers = $part->getHeaders();

        // Get the filename and/or name for the attachment. Try the
        // disposition first.
        if ( $headers->has( 'content-disposition' ) ) {
            $name = $part->getHeaderField( 'content-disposition', 'name' );
            $filename = $part->getHeaderField( 'content-disposition', 'filename' );
        }

        if ( $headers->has( 'content-type' ) ) {
            $name = $name ?: $part->getHeaderField( 'content-type', 'name' );
            $filename = $filename ?: $part->getHeaderField( 'content-type', 'filename' );
        }

        if ( ! $filename ) {
            $filename = $name;
        }

        if ( ! $filename && ! $name ) {
            print_r($part);
            exit;
        }

        if ( ! $filename ) {
            $filename = 'unknown';
        }

        return;

        // Content-Disposition has filename, Content-Type has name
        // Look to Content-Type for something in the event that the
        // Disposition is not present

        // If we are fortunate enough to get an attachment ID, then
        // use that. Otherwise we want to create on in a deterministic
        // way.
        $attachmentId = ( $headers->has( 'x-attachment-id' ) )
            ? trim( $headers->get( 'x-attachment-id' )->getFieldValue(), " <>" )
            : $this->generateAttachmentId( $params, $message, $part );
    }

    /**
     * Create an ID for a message attachment. This takes the attributes
     * name, date, filename, etc and hashes the result.
     * @param array $params
     * @return string
     */
    protected function generateAttachmentId( $params, $message, $partNum )
    {
        // Unique ID is a concatenation of the unique ID and a
        // hash of the combined date, from address, subject line,
        // part number, and message ID.
        return md5(
            sprintf(
                "%s-%s-%s-%s-%s",
                $message->date,
                $message->fromAddress,
                $message->subject,
                $partNum,
                $message->messageId
            ));
    }

    protected function convertContent( $content, $headers )
    {
        $data = NULL;

        if ( $headers->has( 'contentTransferEncoding' ) ) {
            $encoding = $headers
                ->get( 'contentTransferEncoding' )
                ->getFieldValue();

            if ( $encoding === 'base64' ) {
                $data = preg_replace(
                    '~[^a-zA-Z0-9+=/]+~s',
                    '',
                    $content );
                $data = base64_decode( $data );
            }
            else if ( $encoding === 'quoted-printable' ) {
                $data = quoted_printable_decode( $content );
            }
        }

        if ( is_null( $data ) ) {
            $data = $content;
        }

        return $data;
    }

    /**
     * Converts a string from one encoding to another.
     * @param string $string
     * @param string $fromEncoding
     * @param string $toEncoding
     * @return string Converted string if conversion was successful, or the
     *   original string if not
     */
    protected function convertEncoding( $string, $fromEncoding, $toEncoding )
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

        return $convertedString ?: $string;
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

/*
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
*/
}