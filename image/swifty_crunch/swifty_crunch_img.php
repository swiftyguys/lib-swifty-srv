<?php

require_once( dirname( __FILE__ ) . '/swifty_crunch.php' );

$watch_cache   = TRUE; // check that the adapted image isn't stale (ensures updated source images are re-cached)

////////////////////////////////////////

class SwiftyCrunchImg extends SwiftyCrunch {
    private $debug = FALSE;
    private $jpg_quality = 85; // 0..100
    private $jpg_quality_mobi = 75; // 0..100
    private $sharpen = TRUE;

    private $requested_uri = '';
    private $source_file = '';
    private $cache_file = '';
    private $cache_dir = '';
    private $final_url = '';
    private $ori_width = -1;
    private $ori_height = -1;
    private $source_width = -1;
    private $source_height = -1;
    private $source_x = 0;
    private $source_y = 0;
    private $ori_type = '';
    private $tech_type = '';
    private $target_width = -1;
    private $target_height = -1;
    private $target_type = '';
    private $browser_device = '';
    private $wanted_width = -2;
    private $wanted_height = -2;
    private $forced_size = 0;
    private $errors = '';
    private $return_image = '';
    private $ori_ratio = 1;
    private $target_ratio = 1;
    private $focus_perc_x = 0;
    private $focus_perc_y = 0;
    private $focus_perc_w = 100;
    private $focus_perc_h = 100;
    private $focus_center_x = -1;
    private $focus_center_y = -1;

    private $ssc1 = null;
    private $ssc2 = null;

    protected static $_quantizers = array(
        self::QUANTIZER_INTERNAL => false,
        self::QUANTIZER_PNGQUANT => '`which pngquant` --force --transbug --ext ".png" --speed %s %s',
        self::QUANTIZER_PNGNQ => '`which pngnq` -f -e ".png" -s %s %s',
    );
    const QUANTIZER_INTERNAL = 'internal';
    const QUANTIZER_PNGQUANT = 'pngquant';
    const QUANTIZER_PNGNQ = 'pngnq';

    protected function init() {
        $this->set_properties();

        if (!file_exists( $this->source_file )) {
            header("Status: 404 Not Found");
            exit();
        }

        $this->return_image = $this->cache_file;

        $this-> get_param_sizes();

        if (!extension_loaded('gd') && (!function_exists('dl') || !dl('gd.so'))) {
            // If the GD extension is not available
            $this->addErrorHeader( 'GD not available' );
        } elseif( ( ! @is_dir( $this->cache_dir ) && ! @mkdir( $this->cache_dir , 0755, true ) ) || ( ! @is_writable( $this->cache_dir ) && ! chmod( $this->cache_dir , 0755 ) ) ) {
            // If the cache directory doesn't exist or is not writable: Error
            $this->addErrorHeader( 'cache dir error' );
        } else {
            $this->crunch();
            if( $this->errors ) {
                foreach( $this->errors as $errMsg ) {
                    $this->addErrorHeader( $errMsg );
                }
            }
        }

        $this->add_debug_headers();

        $this->sendFile( $this->return_image, 'image/'. $this->getExtensionCorrected( $this->return_image, $this->target_type ), true);
    }

    ////////////////////////////////////////

    protected function set_properties() {
        $document_root = $_SERVER['DOCUMENT_ROOT'];
        $this->requested_uri = parse_url(urldecode($_SERVER['REQUEST_URI']), PHP_URL_PATH);
//        $this->source_file = $document_root.$this->requested_uri;
$this->source_file = $document_root.$_SERVER['MY_CRUNCH_FILE'];
//        $this->cache_file = $document_root.$_SERVER['REDIRECT_URL'];
//$this->cache_file = dirname( __FILE__ ) . '/cache';
$this->cache_file = $document_root.$_SERVER['MY_CRUNCH_URL'];
        $this->cache_dir = dirname( $this->cache_file );
        $this->target_type = $this->getExtension( $this->cache_file );
//        $this->final_url = $_SERVER['REDIRECT_URL'];
$this->final_url = $_SERVER['MY_CRUNCH_URL'];
    }

    ////////////////////////////////////////

    protected function get_param_sizes() {
        $wantedWidth = -2;
        $wantedHeight = -2;

        $url = $this->final_url;

        $cmp = '_ssw_';
        if( ( $i = strpos( $url, $cmp ) ) !== false ) { // false compare is deliberate
            $w = intval( substr( $url, $i + strlen( $cmp ) ) );
            if( $w > 0 ) {
                $wantedWidth = $w;
                // Check for ;h= (used in SS1 resize url's)
                $cmp2 = ';h=';
                if( substr( $url, $i + strlen( $cmp ) + strlen( '' . $w ), 3 ) === $cmp2 ) {
                    $h = intval( substr( $url, $i + strlen( $cmp ) + strlen( '' . $w ) + strlen( $cmp2 ) ) );
                    if( $h > 0 ) {
                        $wantedHeight = $h;
                        $this->forced_size = 1;
                    }
                }
            }
        }

        if( $wantedHeight === -2 ) {
            $cmp = '_ssh_';
            if( ( $i = strpos( $url, $cmp ) ) !== false ) { // false compare is deliberate
                $h = intval( substr( $url, $i + strlen( $cmp ) ) );
                if( $h > 0 ) {
                    $wantedHeight = $h;
                }
            }
        }

        if( strpos( $this->cache_file, '_ssdv_mobi_' ) !== false ) { // false compare is deliberate
            $this->browser_device = 'mobi';
            $this->jpg_quality = $this->jpg_quality_mobi;
        }

        $this->wanted_width = $wantedWidth;
        $this->wanted_height = $wantedHeight;

        if( isset( $_GET[ 'ssrx' ] ) && ! empty( $_GET[ 'ssrx' ] ) ) {
            $this->focus_perc_x = $_GET[ 'ssrx' ];
        }
        if( isset( $_GET[ 'ssry' ] ) && ! empty( $_GET[ 'ssry' ] ) ) {
            $this->focus_perc_y = $_GET[ 'ssry' ];
        }
        if( isset( $_GET[ 'ssrw' ] ) && ! empty( $_GET[ 'ssrw' ] ) ) {
            $this->focus_perc_w = $_GET[ 'ssrw' ];
        }
        if( isset( $_GET[ 'ssrh' ] ) && ! empty( $_GET[ 'ssrh' ] ) ) {
            $this->focus_perc_h = $_GET[ 'ssrh' ];
        }

        $cmp = '_ssc1_';
        if( ( $i = strpos( $url, $cmp ) ) !== false ) { // false compare is deliberate
            $this->ssc1 = explode( '_', substr( $url, $i + strlen( $cmp ) ) )[ 0 ];
        }
        $cmp = '_ssc2_';
        if( ( $i = strpos( $url, $cmp ) ) !== false ) { // false compare is deliberate
            $this->ssc2 = explode( '_', substr( $url, $i + strlen( $cmp ) ) )[ 0 ];
        }
    }

