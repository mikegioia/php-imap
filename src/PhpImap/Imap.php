<?php

namespace PhpImap;

use Zend\Mail\Storage\Imap as ZendImap;

class Imap extends ZendImap
{
    /**
     * Examine given folder. Folder must be selectable!
     *
     * @param  \Zend\Mail\Storage\Folder|string $globalName
     *   global name of folder or instance for subfolder
     * @throws Exception\RuntimeException
     * @throws \Zend\Mail\Protocol\Exception\RuntimeException
     * @return NULL|array
     */
    public function examineFolder( $globalName )
    {
        $this->currentFolder = $globalName;
        $examine = $this->protocol->examine( $this->currentFolder );

        if ( ! $examine ) {
            $this->currentFolder = '';
            throw new Exception\RuntimeException(
                'Cannot examine folder, maybe it does not exist' );
        }

        return $examine;
    }

    public function search( array $params )
    {
        return $this->protocol->search( $params );
    }
}