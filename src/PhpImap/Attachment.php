<?php

namespace PhpImap;

use PhpImap\Message
  , Zend\Mail\Storage\Part;

class Attachment
{
	public $id;
	public $name;
    public $filename;
	public $filePath;
    public $origName;
    public $origFilename;

    public function generateId( Message $message, Part $part )
    {
        $this->id = ( $part->has( 'x-attachment-id' ) )
            ? trim( $part->getHeaderField( 'x-attachment-id' ), " <>" )
            : self::generateAttachmentId( $message, $part->partNum );
    }

    /**
     * Create an ID for a message attachment. This takes the attributes
     * name, date, filename, etc and hashes the result.
     * @param Message $message
     * @param integer $partNum
     * @return string
     */
    protected static function generateAttachmentId( Message $message, int $partNum )
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
}