    ////////////////////////////////////////

    protected function add_debug_headers() {
        $this->addHeader( 'X-SS-Wanted-Width', "=" . $this->wanted_width );
        $this->addHeader( 'X-SS-Wanted-Height', "=" . $this->wanted_height );
        $this->addHeader( 'X-SS-p-stuffpixrat', "=" . $_GET[ 'stuffpixrat' ] );
        $this->addHeader( 'X-SS-p-swifty', "=" . $_GET[ 'swifty' ] );
//        $this->addHeader( 'X-SS-p-ssrx', "=" . $_GET[ 'ssrx' ] ); // Does not always exists and then causes error log entries.
        $this->addHeader( 'X-SS-ori_width', "=" . $this->ori_width );
        $this->addHeader( 'X-SS-ori_height', "=" . $this->ori_height );
        $this->addHeader( 'X-SS-source_width', "=" . $this->source_width );
        $this->addHeader( 'X-SS-source_height', "=" . $this->source_height );
        $this->addHeader( 'X-SS-c1', "=" . $this->ssc1 );
        $this->addHeader( 'X-SS-c2', "=" . $this->ssc2 );
    }

    ////////////////////////////////////////

    protected function addErrorHeader( $error ) {
        if( $this->debug ) {
            $this->sendErrorImage( $error );
        } else {
            parent::addErrorHeader( $error );
        }
    }

    ////////////////////////////////////////

    public function crunch() {
        $this->calc_sizes();

        if( $this->target_width !== $this->ori_width  ) {
            $this->resize_image();
        } else {
            // No downsampling necessary, try to cache a copy of the original image to avoid subsequent php execution
            $this->no_resize_image();
        }
   	}

    ////////////////////////////////////////

    public function calc_sizes()
    {
        $wantedWidth = $this->wanted_width;
        $wantedHeight = $this->wanted_height;

        $this->target_width = intval( $wantedWidth );
        $this->target_height = intval( $wantedHeight );

        list( $ori_width, $ori_height, $type ) = getimagesize( $this->source_file );

        $this->ori_width = $ori_width;
        $this->ori_height = $ori_height;
        $this->source_width = $ori_width;
        $this->source_height = $ori_height;
        $this->tech_type = $type;
        $this->ori_type = image_type_to_extension( $this->tech_type, FALSE );

        $this->ori_ratio = $this->ori_width / $this->ori_height;

        $this->focus_center_x = $this->ori_width * ( $this->focus_perc_x + $this->focus_perc_w / 2 ) / 100.0;
        $this->focus_center_y = $this->ori_height * ( $this->focus_perc_y + $this->focus_perc_h / 2 ) / 100.0;

        # If no target_width requested, set it to ori_width or ori_height * ratio
        if( $this->target_width === -2 ) {
            if( $this->target_height === -2 ) {
                $this->target_width = $this->ori_width;
            } else {
                $this->target_width = round( $this->target_height * $this->ori_ratio );
            }
        }

        # If no target_height requested, set it to ori_width / ratio
        if( $this->target_height === -2 ) {
            $this->target_height = round( $this->target_width / $this->ori_ratio );
        }

        $this->target_ratio = $this->target_width / $this->target_height;

        # Determine the source_height based on target_height etc
        $this->source_height = round( $this->target_height * $this->ori_width / $this->target_width );

        # If this source_height is higher than ori_height, we need full height and reduced width
        if( $this->source_height > $this->ori_height ) {
            $this->source_width = round( $this->target_width * $this->ori_height / $this->target_height );
            $this->source_height = $this->ori_height;
        }

        # If we need a forced size (ratio distortion), just used the wanted and original sizes
        if( $this->forced_size === 1 ) {
            $this->target_width = intval( $wantedWidth );
            $this->target_height = intval( $wantedHeight );
            $this->source_width = $ori_width;
            $this->source_height = $ori_height;
        }

        # Center around focus rect center
        if( $this->source_width < $this->ori_width ) {
            $this->source_x = round( $this->focus_center_x - $this->source_width / 2 );
            if( $this->source_x + $this->source_width > $this->ori_width ) {
                $this->source_x = $this->ori_width - $this->source_width;
            }
            if( $this->source_x < 0 ) {
                $this->source_x = 0;
            }
        }
        if( $this->source_height < $this->ori_height ) {
            $this->source_y = round( $this->focus_center_y - $this->source_height / 2 );
            if( $this->source_y + $this->source_height > $this->ori_height ) {
                $this->source_y = $this->ori_height - $this->source_height;
            }
            if( $this->source_y < 0 ) {
                $this->source_y = 0;
            }
        }

        $this->target_ratio = $this->target_width / $this->target_height;
    }

