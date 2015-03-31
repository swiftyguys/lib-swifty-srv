<?php

abstract class SwiftyCrunch {
    private $cache_lifetime = 604800; // 7 * 24 * 60 * 60
    
    private $_headers = array();

    public function __construct() {
        $this->init();
    }

    protected function init() {
    }

    protected function addErrorHeader( $error ) {
        $this->_headers['X-Swifty-Error'] = $error;
    }

    protected function addHeader( $k, $s ) {
        $this->_headers[ $k ] = $s;
    }

    protected function sendFile($src, $mime, $cache = true) {
        // Custom headers
        foreach ($this->_headers as $header => $value) {
            header($header.': '.$value);
        }

        // Send basic headers
        header('Content-Type: '.$mime);
        header('X-SS-Test-MIME: '.$mime);
        header('X-SS-Test-Content-Length: '.filesize( @is_link($src) ? @readlink($src) : $src ));
        header('Content-Length: ' . filesize( @is_link($src) ? @readlink($src) : $src ) );

        // If the client browser should cache the image
        if ($cache) {
            header( 'Pragma: public' );
            header( 'Cache-Control: public' );
            header('Expires: '.gmdate('D, d M Y H:i:s', time() + $this->cache_lifetime).' GMT');
            header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($src)).' GMT');
            header('ETag: '.$this->_calculateFileETag($src));
        } else {
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: '.gmdate('D, d M Y H:i:s', time() - $this->cache_lifetime).' GMT');
        }

        readfile($src);
        exit;
    }

    private function _calculateFileETag($src) {
        $src = @is_link($src) ? @readlink($src) : $src;
        $fileStats = stat($src);
        $fileFulltime = @exec('ls --full-time '.escapeshellarg($src));
        $fileMtime = str_pad(preg_match("%\d+\:\d+\:\d+\.(\d+)%", $fileFulltime, $fileMtime) ? substr($fileStats['mtime'].$fileMtime[1], 0, 16) : $fileStats['mtime'], 16, '0');
        return sprintf('"%x-%x-%s"', $fileStats['ino'], $fileStats['size'], base_convert($fileMtime, 10, 16));
    }

    ////////////////////////////////////////

    protected function getExtension( $src, $extension = null ) {
        $extension = ($extension === null) ? pathinfo( $src, PATHINFO_EXTENSION ) : $extension;
        $extension = strtolower($extension);

        return $extension;
    }

    ////////////////////////////////////////

    protected function getExtensionCorrected( $src, $extension ) {
        $extension = $this->getExtension( $src, $extension );
        $extension = ((strtolower($extension) == 'jpg') ? 'jpeg' : strtolower($extension));

        return $extension;
    }

}