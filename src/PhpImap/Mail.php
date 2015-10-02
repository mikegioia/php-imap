<?php

namespace PhpImap;

use PhpImap\Attachment;

class Mail
{
	public $id;
	public $date;
	public $subject;
	public $to = [];
	public $cc = [];
    public $toString;
    public $fromName;
    public $textHtml;
    public $messageId;
    public $textPlain;
    public $fromAddress;
	public $replyTo = [];

	/**
     * @var Attachment []
     */
	protected $attachments = [];

	public function addAttachment( Attachment $attachment )
    {
		$this->attachments[ $attachment->id ] = $attachment;
	}

	/**
	 * @return Attachment[]
	 */
	public function getAttachments()
    {
		return $this->attachments;
	}

	/**
	 * Get array of internal HTML links placeholders
	 * @return array attachmentId => link placeholder
	 */
	public function getInternalLinksPlaceholders()
    {
		$matchAll = preg_match_all(
            '/=["\'](ci?d:([\w\.%*@-]+))["\']/i',
            $this->textHtml,
            $matches );

        if ( $matchAll ) {
            return array_combine( $matches[ 2 ], $matches[ 1 ] );
        }

        return [];
	}

	public function replaceInternalLinks( $baseUri )
    {
        $fetchedHtml = $this->textHtml;
		$baseUri = rtrim( $baseUri, '\\/' ) . '/';
        $placeholders = $this->getInternalLinksPlaceholders();

		foreach ( $placeholders as $attachmentId => $placeholder ) {
			if ( isset( $this->attachments[ $attachmentId ] ) ) {
                $basename = basename( $this->attachments[ $attachmentId ]->filePath );
				$fetchedHtml = str_replace(
                    $placeholder,
                    $baseUri . $basename,
                    $fetchedHtml );
			}
		}

		return $fetchedHtml;
	}
}