    ////////////////////////////////////////

    public function resize_image() {
        $source = $this->source_file;
        $target = $this->cache_file;
        $saved = FALSE;

        switch( $this->tech_type ) {
            case IMAGETYPE_PNG:
                $saved = $this->downscalePng( $source, $target );
                break;
            case IMAGETYPE_JPEG:
                $saved = $this->downscaleJpeg( $source, $target, 'resize' );
                break;
            case IMAGETYPE_GIF:
                $saved = $this->downscaleGif( $source, $target );
                break;
        }

        if( $saved && @file_exists( $target ) ) {
            $this->return_image = $target;
        } else {
            $this->errors[] = 'resize failed';
        }
    }

    ////////////////////////////////////////

    public function no_resize_image() {
        $source = $this->source_file;
        $target = $this->cache_file;

        if( $this->ssc1 ) {
            if( $this->tech_type === IMAGETYPE_PNG ) {
                $saved = $this->colorPng( $source, $target );

                if ($saved && @file_exists($target)) {
                    $this->return_image = $target;
                } else {
                    $this->errors[] = 'colorization failed';
                }
            } else {
                $this->return_image = $target;
            }
        }
        else if( ! @symlink( $source, $target ) && ! @copy( $source, $target ) ) {
            $this->errors[] = 'copy original to cache failed';
            $this->return_image = $source/*$target*/;
        } else {
            if( $this->tech_type === IMAGETYPE_JPEG ) {
                $saved = $this->downscaleJpeg( $source, $target, 'optimize' );

                if ($saved && @file_exists($target)) {
                    $this->return_image = $target;
                } else {
                    $this->errors[] = 'optimize failed';
                }
            } else {
                $this->return_image = $target;
            }
        }
    }

    ////////////////////////////////////////

    protected function copy_source_area_to_dest( $targetImage, $sourceImage ) {
        imagecopyresampled( $targetImage, $sourceImage, 0, 0, $this->source_x, $this->source_y, $this->target_width, $this->target_height, $this->source_width, $this->source_height );
    }

    ////////////////////////////////////////

    protected function after_source_init( $sourceImage ) {
        if( $this->debug ) {
            $color_red = imagecolorallocate( $sourceImage, 255, 0, 0 );
            $color_blue = imagecolorallocate( $sourceImage, 0, 0, 255 );
            imagesetthickness( $sourceImage, $this->ori_width / 200 );
            $this->addHeader( 'X-SS-Rect-x1', '=' . $this->focus_perc_x * $this->ori_width / 100.0 );
            $this->addHeader( 'X-SS-Rect-y1', '=' . $this->focus_perc_y * $this->ori_height / 100.0 );
            $this->addHeader( 'X-SS-Rect-x2', '=' . ( $this->focus_perc_x + $this->focus_perc_w ) * $this->ori_width / 100.0 );
            $this->addHeader( 'X-SS-Rect-y2', '=' . ( $this->focus_perc_y + $this->focus_perc_h ) * $this->ori_height / 100.0 );
            $this->addHeader( 'X-SS-Rect-x3', '=' . $this->focus_center_x );
            $this->addHeader( 'X-SS-Rect-y3', '=' . $this->focus_center_y );
            imagerectangle( $sourceImage,
                $this->focus_perc_x * $this->ori_width / 100.0,
                $this->focus_perc_y * $this->ori_height / 100.0,
                ( $this->focus_perc_x + $this->focus_perc_w ) * $this->ori_width / 100.0,
                ( $this->focus_perc_y + $this->focus_perc_h ) * $this->ori_height / 100.0,
                $color_red );
            imagerectangle( $sourceImage,
                $this->focus_center_x - 10,
                $this->focus_center_y - 10,
                $this->focus_center_x + 10,
                $this->focus_center_y + 10,
                $color_blue );
            imagerectangle( $sourceImage,
                100,
                100,
                $this->ori_width - 100,
                $this->ori_height - 100,
                $color_blue );
        }
    }

    ////////////////////////////////////////

