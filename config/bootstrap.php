<?php
/**
 * Enhanced Curl Socket for Lithium
 *
 * @copyright     Copyright 2013, PixelCog Inc. (http://pixelcog.com)
 *                Original Copyright 2013, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

/**
 * The following lines help Li3 core find the classes within this library.
 *
 * Make sure to add the following line to your app's bootstrap.php so that it can find all
 * of this:
 * Libraries::add('li3_curl');
 *
 */
use lithium\core\Libraries;

Libraries::paths( array(
	'socket' => array_merge_recursive( (array) Libraries::paths('socket'), array(
		'{:library}\{:name}' => array('libraries' => 'li3_curl')
	))
));

?>