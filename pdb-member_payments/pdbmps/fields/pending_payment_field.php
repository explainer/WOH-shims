<?php

/**
 * defines the field for maintaining the user's pending payment status
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2018  xnau webdesign
 * @license    GPL3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class pending_payment_field extends status_field {

  /**
   * @var string the name of the payment status field
   * 
   */
  const status_field_name = 'pdbmps_pending_payment';

  /**
   * 
   */
  public function __construct()
  {
    parent::__construct( self::status_field_name, array(
        'title' => __( 'Offline Payment', 'pdb-member_payments' ),
        'form_element' => 'date',
            )
    );

    // this is a general-purpose action to set the pending date
    add_action( 'pdbmps-set_pending_date', array($this, 'set_pending_date') );

    // called whenever an offline payment is made
    add_action( 'pdbmps-offline_payment_return', array($this, 'set_pending_date'), 5 );

    /* check an incoming logged payment
     * this is how we clear the pending state when the payment is made
     */
    add_action( 'pdbmps-update_log_' . \pdbmps\payment_log\log_field::basename, array($this, 'check_payment'), 20, 2 );
  }

  /**
   * sets the pending payment field to the submission date
   * 
   * @param array $post  the submission data
   */
  public function set_pending_date( $post )
  {
    $this->_set_pending( $post['id'] );
  }

  /**
   * sets the pending payment field to the submission date
   * 
   * @param array $post  the submission data
   */
  public function update_pending_date( $post )
  {
    if ( isset( $post[last_payment_type::status_field_name] ) && $post[last_payment_type::status_field_name] === 'offline' ) {
      $this->_set_pending( $post['id'] );
    }
  }

  /**
   * provides the pending status string if the user is pending
   * 
   * @param int $id the record id
   * 
   * @return string|bool the pending status title or bool false if not pending
   */
  public function pending_status( $id )
  {
    return $this->is_pending( $id ) ? 'pending' : false;
  }

  /**
   * tells of the user's payment is still pending
   * 
   * @param int $id the record ID
   * @return bool true if the payment is pending
   */
  public function is_pending( $id )
  {
    return $this->offline_payment_timestamp( $id ) && !$this->_is_expired( $id );
  }

  /**
   * checks an incoming payment
   * 
   * if this matches a currently pending account, clears the pending state
   * 
   * @param array $data the log data
   * @param int $id the record id 
   */
  public function check_payment( $data, $id )
  {
    /**
     * @filter pdbmps-valid_payment_made_on_pending
     * 
     * @param bool  whether the current incoming payment is valid to clear the pending state
     * @param array the payment log data
     * @param int the record id
     * @return bool if true, clear the pending status
     */
    if ( apply_filters( 'pdbmps-valid_payment_made_on_pending', true, $data, $id ) ) {
      $this->_clear_pending( $id );
    }
  }

  /**
   * supplies the pending status title
   * 
   * @return string
   */
  public function pending_title()
  {
    $setting = $this->pending_setting();
    return apply_filters( 'pdb-translate_string', $setting['title'] );
  }

  /**
   * supplies the pending status expiration period
   * 
   * @return string number of days the pending state is active
   */
  public function pending_period()
  {
    $setting = $this->pending_setting();
    return $setting['offset'];
  }

  /**
   * sets up the field's display value in a form context
   * 
   * called on the pdb-form_element_build_{$form_element} filter
   * 
   * @param \PDb_Field_Item  $field
   */
  public function status_display_value( $field )
  {
    // do nothing: use the default form element display
  }

  /**
   * checks for the expiration of the pending status
   * 
   * @param int $id the record id
   * 
   * @return bool true if the pending status is expired
   */
  private function _is_expired( $id )
  {
    if ( $timestamp = $this->offline_payment_timestamp( $id ) ) {
      return time() > strtotime( '+' . $this->pending_period() . ' days', $timestamp );
    }
    return true;
  }

  /**
   * supplies the offline payment timestamp
   * 
   * @param int $id the record id
   * 
   * @return int|bool timestamp or bool false if not set
   */
  private function offline_payment_timestamp( $id )
  {
    $record = \Participants_Db::get_participant( $id );
    return isset( $record[self::status_field_name] ) ? $record[self::status_field_name] : false;
  }

  /**
   * supplies the pending label setting
   * 
   * @return array the expiration period and label string
   */
  private function pending_setting()
  {
    return apply_filters( 'pdbmps-pending_label', array('offset' => '14', 'title' => 'Pending') );
  }

  /**
   * sets the field to the current date/time
   * 
   * @param int $id the record id
   */
  private function _set_pending( $id )
  {
    $this->_write_pending( $id, time() );
  }

  /**
   * clears the pending date
   * 
   * 
   * @param int $id the record id
   */
  private function _clear_pending( $id )
  {
    if ( filter_input( INPUT_POST, 'subsource', FILTER_SANITIZE_STRING ) === \Participants_Db::PLUGIN_NAME ) {
      $pending_payment_field = $this;
      add_action( 'pdb-after_submit_update', function ($post) use ($id, $pending_payment_field) {
        /*
         * if this is a regular record update operation, we need to do this as a 
         * delayed action so that the main submission won't overwrite our change 
         * to the pending status
         */
        $pending_payment_field->_write_pending( $id, '' );
      }, 1 );
    } else {
      $this->_write_pending($id, '');
    }
  }

  /**
   * clears the pending value
   * 
   * @param int $id the record id
   * @param string  $value the value to write
   * @return null
   */
  private function _write_pending( $id, $value )
  {
    \Participants_Db::write_participant( array(self::status_field_name => $value), $id );
  }

  /**
   * processes the displayed value for saving
   * 
   * @param string the incoming value
   * @return string the value as saved
   */
  protected function save_value( $value )
  {
    return \PDb_Date_Parse::timestamp( $value, null, get_class( $this ) . ' pending payment date' );
  }

}
