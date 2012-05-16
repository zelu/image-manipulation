<?php
/**
 * Exception class.
 *
 * @author Marcin Klawitter <marcin.klawitter@gmail.com>
 */
class Image_Exception extends Exception
{
    public function __construct($message, array $variables = array(), $code = 0)
    {
        // Bind message
        $message = strtr($message, $variables);

        // call parent class
        parent::__construct($message, $code);
    }
}
