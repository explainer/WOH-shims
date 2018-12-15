<?php

/**
 * provides a standard set of event triggers for use with a notifications plugin
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps;

class Events {

  /**
   * @var string holds the current action mode
   */
  private $action;

  /**
   * @var int  holds the current record id
   */
  private $id;

  /**
   * @var array record data
   */
  private $record;

  /**
   * 
   */
  private function __construct( $record )
  {
    $this->id = array_key_exists( 'id', $_POST ) ? filter_input( INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT ) : false;
    $this->record = $record;
    $this->set_props();

    switch ( $this->action ) {
      case 'pdbmps-submit':
        // member payment submission
        do_action( 'pdbmps-payment_submission', $this->record );
        break;
      case 'signup-member-payment':
        do_action( $this->action, $this->record );
        break;
      case 'pdbmps-paypal-pdt':
        // handle a PDT response
        do_action( $this->action, $this->record );
        break;
      default:
        do_action( $this->action, $this->record );
    }
  }

  /**
   * checks the submission, then triggers appropriate actions
   * 
   * @param array $participant_values the current record values
   */
  public static function trigger_submission_actions( $participant_values )
  {
    $action = new self( array_merge( $participant_values, self::post_actions() ) );
  }

  /**
   * handles a PDT response
   * 
   * @param array response data
   */
  public static function handle_pp_response( $response )
  {
    if ( isset( $response['custom'] ) && $record = \Participants_Db::get_participant( Plugin::get_record_id_from_return_code( $response['custom'] ) ) ) {
      $event = new self( array_merge( $response, $record, array('action' => 'pdbmps-paypal-pdt') ) );
    }
  }

  /**
   * sets the class properties from available data
   * 
   */
  private function set_props()
  {
    foreach ( array('action', 'id') as $prop ) {
      if ( array_key_exists( $prop, $this->record ) ) {
        $this->{$prop} = $this->record[$prop];
      } else {
        $this->{$prop} = array_key_exists( $prop, $_POST ) ? filter_input( INPUT_POST, $prop, FILTER_SANITIZE_STRING ) : false;
      }
    }
  }

  /**
   * grabs action label from the POST
   * 
   * @return array
   */
  public static function post_actions()
  {
    $post = array();
    $post['action'] = isset( $_POST['submit_action'] ) ? filter_input( INPUT_POST, 'submit_action', FILTER_SANITIZE_STRING ) : filter_input( INPUT_POST, 'action', FILTER_SANITIZE_STRING );
    return $post;
  }

}
