<?php

namespace PhpImap;

use stdClass
  , Exception
  , PhpImap\Mail
  , PhpImap\Attachment;

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
    protected $imapPath;
    protected $imapLogin;
    protected $imapPassword;
    protected $serverEncoding;
    protected $attachmentsDir;
    protected $imapParams = [];
    protected $imapOptions = 0;
    protected $imapRetriesNum = 0;

    private $debugMode = FALSE;

    /**
     * Sets up a new mailbox object with the IMAP credentials to connect.
     * @param string $imapPath
     * @param string $login
     * @param string $password
     * @param string $attachmentsDir
     * @param string $serverEncoding
     */
    function __construct(
        $imapPath,
        $login,
        $password,
        $attachmentsDir = NULL,
        $serverEncoding = 'UTF-8',
        $debugMode = FALSE )
    {
        $this->imapLogin = $login;
        $this->imapPath = $imapPath;
        $this->debugMode = $debugMode;
        $this->imapPassword = $password;
        $this->serverEncoding = strtoupper( $serverEncoding );

        if ( $attachmentsDir ) {
            if ( ! is_dir( $attachmentsDir ) ) {
                throw new Exception( "Directory '$attachmentsDir' not found" );
            }

            $this->attachmentsDir = rtrim( realpath( $attachmentsDir ), '\\/' );
        }
    }

    /**
     * Set custom connection arguments of imap_open method. For more info, see
     * http://php.net/imap_open
     * @param int $options
     * @param int $retriesNum
     * @param array $params
     */
    public function setConnectionArgs(
        $options = 0,
        $retriesNum = 0,
        array $params = NULL )
    {
        $this->imapParams = $params;
        $this->imapOptions = $options;
        $this->imapRetriesNum = $retriesNum;
    }

    /**
     * Get IMAP mailbox connection stream
     * @param bool $forceConnection Initialize connection if it's not already
     * @return NULL|resource
     */
    public function getImapStream( $forceConnection = TRUE )
    {
        static $imapStream;

        if ( $forceConnection ) {
            if ( $imapStream
                && ( ! is_resource( $imapStream )
                    || ! imap_ping( $imapStream ) ) )
            {
                $this->disconnect();
                $imapStream = NULL;
            }

            if ( ! $imapStream ) {
                $imapStream = $this->initImapStream();
            }
        }

        return $imapStream;
    }

    protected function initImapStream()
    {
        $imapStream = @imap_open(
            $this->imapPath,
            $this->imapLogin,
            $this->imapPassword,
            $this->imapOptions,
            $this->imapRetriesNum,
            $this->imapParams );

        if ( ! $imapStream ) {
            throw new Exception( 'Connection error: ' . imap_last_error() );
        }

        return $imapStream;
    }

    protected function disconnect()
    {
        $imapStream = $this->getImapStream( FALSE );

        if ( $imapStream && is_resource( $imapStream ) ) {
            imap_close( $imapStream, CL_EXPUNGE );
        }
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns the information in an object with following properties:
     *   Date - current system time formatted according to RFC2822
     *   Driver - protocol used to access this mailbox: POP3, IMAP, NNTP
     *   Mailbox - the mailbox name
     *   Nmsgs - number of mails in the mailbox
     *   Recent - number of recent mails in the mailbox
     *
     * @return stdClass
     */
    public function checkMailbox()
    {
        return imap_check( $this->getImapStream() );
    }

    /**
     * Creates a new mailbox specified by mailbox.
     *
     * @return bool
     */
    public function createMailbox()
    {
        return imap_createmailbox(
            $this->getImapStream(),
            imap_utf7_encode( $this->imapPath ) );
    }

    /**
     * Gets status information about the given mailbox.
     *
     * This function returns an object containing status information.
     * The object has the following properties:
     *   messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return stdClass if the box doesn't exist
     */

    public function statusMailbox()
    {
        return imap_status(
            $this->getImapStream(),
            $this->imapPath,
            SA_ALL );
    }

    /**
     * Gets listing the folders
     *
     * This function returns an object containing listing the folders.
     * The object has the following properties:
     *   messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return array listing the folders
     */
    public function getListingFolders()
    {
        $folders = imap_list(
            $this->getImapStream(),
            $this->imapPath,
            "*" );

        foreach ( $folders as $key => $folder ) {
            $folder = str_replace(
                $this->imapPath,
                "",
                imap_utf7_decode( $folder ) );
            $folders[ $key ] = $folder;
        }

        return $folders;
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
    public function searchMailbox( $criteria = 'ALL' )
    {
        $mailIds = imap_search(
            $this->getImapStream(),
            $criteria,
            SE_UID,
            $this->serverEncoding );

        return ( $mailIds ) ?: [];
    }

    /**
     * Save mail body.
     * @return bool
     */
    public function saveMail( $mailId, $filename = 'email.eml' )
    {
        return imap_savebody(
            $this->getImapStream(),
            $filename,
            $mailId,
            "",
            FT_UID);
    }

    /**
     * Marks mails listed in mailId for deletion.
     * @return bool
     */
    public function deleteMail( $mailId )
    {
        return imap_delete( $this->getImapStream(), $mailId, FT_UID );
    }

    public function moveMail( $mailId, $mailBox )
    {
        return imap_mail_move(
                $this->getImapStream(),
                $mailId,
                $mailBox,
                CP_UID )
            && $this->expungeDeletedMails();
    }

    /**
     * Deletes all the mails marked for deletion by imap_delete(),
     * imap_mail_move(), or imap_setflag_full().
     * @return bool
     */
    public function expungeDeletedMails()
    {
        return imap_expunge( $this->getImapStream() );
    }

    /**
     * Add the flag \Seen to a mail.
     * @return bool
     */
    public function markMailAsRead( $mailId )
    {
        return $this->setFlag( [ $mailId ], '\\Seen' );
    }

    /**
     * Remove the flag \Seen from a mail.
     * @return bool
     */
    public function markMailAsUnread( $mailId )
    {
        return $this->clearFlag( [ $mailId ], '\\Seen');
    }

    /**
     * Add the flag \Flagged to a mail.
     * @return bool
     */
    public function markMailAsImportant( $mailId )
    {
        return $this->setFlag( [ $mailId ], '\\Flagged');
    }

    /**
     * Add the flag \Seen to a mails.
     * @return bool
     */
    public function markMailsAsRead( array $mailIds )
    {
        return $this->setFlag( $mailIds, '\\Seen');
    }

    /**
     * Remove the flag \Seen from some mails.
     * @return bool
     */
    public function markMailsAsUnread( array $mailIds )
    {
        return $this->clearFlag( $mailId, '\\Seen' );
    }

    /**
     * Add the flag \Flagged to some mails.
     * @return bool
     */
    public function markMailsAsImportant( array $mailIds )
    {
        return $this->setFlag( $mailId, '\\Flagged' );
    }

    /**
     * Causes a store to add the specified flag to the flags set for the mails
     * in the specified sequence.
     *
     * @param array $mailIds
     * @param string $flag which you can set are:
     *   \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060.
     * @return bool
     */
    public function setFlag( array $mailIds, $flag )
    {
        return imap_setflag_full(
            $this->getImapStream(),
            implode( ',', $mailIds ),
            $flag,
            ST_UID );
    }

    /**
     * Cause a store to delete the specified flag to the flags set for the
     * mails in the specified sequence.
     *
     * @param array $mailIds
     * @param string $flag which you can set are \Seen, \Answered, \Flagged,
     *   \Deleted, and \Draft as defined by RFC2060.
     * @return bool
     */
    public function clearFlag( array $mailIds, $flag )
    {
        return imap_clearflag_full(
            $this->getImapStream(),
            implode( ',', $mailIds ),
            $flag,
            ST_UID );
    }

    /**
     * Fetch mail headers for listed mail IDs
     *
     * Returns an array of objects describing one mail header each. The object
     * will only define a property if it exists. The possible properties are:
     *   subject - the mails subject
     *   from - who sent it
     *   to - recipient
     *   date - when was it sent
     *   message_id - Mail-ID
     *   references - is a reference to this mail ID
     *   in_reply_to - is a reply to this mail ID
     *   size - size in bytes
     *   uid - UID the mail has in the mailbox
     *   msgno - mail sequence number in the mailbox
     *   recent - this mail is flagged as recent
     *   flagged - this mail is flagged
     *   answered - this mail is flagged as answered
     *   deleted - this mail is flagged for deletion
     *   seen - this mail is flagged as already read
     *   draft - this mail is flagged as being a draft
     *
     * @param array $mailIds
     * @return array
     */
    public function getMailsInfo( array $mailIds )
    {
        $mails = imap_fetch_overview(
            $this->getImapStream(),
            implode( ',', $mailIds ),
            FT_UID );

        if ( is_array( $mails ) && count( $mails ) ) {
            foreach ( $mails as &$mail ) {
                if ( isset( $mail->to ) ) {
                    $mail->to = $this->decodeMimeStr(
                        $mail->to,
                        $this->serverEncoding );
                }

                if ( isset( $mail->from ) ) {
                    $mail->from = $this->decodeMimeStr(
                        $mail->from,
                        $this->serverEncoding );
                }

                if ( isset( $mail->subject ) ) {
                    $mail->subject = $this->decodeMimeStr(
                        $mail->subject,
                        $this->serverEncoding );
                }
            }
        }

        return $mails;
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns an object with following properties:
     *   Date - last change (current datetime)
     *   Driver - driver
     *   Mailbox - name of the mailbox
     *   Nmsgs - number of messages
     *   Recent - number of recent messages
     *   Unread - number of unread messages
     *   Deleted - number of deleted messages
     *   Size - mailbox size
     *
     * @return object Object with info | FALSE on failure
     */
    public function getMailboxInfo()
    {
        return imap_mailboxmsginfo( $this->getImapStream() );
    }

    /**
     * Gets mail IDs sorted by some criteria
     *
     * Criteria can be one (and only one) of the following constants:
     *   SORTDATE - mail Date
     *   SORTARRIVAL - arrival date (default)
     *   SORTFROM - mailbox in first From address
     *   SORTSUBJECT - mail subject
     *   SORTTO - mailbox in first To address
     *   SORTCC - mailbox in first cc address
     *   SORTSIZE - size of mail in octets
     *
     * @param int $criteria
     * @param bool $reverse
     * @return array Mail IDs
     */
    public function sortMails( $criteria = SORTARRIVAL, $reverse = TRUE )
    {
        return imap_sort( $this->getImapStream(), $criteria, $reverse, SE_UID );
    }

    /**
     * Get mails count in mail box
     * @return int
     */
    public function countMails()
    {
        return imap_num_msg( $this->getImapStream() );
    }

    /**
     * Retrieve the quota settings per user
     * @return array - FALSE in the case of call failure
     */
    protected function getQuota()
    {
        return imap_get_quotaroot( $this->getImapStream(), 'INBOX' );
    }

    /**
     * Return quota limit in KB
     * @return int - FALSE in the case of call failure
     */
    public function getQuotaLimit()
    {
        $quota = $this->getQuota();

        if ( is_array( $quota ) ) {
            $quota = $quota[ 'STORAGE' ][ 'limit' ];
        }

        return $quota;
    }

    /**
     * Return quota usage in KB
     * @return int - FALSE in the case of call failure
     */
    public function getQuotaUsage()
    {
        $quota = $this->getQuota();

        if ( is_array( $quota ) ) {
            $quota = $quota[ 'STORAGE' ][ 'usage' ];
        }

        return $quota;
    }

    /**
     * Get mail data.
     *
     * @param $mailId
     * @param bool $markAsSeen
     * @return Mail
     */
    public function getMail( $mailId, $markAsSeen = TRUE )
    {
        $mail = new Mail();
        $head = imap_rfc822_parse_headers(
            imap_fetchheader(
                $this->getImapStream(),
                $mailId,
                FT_UID
            ));
        $mail->id = $mailId;
        $date = ( isset( $head->date ) )
            ? strtotime( preg_replace( '/\(.*?\)/', '', $head->date ) )
            : time();
        $mail->date = date('Y-m-d H:i:s', $date );
        $mail->subject = ( isset( $head->subject ) )
            ? $this->decodeMimeStr( $head->subject, $this->serverEncoding )
            : NULL;
        $mail->fromName = ( isset( $head->from[ 0 ]->personal ) )
            ? $this->decodeMimeStr(
                $head->from[ 0 ]->personal,
                $this->serverEncoding )
            : NULL;
        $mail->fromAddress = strtolower(
            $head->from[ 0 ]->mailbox .'@'. $head->from[ 0 ]->host );

        if ( isset( $head->to ) ) {
            $toStrings = [];

            foreach ( $head->to as $to ) {
                if ( ! empty( $to->mailbox ) && ! empty( $to->host ) ) {
                    $toEmail = strtolower( $to->mailbox .'@'. $to->host );
                    $toName = ( isset( $to->personal ) )
                        ? $this->decodeMimeStr(
                            $to->personal,
                            $this->serverEncoding )
                        : NULL;
                    $toStrings[] = ( $toName )
                        ? "$toName <$toEmail>"
                        : $toEmail;
                    $mail->to[ $toEmail ] = $toName;
                }
            }

            $mail->toString = implode( ', ', $toStrings );
        }

        if ( isset( $head->cc ) ) {
            foreach ( $head->cc as $cc ) {
                $address = strtolower( $cc->mailbox .'@'. $cc->host );
                $mail->cc[ $address ] = ( isset( $cc->personal ) )
                    ? $this->decodeMimeStr(
                        $cc->personal,
                        $this->serverEncoding )
                    : NULL;
            }
        }

        if ( isset( $head->reply_to ) ) {
            foreach ( $head->reply_to as $replyTo ) {
                $address = strtolower( $replyTo->mailbox .'@'. $replyTo->host );
                $mail->replyTo[ $address ] = ( isset( $replyTo->personal ) )
                    ? $this->decodeMimeStr(
                        $replyTo->personal,
                        $this->serverEncoding )
                    : NULL;
            }
        }

        if ( isset( $head->message_id ) ) {
            $mail->messageId = $head->message_id;
        }

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

        return $mail;
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