<?php

namespace stojg\crop;

/**
 *
 * Base class for all Croppers
 *
 */
abstract class Crop
{
    /**
     * Timer used for profiler / debugging
     *
     * @var float
     */
    protected static $start_time = 0.0;

    /**
     *
     * @var \Imagick
     */
    protected $originalImage = null;

    /**
     * baseDimension
     *
     * @var array
     * @access protected
     */
    protected $baseDimension;

    /**
     * Profiling method
     */
    public static function start()
    {
        self::$start_time = microtime(true);
    }

    /**
     * Profiling method
     *
     * @return string
     */
    public static function mark()
    {
        $end_time = (microtime(true) - self::$start_time) * 1000;

        return sprintf("%.1fms", $end_time);
    }

    /**
     *
     * @param string $imagePath - The path to an image to load. Paths can include wildcards for file names,
     *							  or can be URLs.
     */
    public function __construct($imagePath = null)
    {
        if ($imagePath) {
            $this->setImage(new \Imagick($imagePath));
        }
    }

    /**
     * Sets the object Image to be croped
     *
     * @param  \Imagick $image
     * @return null
     */
    public function setImage(\Imagick $image)
    {
        $this->originalImage = $image;

        // set base image dimensions
        $this->setBaseDimensions(
            $this->originalImage->getImageWidth(),
            $this->originalImage->getImageHeight()
        );
    }

    /**
     * Get the area in pixels for this image
     *
     * @param  \Imagick $image
     * @return int
     */
    protected function area(\Imagick $image)
    {
        $size = $image->getImageGeometry();

        return $size['height'] * $size['width'];
    }

    /**
     * Resize and crop the image so it dimensions matches $targetWidth and $targetHeight
     *
     * @param  int              $targetWidth
     * @param  int              $targetHeight
     * @return boolean|\Imagick
     */
    public function resizeAndCrop($targetWidth, $targetHeight, $debug=false)
    {
        // First get the size that we can use to safely trim down the image without cropping any sides
        $crop = $this->getSafeResizeOffset($this->originalImage, $targetWidth, $targetHeight);


        // maybe we can first detect the main object from the image and then resize or crop
        // --------------------------------------------------

        // the maximum we can scale down the image before we crop
        $max_scale_down = $crop;

        $safeZones = $this->getSafeZoneList();


        $imageSize = $this->originalImage->getImageGeometry();



        // calc all the zones into one one big Safe Area
        if (is_array($safeZones)) {

            $safeArea = [
                'left'=>$imageSize['width'],
                'right'=>0,
                'top'=>$imageSize['height'],
                'bottom'=>0,
            ];

            foreach ($safeZones as $zone) {

                $safeArea['left'] = $safeArea['left'] > $zone['left'] ? $zone['left'] : $safeArea['left'];
                $safeArea['right'] = $safeArea['right'] < $zone['right'] ? $zone['right'] : $safeArea['right'];
                $safeArea['top'] = $safeArea['top'] > $zone['top'] ? $zone['top'] : $safeArea['top'];
                $safeArea['bottom'] = $safeArea['bottom'] < $zone['bottom'] ? $zone['bottom'] : $safeArea['bottom'];
            
                if ($safeArea['left'] < 0) $safeArea['left'] = 0;
                if ($safeArea['top'] < 0) $safeArea['top'] = 0;

            }
        }


        // $safeZone = $safeZones[0];
        $safeAreaSize = [
            'width' => $safeArea['right']-$safeArea['left'],
            'height' => $safeArea['bottom']-$safeArea['top'],
        ];


        // calc the ratio between the targets and the image size
        $canvas_w_ratio = $imageSize['width'] / $targetWidth;
        $canvas_h_ratio = $imageSize['height'] / $targetHeight;

        $canvas_ratio = $canvas_w_ratio;
        if ($canvas_ratio > $canvas_h_ratio) {
            $canvas_ratio = $canvas_h_ratio;
        }


        // var_dump($canvas_ratio); exit;      // 1.19.... 

        // var_dump($imageSize);
        // var_dump($safeAreaSize);
        // exit;



        // get the side from which we need to calculate ratio

        // ratio between originalImage and object
        $object_w_ratio = $imageSize['width'] / $safeAreaSize['width'];
        // var_dump($w_ratio);
        $object_h_ratio = $imageSize['height'] / $safeAreaSize['height'];
        $object_ratio = $object_w_ratio;
        // take the smaller ratio 
        if ($object_w_ratio > $object_h_ratio) {
            $object_ratio = $object_h_ratio;
        }

        // var_dump($object_ratio); exit;          // 2.14.....


        // we need to check how much the safe area will scale down
        // we have the canvas scaling down ratio, so use it
        $scaled_down_safeArea = [
            'width' => $safeAreaSize['width']/$canvas_ratio,
            'height' => $safeAreaSize['height']/$canvas_ratio,
        ];

        // then compare the new smaller size to the targeted width and height

        // if the new SafeArea size fits in the targetted dimensions
        // so we do not need to scale the image down to the maximum

        // if ($object_ratio < $canvas_ratio) {

            // check if the ratio is bigger than 30 percent
            if ($object_ratio > 1.3 && $canvas_ratio > 1.3) {

                $object_ratio = 1.3;
            
                $crop = [
                    'width' => $crop['width'] * $object_ratio,
                    'height' => $crop['height'] * $object_ratio,
                ];

            }
        // }


        // -------------------------------------------------------------------------------------


        // Resize the image
        $this->originalImage->resizeImage($crop['width'], $crop['height'], \Imagick::FILTER_CUBIC, .5);
        
    

        // $safeZones = $this->getSafeZoneList();


        // Get the offset for cropping the image further
        $offset = $this->getSpecialOffset($this->originalImage, $targetWidth, $targetHeight);
        
        // var_dump($offset);

        // var_dump($safeAreaSize);

        // exit;


        // if (($offset['x'] + $targetWidth < $safeAreaSize['width']) || ($offset['y'] + $targetHeight) < $safeAreaSize['height']) {

        //     echo 'offff';
        //     exit;

        // }



        // if (strpos($_SERVER['REQUEST_URI'], 'debug=1') !== false) {
        //     $safeZones = $this->getSafeZoneList();    
        // }
        


        // Crop the image
        $this->originalImage->cropImage($targetWidth, $targetHeight, $offset['x'], $offset['y']);




        // if (strpos($_SERVER['REQUEST_URI'], 'debug=1') !== false) {

        //     $drawing = new \ImagickDraw;
        
        //     $drawing->setStrokeColor( new \ImagickPixel( 'yellow' ) );
        //     $drawing->setStrokeWidth(1);
        //     $drawing->setFillOpacity(0);


        //     foreach ($safeZones as $zone) {
            
        //         $drawing->rectangle($zone['left'], $zone['top'], $zone['right'], $zone['bottom']);    // Draw the rectangle

        //         $this->originalImage->drawImage($drawing);
        //     }
        //     // $this->display($this->originalImage);
        // }

        return $this->originalImage;
    }

