<?php

/**
 * verifies postback from PayPal
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.3
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\paypal_ipn;

class post_check {
  
  /**
   * @var bool verified
   */
  private $verified = false;
  
  /**
   * 
   */
  public function __construct( $response )
  {
    if ( ! is_wp_error( $response ) && preg_match( '/2\d\d/', $response['response']['code'] ) === 1 && strpos( $response['body'], 'VERIFIED' ) !== false ) {
      $this->verified = true;
    }
  }
  
  /**
   * tells if the data is verified
   * 
   * @return bool true if verified
   */
  public function is_verified()
  {
    return $this->verified;
  }
}
