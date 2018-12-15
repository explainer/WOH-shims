<?php

/**
 * defines the last payment type status field
 * 
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class last_payment_type extends status_field {

  /**
   * @var string the name of the payment status field
   * 
   * this can be overriden by a filter
   */
  const status_field_name = 'pdbmps_payment_type';

  /**
   * 
   */
  public function __construct()
  {
    parent::__construct( self::status_field_name, array(
        'title' => __( 'Payment Type', 'pdb-member_payments' ),
        'form_element' => 'radio',
        'values' => $this->payment_type_options(),
            )
    );
    add_action( 'pdbmps-set_last_payment_type', array($this, 'record_payment_type'), 10, 3 );
    $options = $this->payment_type_options();
    /**
     * @filter pdbmps-payment_type_options_array
     * 
     * provides the final array of slug/titles
     * 
     * @return array
     */
    add_filter( 'pdbmps-payment_type_options_array', function () use ($options) {
      return $options;
    }, 20 );
  }

  /**
   * determines the current payment method and saves it to the given record
   * 
   * @param int $record_id
   * @param string $type_value the type value to set
   * @param array $data associative data array
   */
  public function record_payment_type( $record_id, $type_value = false, $data = array() )
  {

    if ( !$type_value ) {
      $type_value = isset( $data[self::status_field_name] ) ? $data[self::status_field_name] : false;
    }
    if ( !$type_value ) {
      $type_value = isset( $_POST[self::status_field_name] ) ? filter_input( INPUT_POST, self::status_field_name, FILTER_SANITIZE_STRING ) : false;
    }
    if ( !$type_value ) {
      $type_value = 'none';
    }
    $this->_set_last_payment_type( $record_id, $type_value );
  }

  /**
   * sets the last payment type field for the given record
   * 
   * @param int $record_id id of the current record
   * @param string $type the value to set
   */
  public function set_last_payment_type( $record_id, $type )
  {
    $this->_set_last_payment_type( $record_id, $type );
  }

  /**
   * sets the last payment type field for the given record
   * 
   * @param int $record_id id of the current record
   * @param string $type the value to set
   */
  private function _set_last_payment_type( $record_id, $type )
  {
    $set_type = 'none';
    if ( array_key_exists( $type, $this->payment_type_labels() ) ) {
      $set_type = $type;
    }
    global $wpdb;
    $wpdb->update( \Participants_Db::$participants_table, array(self::status_field_name => $set_type), array('id' => $record_id) );
//    error_log(__METHOD__.' query: '.$wpdb->last_query );
  }

  /**
   * provides the status options array
   * 
   * @return array as $title => $value
   */
  private function payment_type_options()
  {
    $return = array();
    foreach ( array_keys( $this->payment_type_labels() ) as $status ) {
      $return[$this->status_label( $status )] = $status;
    }
    return $return;
  }

  /**
   * defines the payment type labels
   * 
   * this list is filtered 
   * 
   * @return array as $status => $title
   */
  public function payment_type_labels()
  {
    return apply_filters( 'pdbmps-payment_type_options', array(
        'paypal' => apply_filters( 'pdbmps-paypal_type_label', 'PayPal' ),
        'offline' => apply_filters( 'pdbmps-offline_type_label', 'Offline' ),
//        'cash' => apply_filters( 'pdbmps-cash_type_label', 'Cash' ),
//        'check' => apply_filters( 'pdbmps-check_type_label', 'Check' ),
        'none' => __( 'None', 'pdb-member_payments' ),
            ) );
  }

  /**
   * provides the current status label for the user
   * 
   */
  public function status_label( $status )
  {
    return apply_filters( 'pdb-translate_string', $this->status_label_string( $status ) );
  }

  /**
   * provides the label for the given status
   * 
   * @param string  $status
   * @return  string  status label
   */
  public function status_label_string( $status )
  {
    $payment_type_labels = $this->payment_type_labels();
    return isset( $payment_type_labels[$status] ) ? $payment_type_labels[$status] : 'None';
  }

  /**
   * provides the status key, given the status title
   * 
   * this will also just pass through the status key if that is what comes in
   * 
   * @param string  $label the status label
   * @return string the status key for the label, or the label if there is no match
   */
  public function value_from_label( $label )
  {
    $values = array_flip( $this->payment_type_labels() );
    if ( array_key_exists( $label, $values ) ) {
      return $values[$label];
    }
    return $label;
  }

  /**
   * shows the current status value
   * 
   * @param object $field
   * 
   * @return null
   */
  public function set_field_status_value( &$field )
  {
    if ( ! $this->record_id ) {
      return;
    }
//    if ( isset( $field->value ) && ! array_key_exists( $field->value, $this->payment_type_labels() ) ) {
//      $field->value = $this->value_from_label( $field->value );
//    }
    global $wpdb;
    $user_status = $wpdb->get_var( $wpdb->prepare( 'SELECT `' . self::status_field_name . '` FROM ' . \Participants_Db::$participants_table . ' WHERE `id` = %d', $this->record_id ) );
    $field->value = $user_status ? $user_status : 'none';
  }

}
