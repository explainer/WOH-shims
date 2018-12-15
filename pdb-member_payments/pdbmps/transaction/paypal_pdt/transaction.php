<?php

/**
 * handles the interaction with PayPal and provides methods for interacting with the verified data
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\transaction\paypal_pdt;

class transaction {

  /**
   * @var object manages the response data
   */
  public $response;

  /**
   * instantiates the transaction
   * 
   * @param string $txid the transaction id
   * @param string $token the merchant token
   */
  public function __construct( $txid, $token )
  {
    $this->request( $txid, $token );
  }

  /**
   * sends the id to PAyPal for verification
   * 
   * @param string $txid the transaction id
   * @param string $token the merchant token
   */
  private function request( $txid, $token )
  {
    $verify_url = apply_filters( 'pdb-member_payments_paypal_url', '' );
    assert( !empty( $token ), 'got merchant token' );
    assert( !empty( $verify_url ), 'get PayPal URL' );

    $body = array(
        'cmd' => '_notify-synch',
        'tx' => $txid,
        'at' => $token,
        'submit' => 'PDT',
    );
    $args = array(
        'body' => $body,
        'timeout' => '5',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => array(),
        'cookies' => array()
    );
    $this->response = new response( wp_remote_retrieve_body( wp_remote_post( $verify_url, $args ) ) );
  }

  /**
   * tells if the trnasaction was successful
   * 
   * @return bool true if successful
   */
  public function is_successful()
  {
    return $this->response->status === 'SUCCESS';
  }

}
