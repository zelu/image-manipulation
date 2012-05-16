<?php
/**
 * GD2 driver.
 *
 * @author Marcin Klawitter <marcin.klawitter@gmail.com>
 */
class Image_GD2 extends Image
{
    // Temporary image resource
    private $image;

    // Function name to open Image
    private $create_function;

    public function __construct($file)
    {
        if(!$this->driver_check) {
            $this->driver_check();
        }

        parent::__construct($file);

        // Set the image creation function name
        switch($this->type)
        {
            case IMAGETYPE_JPEG:
                $create = 'imagecreatefromjpeg';
                break;
            case IMAGETYPE_GIF:
                $create = 'imagecreatefromgif';
                break;
            case IMAGETYPE_PNG:
                $create = 'imagecreatefrompng';
                break;
        }

        if (!isset($create) || !function_exists($create)) {
            throw new Image_Exception('Installed GD does not support :type images',
                array(':type'=>image_type_to_extension($this->type, FALSE)));
        }

        // Save function for future use
        $this->create_function = $create;

        // Save filename for lazy loading
        $this->image = $this->file;
    }

    /**
     * Destroys the loaded image to free up resources.
     * @return  void
     */
    public function __destruct()
    {
        if(is_resource($this->image)) {
            // Free all resources
            imagedestroy($this->image);
        }
    }

    /**
     * Checks if GD is enabled.
     * @return  bool
     */
    private function driver_check()
    {
        if(!function_exists('gd_info')) {
            throw new Image_Exception('Missing GD2 library');
        }

        if(defined('GD_VERSION')) {
            // Get the version via a constant, available in PHP5
            $version = GD_VERSION;
        }
        else {
            // Get the version information
            $info = gd_info();

            // Extract the version number
            preg_match('/\d+\.\d+(?:\.\d+)?/', $info['GD Version'], $matches);

            // Get the major version
            $version = $matches[0];
        }

        if(!version_compare($version, '2.0.1', '>=')) {
            throw new Image_Exception('GD2 driver requires GD version :required or greater, you have :version',
                array('required'=>'2.0.1', ':version'=>$version));
        }

        return $this->driver_checked = TRUE;
    }

    /**
     * Loads an image into GD.
     * @return  void
     */
    protected function _load_image()
    {
        if(!is_resource($this->image)) {
            // Gets create function
            $create = $this->create_function;

            // Open the temporary image
            $this->image = $create($this->file);

            // Preserve transparency when saving
            imagesavealpha($this->image, TRUE);
        }
    }

    /**
     * Execute resize.
     * @param   integer  New image width
     * @param   integer  New image height
     * @return  bool
     */
    protected function _do_resize($width, $height)
    {
        $pre_width = $this->width;
        $pre_height = $this->height;

        // Loads image if not yet loaded
        $this->_load_image();

        // Test if we can do a resize without resampling to speed up the final resize
        if($width > ($this->width / 2) && $height > ($this->height / 2)) {
            $reduction_width  = round($width  * 1.1);
            $reduction_height = round($height * 1.1);

            while($pre_width / 2 > $reduction_width && $pre_height / 2 > $reduction_height) {
                // The maximum reduction is 10% greater than the final size
                $pre_width /= 2;
                $pre_height /= 2;
            }

            // Create the temporary image to copy to
            $image = $this->_create($pre_width, $pre_height);

            if(imagecopyresized($image, $this->image, 0, 0, 0, 0, $pre_width, $pre_height, $this->width, $this->height)) {
                // Swap the new image for the old one
                imagedestroy($this->image);
                $this->image = $image;
            }
        }

        // Create the temporary image to copy to
        $image = $this->_create($width, $height);

        // Execute the resize
        if(imagecopyresampled($image, $this->image, 0, 0, 0, 0, $width, $height, $pre_width, $pre_height)) {
            // Swap the new image for the old one
            imagedestroy($this->image);
            $this->image = $image;

            // Reset the width and height
            $this->width  = imagesx($image);
            $this->height = imagesy($image);
        }

        return true;
    }

    /**
     * Execute crop.
     * @param   integer  New image width
     * @param   integer  New image height
     * @param   integer  Offset from the left
     * @param   integer  Offset from the right
     */
    protected function _do_crop($width, $height, $offset_x, $offset_y) {
        // Create the temporary image to copy to
        $image = $this->_create($width, $height);

        // Loads image if not yet loaded
        $this->_load_image();

        // Execute the crop
        if(imagecopyresampled($image, $this->image, 0, 0, $offset_x, $offset_y, $width, $height, $width, $height)) {
            // Swap the new image for the old one
            imagedestroy($this->image);
            $this->image = $image;

            // Reset the width and height
            $this->width  = imagesx($image);
            $this->height = imagesy($image);
        }
    }

