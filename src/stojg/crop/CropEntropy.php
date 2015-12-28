<?php

namespace stojg\crop;

/**
 * SlyCropEntropy
 *
 * This class finds the a position in the picture with the most energy in it.
 *
 * Energy is in this case calculated by this
 *
 * 1. Take the image and turn it into black and white
 * 2. Run a edge filter so that we're left with only edges.
 * 3. Find a piece in the picture that has the highest entropy (i.e. most edges)
 * 4. Return coordinates that makes sure that this piece of the picture is not cropped 'away'
 *
 */
class CropEntropy extends Crop
{
    const POTENTIAL_RATIO = 1.5;

    /**
     * get special offset for class
     *
     * @param  \Imagick $original
     * @param  int      $targetWidth
     * @param  int      $targetHeight
     * @return array
     */
    protected function getSpecialOffset(\Imagick $original, $targetWidth, $targetHeight)
    {
        return $this->getEntropyOffsets($original, $targetWidth, $targetHeight);
    }

    /**
     * Get the topleftX and topleftY that will can be passed to a cropping method.
     *
     * @param  \Imagick $original
     * @param  int      $targetWidth
     * @param  int      $targetHeight
     * @return array
     */
    protected function getEntropyOffsets(\Imagick $original, $targetWidth, $targetHeight)
    {
        $measureImage = clone($original);
        // Enhance edges
        $measureImage->edgeimage(1);
        // Turn image into a grayscale
        $measureImage->modulateImage(100, 0, 100);
        // Turn everything darker than this to pitch black
        $measureImage->blackThresholdImage("#070707");
        // Get the calculated offset for cropping
        return $this->getOffsetFromEntropy($measureImage, $targetWidth, $targetHeight);
    }

    /**
     * Get the offset of where the crop should start
     *
     * @param  \Imagick $image
     * @param  int      $targetHeight
     * @param  int      $targetHeight
     * @param  int      $sliceSize
     * @return array
     */
    protected function getOffsetFromEntropy(\Imagick $originalImage, $targetWidth, $targetHeight)
    {
        // The entropy works better on a blured image
        $image = clone $originalImage;
        $image->blurImage(3, 2);

        $size = $image->getImageGeometry();

        $originalWidth = $size['width'];
        $originalHeight = $size['height'];

        // landscape = 1
        // portrait = 2

        $slice_index = 1;
        if ($originalWidth < $originalHeight) {
            $slice_index = 2;
        }

        $leftX = $this->slice($image, $originalWidth, $targetWidth, 'h', $slice_index);
        $topY = $this->slice($image, $originalHeight, $targetHeight, 'v', $slice_index);

        return array('x' => $leftX, 'y' => $topY);
    }

