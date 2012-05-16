<?php
require_once 'driver/gd2.php';
require_once 'class.exception.php';

/**
 * Image manipulation support.
 *
 * @author Marcin Klawitter <marcin.klawitter@gmail.com>
 */
abstract class Image
{
    /**
     * @var string Default driver
     */
    protected static $default_driver = 'GD2';

    /**
     * @var bool Driver check status
     */
    protected $driver_check = FALSE;

    /**
     * @var string Default image upload folder
     */
    protected $image_directory = 'upload';

    /**
     * @var string File path
     */
    public $file;

    /**
     * @var integer Image width
     */
    public $width;

    /**
     * @var integer Image height
     */
    public $height;

    /**
     * @var integer Image type
     */
    public $type;

    /**
     * @var string Image extension
     */
    public $extension;

    /**
     * Load driver and prepare image file.
     * @param   string  File path
     * @param   string  Driver name
     * @return  Image
     */
    public static function factory($file, $driver = NULL)
    {
        if($driver === NULL) {
            // Use default driver if not set
            $driver = self::$default_driver;
        }

        // Set driver class name
        $class = 'Image_' . $driver;

        return new $class($file);
    }

    /**
     * Load image info.
     * @throws  Image_Exception if file does not exist or file is not an image
     */
    public function __construct($file)
    {
        $this->file = $file;

        try {
            // Get the real path to the file
            $file = realpath($file);

            // Get the image information
            $info = getimagesize($file);
        }
        catch(Exception $e) {
            // Ignore all errors while reading the image
        }

        if(empty($file) || empty($info)) {
            throw new Image_Exception( ':file not found or is not an image',
                array(':file'=>basename($file)));
        }

        // Save info about image
        $this->width  = $info[0];
        $this->height = $info[1];
        $this->type   = $info[2];
        $this->mime   = image_type_to_mime_type($this->type);
    }

    /**
     * Set path to save the file.
     * @param   string  Path to save the file in
     */
    public function image_directory($path)
    {
        $this->image_dirctory = $path;
    }

    /**
     * Get file name.
     * @return  string  Image name
     */
    public function get_filename()
    {
        return (string)$this->file;
    }

    /**
     * Get image extension.
     * @return  string  Image extension
     */
    public function get_extension()
    {
        return image_type_to_extension($this->type, TRUE);
    }

    /**
     * Get image width.
     * @return  integer Image width
     */
    public function get_width()
    {
        return (int)$this->width;
    }

    /**
     * Get image height.
     * @return  integer Image height
     */
    public function get_height()
    {
        return (int)$this->height;
    }

    /**
     * Resize the image to the given size. Either the width or the height can
     * be omitted and the image will be resized proportionally.
     *
     *     // Resize to 200 pixels on the shortest side
     *     $image->resize(200, 200);
     *
     *     // Resize to 200 pixel width, keeping aspect ratio
     *     $image->resize(200, NULL);
     *
     *     // Resize to 200 pixel height, keeping aspect ratio
     *     $image->resize(NULL, 200);
     *
     * @param   integer  New width
     * @param   integer  New height
     * @return  Image
     * @uses    Image::_do_resize
     */
    public function resize($width = NULL, $height = NULL)
    {
        $width  = (int)$width;
        $height = (int)$height;

        // Throw an exception if no dimentions were set or dimentions are wrong
        if((empty($width) && empty($height)) || $width < 0 || $height < 0) {
            throw new Image_Exception('Missing image dimensions or dimensions are wrong');
        }

        if(empty($width)) {
            // Auto width
            $width = $height * $this->width / $this->height;
        }

        if(empty($height)) {
            // Auto height
            $height = $width / ($this->width / $this->height);
        }

        // Round results
        $width  = max(round($width), 1);
        $height = max(round($height), 1);

        $this->_do_resize($width, $height);

        return $this;
    }