    /**
     * Returns width and height for resizing the image, keeping the aspect ratio
     * and allow the image to be larger than either the width or height
     *
     * @param  \Imagick $image
     * @param  int      $targetWidth
     * @param  int      $targetHeight
     * @return array
     */
    protected function getSafeResizeOffset(\Imagick $image, $targetWidth, $targetHeight)
    {
        $source = $image->getImageGeometry();
        if (($source['width'] / $source['height']) < ($targetWidth / $targetHeight)) {
            $scale = $source['width'] / $targetWidth;
        } else {
            $scale = $source['height'] / $targetHeight;
        }

        return array('width' => (int) ($source['width'] / $scale), 'height' => (int) ($source['height'] / $scale));
    }

    /**
     * Returns a YUV weighted greyscale value
     *
     * @param  int $r
     * @param  int $g
     * @param  int $b
     * @return int
     * @see http://en.wikipedia.org/wiki/YUV
     */
    protected function rgb2bw($r, $g, $b)
    {
        return ($r*0.299)+($g*0.587)+($b*0.114);
    }

    /**
     *
     * @param  array $histogram - a value[count] array
     * @param  int   $area
     * @return float
     */
    protected function getEntropy($histogram, $area)
    {
        $value = 0.0;

        $colors = count($histogram);
        for ($idx = 0; $idx < $colors; $idx++) {
            // calculates the percentage of pixels having this color value
            $p = $histogram[$idx]->getColorCount() / $area;
            // A common way of representing entropy in scalar
            $value = $value + $p * log($p, 2);
        }
        // $value is always 0.0 or negative, so transform into positive scalar value
        return -$value;
    }

    /**
     * setBaseDimensions
     *
     * @param int $width
     * @param int $height
     * @access protected
     * @return $this
     */
    protected function setBaseDimensions($width, $height)
    {
        $this->baseDimension = array('width' => $width, 'height' => $height);

        return $this;
    }

    /**
     * getBaseDimension
     *
     * @param string $key width|height
     * @access protected
     * @return int
     */
    protected function getBaseDimension($key)
    {
        if (isset($this->baseDimension)) {
            return $this->baseDimension[$key];
        } elseif ($key == 'width') {
            return $this->originalImage->getImageWidth();
        } else {
            return $this->originalImage->getImageHeight();
        }
    }


    /**
     * Temp method
     * for debugging purposes only
     */
    protected function display($image)
    {
        header("Content-type: image/".$image->getImageFormat());
        echo $image;
    }


    /**
     * get special offset for class
     *
     * @param  \Imagick $original
     * @param  int      $targetWidth
     * @param  int      $targetHeight
     * @return array
     */
    abstract protected function getSpecialOffset(\Imagick $original, $targetWidth, $targetHeight);
}