    /**
     * slice
     *
     * @param mixed $image
     * @param mixed $originalSize
     * @param mixed $targetSize
     * @param mixed $axis         h=horizontal, v = vertical
     * @access protected
     * @return void
     */
    protected function slice($image, $originalSize, $targetSize, $axis, $slice_index)
    {
        $aSlice = null;
        $bSlice = null;

        // Just an arbitrary size of slice size
        // $sliceSize = ceil(($originalSize - $targetSize) / 25);
        $sliceSize = ceil(($originalSize / $targetSize) * 10) * $slice_index;

        $aBottom = $originalSize;
        $aTop = 0;

        // while there still are uninvestigated slices of the image
        while ($aBottom - $aTop > $targetSize) {
            // Make sure that we don't try to slice outside the picture
            $sliceSize = min($aBottom - $aTop - $targetSize, $sliceSize);

            // Make a top slice image
            if (!$aSlice) {
                $aSlice = clone $image;
                if ($axis === 'h') {
                    $aSlice->cropImage($sliceSize, $originalSize, $aTop, 0);
                } else {
                    $aSlice->cropImage($originalSize, $sliceSize, 0, $aTop);
                }
            }

            // Make a bottom slice image
            if (!$bSlice) {
                $bSlice = clone $image;
                if ($axis === 'h') {
                    $bSlice->cropImage($sliceSize, $originalSize, $aBottom - $sliceSize, 0);
                } else {
                    $bSlice->cropImage($originalSize, $sliceSize, 0, $aBottom - $sliceSize);
                }
            }

            // calculate slices potential
            $aPosition = ($axis === 'h' ? 'left' : 'top');
            $bPosition = ($axis === 'h' ? 'right' : 'bottom');

            $aPot = $this->getPotential($aPosition, $aTop, $sliceSize);
            $bPot = $this->getPotential($bPosition, $aBottom, $sliceSize);

            $canCutA = ($aPot <= 0);
            $canCutB = ($bPot <= 0);

            // if no slices are "cutable", we force if a slice has a lot of potential
            if (!$canCutA && !$canCutB) {
                if ($aPot * self::POTENTIAL_RATIO < $bPot) {
                    $canCutA = true;
                } elseif ($aPot > $bPot * self::POTENTIAL_RATIO) {
                    $canCutB = true;
                }
            }

            // if we can only cut on one side
            if ($canCutA xor $canCutB) {
                if ($canCutA) {
                    $aTop += $sliceSize;
                    $aSlice = null;
                } else {
                    $aBottom -= $sliceSize;
                    $bSlice = null;
                }
            } elseif ($this->grayscaleEntropy($aSlice) < $this->grayscaleEntropy($bSlice)) {
                // bSlice has more entropy, so remove aSlice and bump aTop down
                $aTop += $sliceSize;
                $aSlice = null;
            } else {
                $aBottom -= $sliceSize;
                $bSlice = null;
            }
        }

        return $aTop;
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
        if (strpos($_SERVER['REQUEST_URI'], 'debug=1') !== false) {
            $this->debug = true; 
        }

        // $this->debug = true;

        // First get the size that we can use to safely trim down the image without cropping any sides
        $crop = $this->getSafeResizeOffset($this->originalImage, $targetWidth, $targetHeight);

        // maybe we can first detect the main object from the image and then resize or crop
        // --------------------------------------------------

        $safeZones = $this->getSafeZoneList();

        $this->setSpecialMetadata($safeZones);

        // calc all the zones into one one big Safe Area
        if (is_array($safeZones) && $safeZones) {

            $imageSize = $this->originalImage->getImageGeometry();

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

            // if the new SafeArea size fits in the targetted dimensions
            // so we do not need to scale the image down to the maximum

            // check if the ratio is bigger than 30 percent
            if ($object_ratio > 1.3 && $canvas_ratio > 1.3) {

                $object_ratio = 1.3;
            
                $crop = [
                    'width' => (int) $crop['width'] * $object_ratio,
                    'height' => (int) $crop['height'] * $object_ratio,
                ];

                $key = $this->getSafeZoneKey($imageSize['width'], $imageSize['height']);
                $new_key = $this->getSafeZoneKey($crop['width'], $crop['height']);
                $this->adjustSafeZoneList($key, $new_key, $crop['width'] / $imageSize['width']);
            }
        }

        // -------------------------------------------------------------------------------------

        // Resize the image
        $this->originalImage->resizeImage($crop['width'], $crop['height'], \Imagick::FILTER_CUBIC, .5);

        // Get the offset for cropping the image further
        $offset = $this->getSpecialOffset($this->originalImage, $targetWidth, $targetHeight);
        
        if ($this->debug) {

            $safeZones = $this->getSafeZoneList();

            $drawing = new \ImagickDraw;
        
            $drawing->setStrokeColor(new \ImagickPixel('yellow'));
            $drawing->setStrokeWidth(1);
            $drawing->setFillOpacity(0);

            foreach ($safeZones as $zone) {
                $drawing->rectangle($zone['left'], $zone['top'], $zone['right'], $zone['bottom']);
                // Draw the rectangle
                $this->originalImage->drawImage($drawing);
            }
            // $this->display($this->originalImage);
        }

        // Crop the image
        $this->originalImage->cropImage($targetWidth, $targetHeight, $offset['x'], $offset['y']);

        return $this->originalImage;
    }

    