    /**
     * Apply filter.
     * @param   integer  Filter type
     * @param   integer  First filter param
     * @param   integer  Second filter param
     * @param   integer  Third filter param
     * @param   integer  Fourth filter param
     * @see     http://www.php.net/manual/en/function.imagefilter.php
     */
    protected function _do_filter($filter, $arg1, $arg2, $arg3, $arg4)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Check optional params one by one
        if($arg1 === NULL) {
            imagefilter($this->image, $filter);
        }
        elseif($arg2 === NULL) {
            imagefilter($this->image, $filter, $arg1);
        }
        elseif($arg3 === NULL) {
            imagefilter($this->image, $filter, $arg1, $arg2);
        }
        elseif($arg4 === NULL) {
            imagefilter($this->image, $filter, $arg1, $arg2, $arg3);
        }
        else {
            imagefilter($this->image, $filter, $arg1, $arg2, $arg3, $arg4);
        }
    }

    /**
     * Execute watermarking.
     * @param   object   Watermark Image instance
     * @param   integer  Offset from the left
     * @param   integer  Offset from the top
     * @param   integer  Opacity of watermark: 1-100
     */
    protected function _do_watermark(Image $watermark, $offset_x, $offset_y, $opacity)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Create the watermark image resource
        $overlay = imagecreatefromstring($watermark->render());

        // Get the width and height of the watermark
        $width  = imagesx($overlay);
        $height = imagesy($overlay);

        if($opacity < 100) {
            // Convert an opacity range of 0-100 to 127-0
            $opacity = round(abs(($opacity * 127 / 100) - 127));

            // Allocate transparent white
            $color = imagecolorallocatealpha($overlay, 255, 255, 255, $opacity);

            // The transparent image will overlay the watermark
            imagelayereffect($overlay, IMG_EFFECT_OVERLAY);

            // Fill the background with transparent white
            imagefilledrectangle($overlay, 0, 0, $width, $height, $color);
        }

        // Alpha blending must be enabled on the background!
        imagealphablending($this->image, TRUE);

        if( imagecopy($this->image, $overlay, $offset_x, $offset_y, 0, 0, $width, $height)) {
            // Destroy the overlay image
            imagedestroy($overlay);
        }
    }

    /**
     * Execute save.
     * @param   string   New file name
     * @param   integer  Quality (percentage value)
     */
    protected function _do_save($file, $quality)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Get the extension of the file
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        // Get the save function and IMAGETYPE
        list($save, $type) = $this->_save_function($extension, $quality);

        // Save the image to a file
        $status = isset($quality) ? $save($this->image, $file, $quality) : $save($this->image, $file);

        if($status === TRUE && $type !== $this->type) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);
        }

        return $file;
    }

    /**
     * Execute render.
     * @param   string   Image type (jpg, png, gif, etc)
     * @param   integer  Image quality
     * @return  string
     */
    protected function _do_render($type, $quality)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Get the save function and IMAGETYPE
        list($save, $type) = $this->_save_function($type, $quality);

        // Capture the output
        ob_start();

        // Render the image
        $status = isset($quality) ? $save($this->image, NULL, $quality) : $save($this->image, NULL);

        if($status === TRUE && $type !== $this->type) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);
        }

        return ob_get_clean();
    }

    /**
     * Get the GD saving function and image type for this extension.
     * @param   string   Image type: png, jpg, etc
     * @param   integer  Image quality
     * @return  array    save function, IMAGETYPE_* constant
     * @throws  Image_Exception
     */
    protected function _save_function($extension, &$quality)
    {
        switch(strtolower($extension))
        {
            case 'jpg':
            case 'jpeg':
                $save = 'imagejpeg';
                $type = IMAGETYPE_JPEG;
                break;
            case 'gif':
                $save = 'imagegif';
                $type = IMAGETYPE_GIF;

                // GIFs do not a quality setting
                $quality = NULL;
                break;
            case 'png':
                $save = 'imagepng';
                $type = IMAGETYPE_PNG;

                // Use a compression level of 9 (does not affect quality!)
                $quality = 9;
            break;
            default:
                throw new Image_Exception('Installed GD does not support :type images',
                    array(':type' => $extension));
                break;
        }

        return array($save, $type);
    }

    /**
     * Create an empty image with the given width and height.
     * @param   integer  Image width
     * @param   integer  Image height
     * @return  resource
     * @throws  Image_Exception
     */
    protected function _create($width, $height)
    {
        // Create an empty image
        if(!$image = @imagecreatetruecolor($width, $height)) {
            throw new Image_Exception('Image has not been created');
        }

        // Do not apply alpha blending
        imagealphablending($image, FALSE);

        // Save alpha levels
        imagesavealpha($image, TRUE);

        return $image;
    }
}