    protected function downscaleJpeg( $source, $target, $mod ) {
        $width = $this->source_width;
        $height = $this->source_height;
        $targetWidth = $this->target_width;
        $targetHeight = $this->target_height;

        $saved = TRUE;

        $sourceImage = @imagecreatefromjpeg($source);
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

        $this->after_source_init( $sourceImage );

        // Enable interlacing for progressive JPEGs
        imageinterlace($targetImage, true);

        $this->copy_source_area_to_dest( $targetImage, $sourceImage );
        $this->sharpenImage( $targetImage, $width, $targetWidth );

        if( $this->target_type === 'webp' ) {
            if( $this->debug ) {
                $this->addDebugInfoToImg( $targetImage, 5 );
            }

//            $saved = imagewebp( $targetImage, $target, min( 100, max( 1, intval( $this->jpg_quality ) ) ) );
//            $saved = imagewebp( $targetImage, $target, 76 ); // 75 outputs incorrect picture???

            $saved = imagepng( $targetImage, $target . '.png' );

            // dorh error check
            exec( 'cwebp -q ' . max( 1, intval( $this->jpg_quality ) ) . ' ' . $target . '.png -o ' . $target . '.tran', $result, $return_var );
            exec( 'mv -f ' . $target . '.tran ' . $target, $result, $return_var );
            $this->deleteFile( $target . '.png' );
        } else {
            if( $this->debug ) {
                $this->addDebugInfoToImg( $targetImage, 5 );
            }

            $target1 = $target;
            if( $mod !== 'optimize' || $this->debug ) {
                $saved = imagejpeg( $targetImage, $target, min( 100, max( 1, intval( $this->jpg_quality ) ) ) );
            } else {
                $target1 = $source;
            }

            $this->jpegTranIfSmaller( $target1, $target );
        }

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 72 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 73 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 74 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 75 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 76 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 77 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 78 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 79 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 80 );
        // measured / reported = rounded / webp q my test img
        //0.9940 / 0.99884919961106 = 0.9988
        //0.9945 / 0.99892105327777 = 0.9989
        //0.9950 / 0.99903838772162 = 0.9990
        //0.9950 / 0.99904720383598 = 0.9991
        //0.9960 / 0.99923967372306 = 0.9992
        //0.9965 / 0.99932857114707 = 0.9993
        //0.9970 / 0.99945298403711 = 0.9994
        //0.9975 / 0.9995290226736  = 0.9995 / 83
        //0.9980 / 0.99962416259571 = 0.9996
        //0.9985 / 0.99973697320081 = 0.9997
        //0.9990 / 0.99983893543648 = 0.9998

//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 50 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 55 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 60 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 65 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 70 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 75 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 80 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 85 );
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 90 );

// These are the desired ssim results from dssim / for a comparable jpeg quality of my test img
//         0.9940 / 40
//         0.9945 / 45
//         0.9950 / 50
//         0.9955 / 55
//         0.9960 / 60
//         0.9965 / 65
//         0.9970 / 70
//         0.9975 / 75
//         0.9980 / 80
//         0.9985 / 85
//         0.9990 / 90

        //                                                                                                           desired ssim
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.986500 ); //0.9940
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.987000 ); //0.9945
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.988600 ); //0.9950
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.989090 ); //0.9955
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.990500 ); //0.9960
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.991510 ); //0.9965
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.992970 ); //0.9970
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.994070 ); //0.9975
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.995270 ); //0.9980
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.997190 ); //0.9985
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.998080 ); //0.9990

