<?php

/**
 * defines the last payment date status field
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

namespace pdbmps\payment_status;
                     
class last_payment_date extends \pdbmps\fields\status_field {
  

  /**
   * @var string the name of the payment status field
   * 
   * this can be overriden by a filter
   */
  const status_field_name = 'pdbmps_payment_date';
  
  /**
   * 
   */
  public function __construct()
  {
    parent::__construct( self::status_field_name, array(
        'title' => __( 'Last Payment Date', 'pdb-member_payments' ),
        'form_element' => 'date',
            )
    );
    // no need to add this to the log, it duplicates the payment_date field 
    //add_filter( 'pdbmps-log_entry', array( $this, 'add_payment_date_value_to_input' ) );
    add_filter( 'pdbmps-payment_date_field', function() { return last_payment_date::status_field_name; } );
    add_filter( 'pdbmps-log_column_name_to_pdb_column_name', function ( $name ) {
      if ( strpos( $name, 'payment_date' ) !== false )
        $name = last_payment_date::status_field_name;
      return $name;
    } );
    add_filter('pdbmps-last_value_value', function ( $value, $name ) {
      if ( $name === last_payment_date::status_field_name ) {
        $value = strtotime( $value );
      }
      return $value;
    }, 10, 2 );
    add_filter( 'pdbmps-log_edit_row_element_attributes', array( $this, 'edit_row_element_atts'), 10, 2 );
  }
  

  /**
   * displays the field's value
   * 
   * @param mixed $value
   * @param \PDb_Field_Item  $field
   * @return string
   */
  public function display_value( $value, $field )
  {
    if ( $field->name === $this->status_field_name() ) {
      $value = \PDb_Date_Display::get_date( parent::display_value( $value, $field ) );
    }
    return $value;
  }
  

  /**
   * provides the current status value
   * 
   * this is used to show the status label in a read-only context
   * 
   * @return string
   */
  protected function get_status_value()
  {
    $user_status = new user_status( $this->record_id );
    $info = $user_status->user_status_info();
    
    return $info['last_payment_date'];
  }
  
  /**
   * adds this field's value to the log data
   * 
   * @param array $input_data the incoming data
   * @return array the data array
   */
  public function add_payment_date_value_to_input( $input_data )
  {
    if ( array_key_exists( 'payment_date', $input_data ) ) {
      $input_data[self::status_field_name] = $input_data['payment_date'];
    }
    return $input_data;
  }
  
  /**
   * @filter pdbmps-log_edit_row_element_attributes
   * @param array   $atts       the field config array
   * @param object  $field_def  the field definition object
   * @return array field config array
   */
  public function edit_row_element_atts( $atts, $field_def )
  {
    if ( $field_def->name === $this->field_name ) {
      $atts['attributes']['placeholder'] = date( \pdbmps\payment_log\PayPal_Log::date_format );
    }
    return $atts;
  }
}
