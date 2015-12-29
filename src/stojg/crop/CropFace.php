<?php

namespace stojg\crop;

/**
 * CropFace
 *
 * This class will try to find the most interesting point in the image by trying to find a face and
 * center the crop on that
 *
 * @todo implement
 * @see https://github.com/mauricesvay/php-facedetection/blob/master/FaceDetector.php
 */
class CropFace extends CropEntropy
{
    const CLASSIFIER_FACE = '/classifier/haarcascade_frontalface_alt.xml';
    // const CLASSIFIER_EYES = '/classifier/frontalEyes35x16.xml';
    const CLASSIFIER_EYES = '/classifier/Eyes22x5.1.xml';
    const CLASSIFIER_PROFILE_EYES = '/classifier/haarcascade_eye.xml';
    
    // const CLASSIFIER_FACE = '/classifier/haarcascade_frontalface_alt_tree.xml';
    
    const CLASSIFIER_PROFILE = '/classifier/haarcascade_profileface.xml';
    const CLASSIFIER_BODY = '/classifier/haarcascade_fullbody.xml';

    /**
     * imagePath original image path
     *
     * @var mixed
     * @access protected
     */
    protected $imagePath;

    /**
     * safeZoneList
     *
     * @var array
     * @access protected
     */
    protected $safeZoneList=[];

    /**
     *
     * @param string $imagePath
     */
    public function __construct($imagePath)
    {
        $this->imagePath = $imagePath;
        parent::__construct($imagePath);
    }

    /**
     * getFaceList get faces positions and sizes
     *
     * @access protected
     * @return array
     */
    protected function getFaceList()
    {
        if (!function_exists('face_detect')) {
            $msg = 'PHP Facedetect extension must be installed.
                    See http://www.xarg.org/project/php-facedetect/ for more details';
            throw new \Exception($msg);
        }

        $faceList = $this->getFaceListFromClassifier(self::CLASSIFIER_FACE);
        $profileList = $this->getFaceListFromClassifier(self::CLASSIFIER_PROFILE);
        
        $facialEyesList = $this->getFaceListFromClassifier(self::CLASSIFIER_EYES);
        
        $profileEyesList = $this->getFaceListFromClassifier(self::CLASSIFIER_PROFILE_EYES);

        // $bodyList = $this->getFaceListFromClassifier(self::CLASSIFIER_BODY);

        $faceList = $this->validateFaces($faceList, $facialEyesList);
        
        $profileList = $this->validateFaces($profileList, $profileEyesList);

        $faceList = array_merge($faceList, $profileList);

        return $faceList;
    }

    /**
     * Validate and filter the face detection by checking if there eyes detection inside
     * 
     * @param array $faces
     * @param array $eyes
     * @return array
     */
    protected function validateFaces($faces, $eyes = [])
    {
        if (count($faces) < 2 || !$eyes) {
            return $faces;
        }

        return array_filter($faces, function($face) use ($eyes) {
            $face_found = false;
            foreach ($eyes as $i => $eyes_detection) {
                if (
                    ($eyes_detection['x'] > $face['x'] && $eyes_detection['x'] < ($face['x'] + $face['w'])) && 
                    ($eyes_detection['y'] > $face['y'] && $eyes_detection['y'] < ($face['y'] + $face['h']))) {
                    
                    $face_found = true;
                    unset($eyes[$i]);
                    break;
                }
            }
            return $face_found;
        });
    }

    /**
     * getFaceListFromClassifier
     *
     * @param string $classifier
     * @access protected
     * @return array
     */
    protected function getFaceListFromClassifier($classifier)
    {
        $faceList = face_detect($this->imagePath, __DIR__ . $classifier);

        if (! $faceList && ! is_array($faceList)) {
            $faceList = [];
        }

        return $faceList;
    }

    /**
     * Set the areas of the image
     *
     * @param array $list
     */
    public function setSpecialMetadata($list)
    {
        parent::setSpecialMetadata($list);

        $size = $this->originalImage->getImageGeometry();
        $key = $this->getSafeZoneKey($size['width'], $size['height']);

        if (! array_key_exists($key, $this->safeZoneList)) {
            $this->safeZoneList[$key] = $list;
        }

        return $this;
    }

    /**
     * getSafeZoneList
     *
     * @access private
     * @return array
     */
    protected function getSafeZoneList()
    {
        if (!isset($this->safeZoneList)) {
            $this->safeZoneList = array();
        }
        // the local key is the current image width-height
        $key = $this->getSafeZoneKey($this->originalImage->getImageWidth(), $this->originalImage->getImageHeight());
        // $key = $this->originalImage->getImageWidth() . '-' . $this->originalImage->getImageHeight();

        if (!isset($this->safeZoneList[$key])) {
            $faceList = $this->getFaceList();

            // getFaceList works on the main image, so we use a ratio between main/current image
            $xRatio = $this->getBaseDimension('width') / $this->originalImage->getImageWidth();
            $yRatio = $this->getBaseDimension('height') / $this->originalImage->getImageHeight();

            $safeZoneList = array();
            foreach ($faceList as $face) {
                
                $hw = ceil($face['w'] / 2);
                $hh = ceil($face['h'] / 2);
                $safeZone = array(
                    'left' => $face['x'] - $hw,
                    'right' => $face['x'] + $face['w'] + $hw,
                    'top' => $face['y'] - $hh,
                    'bottom' => $face['y'] + $face['h'] + $hh
                );

                $safeZoneList[] = array(
                    'left' => round($safeZone['left'] / $xRatio),
                    'right' => round($safeZone['right'] / $xRatio),
                    'top' => round($safeZone['top'] / $yRatio),
                    'bottom' => round($safeZone['bottom'] / $yRatio),
                );
            }
            $this->safeZoneList[$key] = $safeZoneList;
        }

        return $this->safeZoneList[$key];
    }
}