    /**
     * getSafeZoneList
     *
     * @access protected
     * @return array
     */
    protected function getSafeZoneList()
    {
        return array();
    }

    /**
     * Safe zone list key
     *
     * @param $width
     * @param $height
     *
     * @access protected
     *
     * @return string
     */
    protected function getSafeZoneKey($width, $height)
    {
        return sprintf("%s-%s", $width, $height);
    }

    /**
     * Adjust the safe zone area list by ratio
     * Avoid the detection again
     * 
     * @param string $key
     * @param string $new_key
     * @param float $ratio
     */
    protected function adjustSafeZoneList($key, $new_key, $ratio)
    {
        $safeZoneList = $this->safeZoneList[$key];

        foreach ($safeZoneList as &$zone) {
            $zone['left'] = $zone['left'] * $ratio;
            $zone['top'] = $zone['top'] * $ratio;
            $zone['right'] = $zone['right'] * $ratio;
            $zone['bottom'] = $zone['bottom'] * $ratio;
        }

        $this->safeZoneList[$new_key] = $safeZoneList;

        return;
    }

    /**
     * getPotential
     *
     * @param mixed $position
     * @param mixed $top
     * @param mixed $sliceSize
     * @access protected
     * @return void
     */
    protected function getPotential($position, $top, $sliceSize)
    {
        $safeZoneList = $this->getSafeZoneList();

        $safeRatio = 0;

        if ($position == 'top' || $position == 'left') {
            $start = $top;
            $end = $top + $sliceSize;
        } else {
            $start = $top - $sliceSize;
            $end = $top;
        }

        for ($i = $start; $i < $end; $i++) {
            foreach ($safeZoneList as $safeZone) {
                if ($position == 'top' || $position == 'bottom') {
                    if ($safeZone['top'] <= $i && $safeZone['bottom'] >= $i) {
                        $safeRatio = max($safeRatio, ($safeZone['right'] - $safeZone['left']));
                    }
                } else {
                    if ($safeZone['left'] <= $i && $safeZone['right'] >= $i) {
                        $safeRatio = max($safeRatio, ($safeZone['bottom'] - $safeZone['top']));
                    }
                }
            }
        }

        return $safeRatio;
    }

    /**
     * Calculate the entropy for this image.
     *
     * A higher value of entropy means more noise / liveliness / color / business
     *
     * @param  \Imagick $image
     * @return float
     *
     * @see http://brainacle.com/calculating-image-entropy-with-python-how-and-why.html
     * @see http://www.mathworks.com/help/toolbox/images/ref/entropy.html
     */
    protected function grayscaleEntropy(\Imagick $image)
    {
        // The histogram consists of a list of 0-254 and the number of pixels that has that value
        $histogram = $image->getImageHistogram();

        return $this->getEntropy($histogram, $this->area($image));
    }

    /**
     * Find out the entropy for a color image
     *
     * If the source image is in color we need to transform RGB into a grayscale image
     * so we can calculate the entropy more performant.
     *
     * @param  \Imagick $image
     * @return float
     */
    protected function colorEntropy(\Imagick $image)
    {
        $histogram = $image->getImageHistogram();
        $newHistogram = array();

        // Translates a color histogram into a bw histogram
        $colors = count($histogram);
        for ($idx = 0; $idx < $colors; $idx++) {
            $colors = $histogram[$idx]->getColor();
            $grey = $this->rgb2bw($colors['r'], $colors['g'], $colors['b']);
            if (!isset($newHistogram[$grey])) {
                $newHistogram[$grey] = $histogram[$idx]->getColorCount();
            } else {
                $newHistogram[$grey] += $histogram[$idx]->getColorCount();
            }
        }

        return $this->getEntropy($newHistogram, $this->area($image));
    }
}