//        $this->findImgViaSsim( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpeg', 75, 1 );
//        $this->findImgViaSsim( $source, $width, $height, $target, $targetWidth, $targetHeight, 'webp', 75, 1 );

        return $saved;
    }

    ////////////////////////////////////////

    protected function testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, $test_type, $test_quality ) {
        $sourceImage = @imagecreatefromjpeg($source);
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

        $this->copy_source_area_to_dest( $targetImage, $sourceImage );
        $this->sharpenImage( $targetImage, $width, $targetWidth );

        $this->deleteFile( $target . '.png' );
        $saved = imagepng( $targetImage, $target . '.png' );

        if( $test_type === 'webp' ) {
            $this->deleteFile( $target . '.tran' );
            $result2 = shell_exec( 'cwebp -print_ssim -q ' . $test_quality . ' ' . $target . '.png -o ' . $target . '.tran 2>&1' );
            $this->deleteFile( $target . '.2.png' );
            exec( 'dwebp ' . $target . '.tran -o ' . $target . '.2.png', $result, $return_var );
            preg_match("/.*SSIM:.*Total:(.*)/",$result2,$m);
            $ssim = floatval( $m[1] );
            $ssim /= -10.0;
            $ssim = pow( 10, $ssim );
            $ssim = 1 - $ssim;
            $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-ssim-reported', '=' . $ssim );
            $result_filename = $target . '.tran';
        } else if( $test_type === 'jpeg' ) {
            $result_filename = $target . '.jpg';
            $this->deleteFile( $result_filename );
            $saved = imagejpeg( $targetImage, $result_filename, $test_quality );
            $sourceImage2 = @imagecreatefromjpeg($result_filename);
            $this->deleteFile( $target . '.2.png' );
            $saved = imagepng( $sourceImage2, $target . '.2.png' );
            imagedestroy($sourceImage2);
        } else {
            $this->deleteFile( $target . '.100.jpg' );
            $saved = imagejpeg( $targetImage, $target . '.100.jpg', 100 );
            $result_filename = $target . '.jpg';
            $this->deleteFile( $result_filename );
            $result2 = shell_exec( 'jpeg-recompress  --target ' . $test_quality . ' ' . $target . '.100.jpg ' . $result_filename . ' 2>&1' );
            $sourceImage2 = @imagecreatefromjpeg($result_filename);
            $this->deleteFile( $target . '.2.png' );
            $saved = imagepng( $sourceImage2, $target . '.2.png' );
            imagedestroy($sourceImage2);
            preg_match("/.*Final optimized ssim at.*: (.*)/",$result2,$m);
            $result2 = floatval( $m[ 1 ] );
        }

        $result3 = shell_exec( 'dssim ' . $target . '.png ' . $target . '.2.png 2>&1' );
//        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-output', '=' . preg_replace( "/\r|\n/", "==", $result2 ) );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-filesize', $this->getFileSize( $result_filename, 1) );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-ssim-measured', '=' . ( 1 - floatval( preg_replace( "/\r|\n/", "==", $result3 ) ) ) );

        imagedestroy($sourceImage);
        imagedestroy($targetImage);
    }

    ////////////////////////////////////////

    protected function findImgViaSsim( $source, $width, $height, $target, $targetWidth, $targetHeight, $test_type, $test_quality, $test_reference ) {
        $test_dssim = floatval( '0.99' . $test_quality );
        $test_ssim = $test_dssim;

        $sourceImage = @imagecreatefromjpeg($source);
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

        $this->copy_source_area_to_dest( $targetImage, $sourceImage );
        $this->sharpenImage( $targetImage, $width, $targetWidth );

        if( $test_reference === 1 ) {
            imagejpeg( $targetImage, $target, $test_quality );
            $this->jpegTranIfSmaller( $target, $target );
            $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-filesize-reference', $this->getFileSize( $target, 1) );
        }

        if( $test_type === 'webp' ) {
            $this->deleteFile( $target . '.ssim.png' );
            imagepng( $targetImage, $target . '.ssim.png' );

            $test_ssim = $this->convertDsimmToWebpSimm( $test_dssim );

            $result_filename = $target;
            $ar_webp = array();
            $quality = $test_quality;

            $itter = 0;
            while( ( ++$itter ) <= 6 ) {
                list( $tran_file, $reported_ssim ) = $this->generateWebpWithSsim( $target . '.ssim.png', $target, $quality, $itter );
                $quality_begin_loop = $quality;
                $best_i = -1;
                if( $reported_ssim > $test_ssim ) {
                    $add = -10;
                    foreach( $ar_webp as $i => $webp ) {
                        if( $webp[ 1 ] < $test_ssim && ( $best_i === -1 || $webp[ 1 ] > $ar_webp[ $best_i ][ 1 ] ) ) {
                            $best_i = $i;
                        }
                    }
                } else {
                    $add = 10;
                    foreach( $ar_webp as $i => $webp ) {
                        if( $webp[ 1 ] > $test_ssim && ( $best_i === -1 || $webp[ 1 ] < $ar_webp[ $best_i ][ 1 ] ) ) {
                            $best_i = $i;
                        }
                    }
                }
                $this->addHeader( 'X-SS-Test-itter-' . $itter . '-' . $quality_begin_loop . '-add', '=' . $add );
                if( $best_i === -1 ) {
                    $quality += $add;
                } else {
                    $this->addHeader( 'X-SS-Test-itter-' . $itter . '-' . $quality_begin_loop . '-q1', '=' . $quality );
                    $this->addHeader( 'X-SS-Test-itter-' . $itter . '-' . $quality_begin_loop . '-q2', '=' . $ar_webp[ $best_i ][ 2 ] );
                    $quality = intval( ( $quality + $ar_webp[ $best_i ][ 2 ] ) / 2 );
                }
                $ar_webp[ ] = array( $tran_file, $reported_ssim, $quality_begin_loop );
                $quality_done_before = -1;
                foreach( $ar_webp as $i => $webp ) {
                    if( $webp[ 2 ] === $quality ) {
                        $quality_done_before = $i;
                    }
                }
                if( $quality_done_before !== -1 ) {
                    $itter = 999;
                }
            }

            $best_i = -1;
            foreach( $ar_webp as $i => $webp ) {
                if( $best_i === -1 || abs( $webp[ 1 ] - $test_ssim ) < abs( $ar_webp[ $best_i ][ 1 ] - $test_ssim ) ) {
                    $best_i = $i;
                }
            }

            $reported_quality = $ar_webp[ $best_i ][ 1 ];
            $reported_ssim = $ar_webp[ $best_i ][ 2 ];
            exec( 'mv -f ' . $ar_webp[ $best_i ][ 0 ] . ' ' . $target, $result, $return_var );

            foreach( $ar_webp as $i => $webp ) {
                $this->deleteFile( $ar_webp[ $i ][ 0 ] );
            }
        } else {
            $test_ssim = $this->convertDsimmToJpegRecompressSimm( $test_dssim );

            $this->deleteFile( $target . '.ssim100.jpg' );
            imagejpeg( $targetImage, $target . '.ssim100.jpg', 100 );
            $result_filename = $target;
            $this->deleteFile( $result_filename );
            $result2 = shell_exec( 'jpeg-recompress --strip --target ' . $test_ssim . ' ' . $target . '.ssim100.jpg ' . $result_filename . ' 2>&1' );
            preg_match( "/.*Final optimized ssim at q=(\\d+): (.*)/", $result2, $m );
            $reported_quality = floatval( $m[ 1 ] );
            $reported_ssim = floatval( $m[ 2 ] );
            $this->jpegTranIfSmaller( $target, $target );
            $this->deleteFile( $target . '.ssim100.jpg' );
        }

//        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-output', '=' . preg_replace( "/\r|\n/", "==", $result2 ) );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-filesize', $this->getFileSize( $result_filename, 1) );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-ssim-reported', '=' . $reported_ssim );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-quality-reported', '=' . $reported_quality );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-ssim-requested', '=' . $test_ssim );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-quality-requested', '=' . $test_quality );
        $this->addHeader( 'X-SS-Test-' . $test_type . '-' . $test_quality . '-dsimm-requested', '=' . $test_dssim );

        imagedestroy($sourceImage);
        imagedestroy($targetImage);
    }

    ////////////////////////////////////////

    protected function generateWebpWithSsim( $ref_file, $target, $test_quality, $itter ) {
        $this->deleteFile( $target . '.tran' );
        $tran_file = $target . '.' . $test_quality . '.tran';
        $result2 = shell_exec( 'cwebp -print_ssim -q ' . $test_quality . ' ' . $ref_file . ' -o ' . $tran_file . ' 2>&1' );
        preg_match( "/.*SSIM:.*Total:(.*)/", $result2, $m );
        $ssim = floatval( $m[1] );
        $ssim /= -10.0;
        $ssim = pow( 10, $ssim );
        $ssim = 1 - $ssim;
        $this->addHeader( 'X-SS-Test-itter-' . $itter . '-' . $test_quality . '-ssim-reported', '=' . $ssim );
        return array( $tran_file, $ssim );
    }

    ////////////////////////////////////////

    protected function deleteFile( $file ) {
        exec( 'rm -f ' . $file, $result, $return_var );
    }

    ////////////////////////////////////////

    protected function convertDsimmToWebpSimm( $test_dssim ) {
        // measured / reported = rounded / webp q my test img
        //0.9940 / 0.99884919961106 = 0.9988
        //0.9945 / 0.99892105327777 = 0.9989
        //0.9950 / 0.99903838772162 = 0.9990
        //0.9950 / 0.99904720383598 = 0.9991
        //0.9960 / 0.99923967372306 = 0.9992
        //0.9965 / 0.99932857114707 = 0.9993
        //0.9970 / 0.99945298403711 = 0.9994
        //0.9975 / 0.9995290226736  = 0.9995 / 83
        //0.9980 / 0.99962416259571 = 0.9996
        //0.9985 / 0.99973697320081 = 0.9997
        //0.9990 / 0.99983893543648 = 0.9998

        if( $test_dssim >= 0.9990 ) {
            $test_ssim = 0.9998;
        }
        else if( $test_dssim >= 0.9985 ) {
            $test_ssim = 0.9997;
        }
        else if( $test_dssim >= 0.9980 ) {
            $test_ssim = 0.9996;
        }
        else if( $test_dssim >= 0.9975 ) {
            $test_ssim = 0.9995;
        }
        else if( $test_dssim >= 0.9970 ) {
            $test_ssim = 0.9994;
        }
        else if( $test_dssim >= 0.9965 ) {
            $test_ssim = 0.9993;
        }
        else if( $test_dssim >= 0.9960 ) {
            $test_ssim = 0.9992;
        }
        else if( $test_dssim >= 0.9955 ) {
            $test_ssim = 0.9991;
        }
        else if( $test_dssim >= 0.9950 ) {
            $test_ssim = 0.9990;
        }
        else if( $test_dssim >= 0.9945 ) {
            $test_ssim = 0.9989;
        }
        else /*if( $test_dssim >= 0.9940 )*/ {
            $test_ssim = 0.9988;
        }
        return $test_ssim;
    }

    ////////////////////////////////////////

    protected function convertDsimmToJpegRecompressSimm( $test_dssim ) {
        //                                                                                                           desired ssim
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.986500 ); //0.9940
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.987000 ); //0.9945
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.988600 ); //0.9950
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.989090 ); //0.9955
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.990500 ); //0.9960
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.991510 ); //0.9965
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.992970 ); //0.9970
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.994070 ); //0.9975
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.995270 ); //0.9980
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.997190 ); //0.9985
//        $this->testCompression( $source, $width, $height, $target, $targetWidth, $targetHeight, 'jpg2', 0.998080 ); //0.9990

        if( $test_dssim >= 0.9990 ) {
            $test_ssim = 0.998080;
        }
        else if( $test_dssim >= 0.9985 ) {
            $test_ssim = 0.997190;
        }
        else if( $test_dssim >= 0.9980 ) {
            $test_ssim = 0.995270;
        }
        else if( $test_dssim >= 0.9975 ) {
            $test_ssim = 0.994070;
        }
        else if( $test_dssim >= 0.9970 ) {
            $test_ssim = 0.992970;
        }
        else if( $test_dssim >= 0.9965 ) {
            $test_ssim = 0.991510;
        }
        else if( $test_dssim >= 0.9960 ) {
            $test_ssim = 0.990500;
        }
        else if( $test_dssim >= 0.9955 ) {
            $test_ssim = 0.989090;
        }
        else if( $test_dssim >= 0.9950 ) {
            $test_ssim = 0.988600;
        }
        else if( $test_dssim >= 0.9945 ) {
            $test_ssim = 0.987000;
        }
        else /*if( $test_dssim >= 0.9940 )*/ {
            $test_ssim = 0.986500;
        }
        return $test_ssim;
    }

    ////////////////////////////////////////

    protected function jpegTranIfSmaller( $target, $target_dest ) {
        exec( 'jpegtran -copy none -optimize -progressive ' . $target . ' > ' . $target_dest . '.tran', $result, $return_var );
        $size = $this->getFileSize( $target_dest . '.tran' );
        if( $size > 0 && $size < $this->getFileSize( $target ) ) {
            exec( 'mv -f ' . $target_dest . '.tran ' . $target_dest, $result, $return_var );
        } else {
            $this->deleteFile( $target_dest . '.tran' );
            if( $target !== $target_dest ) {
                exec( 'cp -f ' . $target . ' ' . $target_dest, $result, $return_var );
            }
        }
    }

    ////////////////////////////////////////

    protected function getFileSize( $source, $kb = 0 ) {
        $size = trim(shell_exec('stat -c %s '.escapeshellarg( $source )));
        if( $kb === 1 ) {
            $size = intval( 10 * $size / 1024 ) / 10;
        }
        return $size;
    }

    ////////////////////////////////////////

    protected function downscalePng( $source, $target ) {
        $width = $this->source_width;
        $height = $this->source_height;
        $targetWidth = $this->target_width;
        $targetHeight = $this->target_height;

        $img_png_quantizer = 'internal';
        $img_png_quantizer_speed = 5;

        $targetImage	        		= imagecreatetruecolor($targetWidth, $targetHeight);

        // Determine active quantizer
        if ($img_png_quantizer === false) {
            $quantizer					= false;
        } else {
            $quantizer					= strtolower(trim($img_png_quantizer));
            $quantizer					= ($quantizer && array_key_exists($quantizer, self::$_quantizers)) ? $quantizer : self::QUANTIZER_INTERNAL;
        }

        /**
         * Determine the PNG type
         *
         * 0 - Grayscale
         * 2 - RGB
         * 3 - RGB with palette (= indexed)
         * 4 - Grayscale + alpha
         * 6 - RGB + alpha
         */
        $sourceType						= ord(@file_get_contents($source, false, null, 25, 1));
        $sourceImage					= @imagecreatefrompng($source);
        $sourceIndexed 					= !!($sourceType & 1);
        $sourceAlpha					= !!($sourceType & 4);
        $sourceTransparentIndex			= imagecolortransparent($sourceImage);
        $sourceIndexTransparency		= $sourceTransparentIndex >= 0;
        $sourceTransparentColor			= $sourceIndexTransparency ? imagecolorsforindex($sourceImage, $sourceTransparentIndex) : null;
        $sourceColors					= imagecolorstotal($sourceImage);

        // Determine if the resulting image should be quantized
        $quantize						= $quantizer && ($sourceIndexed || (($sourceColors > 0) && ($sourceColors <= 256)));

        // Support transparency on the target image if necessary
        if ($sourceIndexTransparency || $sourceAlpha) {
            $this->enableTranparency($targetImage, $sourceTransparentColor);
        }

        // If the resulting image should be quantized
        if ($quantize) {

            // If an external quantizer is available: Convert the source image to a TrueColor before downsampling
            if ($quantize && ($quantizer != self::QUANTIZER_INTERNAL)) {
                $tmpSourceImage				= imagecreatetruecolor($width, $height);

                // Enable transparency if necessary (index or alpha channel)
                if ($sourceIndexTransparency || $sourceAlpha) {
                    $this->enableTranparency($tmpSourceImage, $sourceTransparentColor);
                }

                imagecopy($tmpSourceImage, $sourceImage, 0, 0, 0, 0, $width, $height);
                imagedestroy($sourceImage);
                $sourceImage				= $tmpSourceImage;
                unset($tmpSourceImage);

            // Else: Use internal quantizer (convert to palette before downsampling)
            } elseif ($sourceIndexTransparency || $sourceAlpha) {
                imagetruecolortopalette($targetImage, true, $sourceColors);
            }
        }

        // Resize & resample the image
        $this->copy_source_area_to_dest( $targetImage, $sourceImage );

        // Sharpen image if possible and requested
        if ( !$quantize ) {
            // RH20150402 Sharpenimage is messing up transparency
//            $this->sharpenImage($targetImage, $width, $targetWidth);
        }

        // If the image should be quantized internally
        if ($quantize && ($quantizer == self::QUANTIZER_INTERNAL)) {
            imagetruecolortopalette($targetImage, true, $sourceColors);
        }

        $saved = imagepng($targetImage, $target);

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        // If the image should be quantized using an external tool
        if ($saved && $quantize && ($quantizer != self::QUANTIZER_INTERNAL)) {
            $cmd = sprintf(self::$_quantizers[$quantizer], max(1, min(10, intval($img_png_quantizer_speed))), escapeshellarg($target));
            @exec($cmd);
        }

        return $saved;
    }

    ////////////////////////////////////////

    protected function colorPng( $source, $target ) {
        $targetWidth = $this->target_width;
        $targetHeight = $this->target_height;

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $sourceImage = @imagecreatefrompng($source);

        imagefill( $targetImage, 0, 0, imagecolorallocate( $targetImage, 255, 255, 255 ) );

        $this->copy_source_area_to_dest( $targetImage, $sourceImage );

        list( $r, $g, $b ) = sscanf( $this->ssc1, "%02x%02x%02x" );
        $r /= 255;
        $g /= 255;
        $b /= 255;
        list( $r2, $g2, $b2 ) = sscanf( $this->ssc2, "%02x%02x%02x" );
        $r2 /= 255;
        $g2 /= 255;
        $b2 /= 255;

        for( $x = 0; $x < $this->source_width; $x++ ) {
            for( $y = 0; $y < $this->source_height; $y++ ) {
                $src_pix = imagecolorat( $targetImage, $x, $y );
                $pb = $src_pix & 0xFF;
                $pb255 = 255 - $pb;
                $pr = $pb * $r + $pb255 * $r2;
                $pg = $pb * $g + $pb255 * $g2;
                $pb = $pb * $b + $pb255 * $b2;
                imagesetpixel( $targetImage, $x, $y, imagecolorallocate( $targetImage, $pr, $pg, $pb ) );
            }
        }

        $saved = imagepng($targetImage, $target);

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        return $saved;
    }

    ////////////////////////////////////////

    protected function downscaleGif( $source, $target ) {
        $width = $this->source_width;
        $height = $this->source_height;
        $targetWidth = $this->target_width;
        $targetHeight = $this->target_height;

   		$sourceImage					= @imagecreatefromgif($source);
   		$targetImage	        		= imagecreatetruecolor($targetWidth, $targetHeight);

   		// Determine the transparent color
   		$sourceTransparentIndex			= imagecolortransparent($sourceImage);
   		$sourceTransparentColor			= ($sourceTransparentIndex >= 0) ? imagecolorsforindex($sourceImage, $sourceTransparentIndex) : null;

   		// Allocate transparency for the target image if needed
   		if ($sourceTransparentColor !== null) {
   			$targetTransparentColor		= imagecolorallocate($targetImage, $sourceTransparentColor['red'], $sourceTransparentColor['green'], $sourceTransparentColor['blue'] );
   			$targetTransparentIndex		= imagecolortransparent($targetImage, $targetTransparentColor);
       		imageFill($targetImage, 0, 0, $targetTransparentIndex);
   		}

   		// Resize & resample the image (no sharpening)
        $this->copy_source_area_to_dest( $targetImage, $sourceImage );

   		// Save the target GIF image
   		$saved							= imagegif($targetImage, $target);

   		// Destroy the source image descriptor
   		imagedestroy($sourceImage);

   		// Destroy the target image descriptor
   		imagedestroy($targetImage);

   		return $saved;
   	}

    ////////////////////////////////////////

    protected function sharpenImage($image, $width, $targetWidth) {
        if( $this->sharpen == TRUE ) {
            if( function_exists( 'imageconvolution' ) ) {
                $intFinal = $targetWidth * ( 750.0 / $width );
                $intA = 52;
                $intB = -0.27810650887573124;
                $intC = .00047337278106508946;
                $intRes = $intA + $intB * $intFinal + $intC * $intFinal * $intFinal;
                $intSharpness = max( round( $intRes ), 0 );
                $arrMatrix = array(
                    array( -1, -2, -1 ),
                    array( -2, $intSharpness + 12, -2 ),
                    array( -1, -2, -1 )
                );
                imageconvolution( $image, $arrMatrix, $intSharpness, 0 );
            }
        }
    }

    ////////////////////////////////////////

    function sendErrorImage($message) {
        $im = imagecreatetruecolor( 800, 300 );
        $text_color = imagecolorallocate( $im, 233, 14, 91 );
        $message_color = imagecolorallocate( $im, 91, 112, 233 );

        $this->addImgString( $im, 5, 5, 5, "Error:", $text_color );
        $this->addImgString( $im, 3, 5, 25, $message, $message_color );

        $this->addDebugInfoToImg( $im, 85 );

        header( "Cache-Control: no-store" );
        header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() - 1000 ) . ' GMT' );
        header( 'Content-Type: image/jpeg' );
        imagejpeg( $im );
        imagedestroy( $im );
        exit();
    }

    ////////////////////////////////////////

    function addDebugInfoToImg( $im, $y ) {
        $text_color = imagecolorallocate( $im, 255, 255, 255 );

        $y = $this->addImgString( $im, 5, 5, $y, "Info:", $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "Size ORI " . $this->ori_width . ' x ' . $this->ori_height . " TARGET " . $this->target_width . ' x ' . $this->target_height, $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "TYPE ORI " . $this->ori_type . " TRGET " . $this->target_type . " QUALITY " . $this->jpg_quality, $text_color );
//        $y = $this->addImgString( $im, 3, 5, $y, "DOCUMENT ROOT " . $_SERVER[ 'DOCUMENT_ROOT' ], $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "REQUESTED URI " . $this->requested_uri, $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "SOURCE FILE " . $this->source_file, $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "CACHE FILE " . $this->cache_file, $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "AGENT " . $_SERVER['HTTP_USER_AGENT'], $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "QUERY " . $_SERVER['QUERY_STRING'], $text_color );
        $y = $this->addImgString( $im, 3, 5, $y, "COOKIE " . $_SERVER['HTTP_COOKIE'], $text_color );
    }

    ////////////////////////////////////////

    function addImgString( $im, $ix, $x, $y, $s, $c ) {
        $keer = 0;
        $maxchar = 80;
        while( strlen( $s ) > 0 && $keer < 5 ) {
            $s1 = $s;
            if( strlen( $s1 ) > $maxchar ) {
                $s1 = substr( $s1, 0, $maxchar );
            }
            $s = substr( $s, strlen( $s1 ) );
            $this->addImgStringPart( $im, $ix, $x, $y, $s1, $c );
            $y += 12;
            $keer++;
        }

        return $y;
    }

    ////////////////////////////////////////

    function addImgStringPart( $im, $ix, $x, $y, $s, $c ) {
        $shadow_color = imagecolorallocate( $im, 0, 0, 0 );
        imagestring( $im, $ix, $x - 1, $y, $s, $shadow_color );
        imagestring( $im, $ix, $x + 1, $y, $s, $shadow_color );
        imagestring( $im, $ix, $x, $y - 1, $s, $shadow_color );
        imagestring( $im, $ix, $x, $y + 1, $s, $shadow_color );
        imagestring( $im, $ix, $x - 1, $y - 1, $s, $shadow_color );
        imagestring( $im, $ix, $x - 1, $y + 1, $s, $shadow_color );
        imagestring( $im, $ix, $x + 1, $y - 1, $s, $shadow_color );
        imagestring( $im, $ix, $x + 1, $y + 1, $s, $shadow_color );

        imagestring( $im, $ix, $x, $y, $s, $c );
    }

    ////////////////////////////////////////

    protected function enableTranparency($image, array $transparentColor = null) {
        if ($transparentColor === null) {
            $transparentColor = array('red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 127);
        }
        $targetTransparent = imagecolorallocatealpha($image, $transparentColor['red'], $transparentColor['green'], $transparentColor['blue'], $transparentColor['alpha']);
        $targetTransparentIndex = imagecolortransparent($image, $targetTransparent);
        imageFill($image, 0, 0, $targetTransparent);
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

}

new SwiftyCrunchImg;