    /**
     * Crop an image to the given size. Either the width or the height can be
     * omitted and the current width or height will be used.
     *
     * If no offset is specified, the center of the axis will be used.
     * If an offset of TRUE is specified, the bottom of the axis will be used.
     *
     *     // Crop the image to 200x200 pixels, from the center
     *     $image->crop(200, 200);
     *
     * @param   integer  New width
     * @param   integer  New height
     * @param   mixed    Offset from the left
     * @param   mixed    Offset from the top
     * @return  $this
     * @uses    Image::_do_crop
     */
    public function crop($width, $height, $offset_x = NULL, $offset_y = NULL)
    {
        if($width > $this->width) {
            // Use the current width
            $width = $this->width;
        }

        if($height > $this->height) {
            // Use the current height
            $height = $this->height;
        }

        if($offset_x === NULL) {
            // Center the X offset
            $offset_x = round(($this->width - $width) / 2);
        }
        elseif($offset_x === TRUE) {
            // Bottom the X offset
            $offset_x = $this->width - $width;
        }
        elseif($offset_x < 0) {
            // Set the X offset from the right
            $offset_x = $this->width - $width + $offset_x;
        }

        if($offset_y === NULL) {
            // Center the Y offset
            $offset_y = round(($this->height - $height) / 2);
        }
        elseif($offset_y === TRUE) {
            // Bottom the Y offset
            $offset_y = $this->height - $height;
        }
        elseif($offset_y < 0) {
            // Set the Y offset from the bottom
            $offset_y = $this->height - $height + $offset_y;
        }

        // Determine the maximum possible width and height
        $max_width  = $this->width  - $offset_x;
        $max_height = $this->height - $offset_y;

        if($width > $max_width) {
            // Use the maximum available width
            $width = $max_width;
        }

        if($height > $max_height) {
            // Use the maximum available height
            $height = $max_height;
        }

        $this->_do_crop($width, $height, $offset_x, $offset_y);

        return $this;
    }

    /**
     * Apply image filter.
     * Only first param is required. Rest of the params depend on used filter.
     *
     * Examples of use:
     * @see http://www.phpied.com/image-fun-with-php-part-2/
     *
     * @param   integer  Filter name (manual)
     * @param   integer  First param
     * @param   integer  Second param
     * @param   integer  Third param
     * @param   integer  Fourth param
     */
    public function filter($filter, $arg1 = NULL, $arg2 = NULL, $arg3 = NULL, $arg4 = NULL)
    {
        $this->_do_filter($filter, $arg1, $arg2, $arg3, $arg4);

        return $this;
    }

    /**
     * Add a watermark to an image with a specified opacity. Alpha transparency
     * will be preserved.
     *
     * If no offset is specified, the center of the axis will be used.
     * If an offset of TRUE is specified, the bottom of the axis will be used.
     *
     *     // Add a watermark to the bottom right of the image
     *     $mark = Image::factory('upload/watermark.png');
     *     $image->watermark( $mark, TRUE, TRUE );
     *
     * @param   object   Watermark Image instance
     * @param   integer  Offset from the left
     * @param   integer  Offset from the top
     * @param   integer  Opacity of watermark: 1-100
     * @return  $this
     * @uses    Image::_do_watermark
     */
    public function watermark(Image $watermark, $offset_x = NULL, $offset_y = NULL, $opacity = 100)
    {
        if($offset_x === NULL) {
            // Center the X offset
            $offset_x = round(($this->width - $watermark->width) / 2);
        }
        elseif($offset_x === TRUE) {
            // Bottom the X offset
            $offset_x = $this->width - $watermark->width;
        }
        elseif($offset_x < 0) {
            // Set the X offset from the right
            $offset_x = $this->width - $watermark->width + $offset_x;
        }

        if($offset_y === NULL) {
            // Center the Y offset
            $offset_y = round(($this->height - $watermark->height) / 2);
        }
        elseif($offset_y === TRUE) {
            // Bottom the Y offset
            $offset_y = $this->height - $watermark->height;
        }
        elseif($offset_y < 0) {
            // Set the Y offset from the bottom
            $offset_y = $this->height - $watermark->height + $offset_y;
        }

        // The opacity must be in the range of 1 to 100
        $opacity = min(max($opacity, 1), 100);

        $this->_do_watermark($watermark, $offset_x, $offset_y, $opacity);

        return $this;
    }

    /**
     * Save the image. If the filename is omitted, the original image will be overwritten.
     *
     *      // Save the image as a PNG file in "saved" folder
     *      $image->save('cool.png', 'saved');
     *
     *      // Save the file with original name in "saved" folder
     *      $image->save(NULL, 'saved');
     *
     *      // Save the file with new name in the original folder
     *       $image->save('cool.png');
     *
     *      // Overwrite the original image
     *      $image->save();
     *
     * [!!] If the file exists, but is not writable, an exception will be thrown.
     * [!!] If the file does not exist, and the directory is not writable, an exception will be thrown.
     *
     * @param   string   New file name
     * @param   string   Folder where the image should be saved in
     * @param   integer  New image quality
     * @return  string   Path to new file
     * @throws  Image_Exception
     * @uses    Image::_save
     */
    public function save($file = NULL, $directory = NULL, $quality = 100)
    {
        if($file === NULL) {
            // Overwrite the file
            $file = basename($this->file);
        }
        else {
            // Take new name ond original extension
            $file = trim($file, '.') . $this->get_extension();
        }

        if($directory === NULL) {
            // Use default folder if not set
            $directory = $this->image_directory;
        }

        // Make sure folder name ends with "/"
        $directory = trim($directory, '/') . '/';

        if(!is_dir($directory) || !is_writable($directory)) {
            throw new Image_Exception(':directory must be writable',
                array(':directory'=>$directory));
        }

        // Create full file name (directory + file name)
        $file = $directory . $file;

        // The quality must be in the range of 1 to 100
        $quality = min(max($quality, 1), 100);

        return $this->_do_save($file, $quality);
    }

    /**
     * Render the image and return the binary string.
     *
     * @param   string   Image type to return: png, jpg, gif, etc
     * @param   integer  Quality of image: 1-100
     * @return  string
     * @uses    Image::_do_render
     */
    public function render($type = NULL, $quality = 100)
    {
        if($type === NULL) {
            // Use the current image type
            $type = image_type_to_extension($this->type, FALSE);
        }

        return $this->_do_render($type, $quality);
    }

    /**
     * Render the current image.
     */
    public function __toString()
    {
        echo $this->file;
    }

    /**
     * Execute a resize.
     * @param   integer  New width
     * @param   integer  New height
     * @return  void
     */
    abstract protected function _do_resize($width, $height);

    /**
     * Execute a crop.
     * @param   integer  New width
     * @param   integer  New height
     * @param   integer  Offset from the left
     * @param   integer  Offset from the top
     * @return  void
     */
    abstract protected function _do_crop($width, $height, $offset_x, $offset_y);

    /**
     * Apply filter.
     * @param   integer  GD library constants
     * @param   integer  First filter param
     * @param   integer  Second filter param
     * @param   integer  Third filter param
     * @param   integer  Fourth filter param
     * @return  void
     */
    abstract protected function _do_filter($filter, $arg1, $arg2, $arg3, $arg4);

    /**
     * Execute a watermarking.
     * @param   object   Watermarking Image
     * @param   integer  Offset from the left
     * @param   integer  Offset from the top
     * @param   integer  Opacity of watermark
     * @return  void
     */
    abstract protected function _do_watermark(Image $image, $offset_x, $offset_y, $opacity);

    /**
     * Execute a save.
     * @param   string   New image filename
     * @param   integer  Quality
     * @return  bool
     */
    abstract protected function _do_save($file, $quality);

    /**
     * Execute a render.
     * @param   string   Image type: png, jpg, gif, etc
     * @param   integer  Quality
     * @return  string
     */
    abstract protected function _do_render($type, $quality);
}