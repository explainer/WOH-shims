<?php

/**
 * defines the payment status field
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.3
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\payment_status;

class payment_status_field extends \pdbmps\fields\status_field {

  /**
   * @var string the name of the payment status field
   * 
   * this can be overriden by a filter
   */
  const status_field_name = 'pdbmps_member_payment_status';

  /**
   * 
   */
  public function __construct()
  {
    parent::__construct( self::status_field_name, array(
        'title' => __( 'Member Payment Status', 'pdb-member_payments' ),
        'form_element' => 'dropdown',
        'values' => $this->status_options(),
            )
    );
    add_filter( 'pdbmps-log_column_name_to_pdb_column_name', function ( $name ) {
      // check for the member_payment_status field, not the status_field
      if ( strpos( $name, 'member_payment_status' ) !== false )
        $name = payment_status_field::status_field_name;
      return $name;
    } );
    
    if ( is_admin() ) 
      $this->check_db_update();
  }

  /**
   * displays the field's value in a non-form context
   * 
   * 
   * @param mixed $value
   * @param \PDb_Field_Item  $field
   * @return string
   */
  public function display_value( $value, $field )
  {
    if ( $field->name === $this->status_field_name() ) {
      
      $this->get_record_id_from_field($field);
      
      $value = $this->status_label( $this->get_status_value() );
      
    }
    return $value;
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
   * supplies the status modes
   * 
   * @return array of status desgination slugs
   */
  public static function status_list()
  {
    return apply_filters( 'pdbmps-payment_status_list', array('paid', 'past_due', 'due', 'payable', 'pending') );
  }
  

  /**
   * provides the status options array
   * 
   * @return array as $value => $title
   */
  private function status_options()
  {
    $return = array();
    foreach ( self::status_list() as $status ) {
      $return[$this->status_label( $status )] = $status;
    }
    return $return;
  }

  /**
   * provides the label for the given status
   * 
   * @param string  $status
   * @return  string  status label
   */
  public function status_label_string( $status )
  {
    $terms = apply_filters( 'pdbmps-' . $status . '_label', $status );
    return is_array( $terms ) ? $terms['title'] : $terms;
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
    $statuses = $this->status_options();
    if ( array_key_exists( $label, $statuses ) ) {
      return $statuses[$label];
    }
    return $label;
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

    // print some extra debuggin info
    if ( false ) {
      $dateformat = get_option( 'date_format' );
      printf(
              '<p style="margin-bottom:0.5em" >Next Due Date: %s<br>Payable Date: %s<br>Past Due Date: %s<br>Current Date: %s</p>', date( $dateformat, $info['next_due_date'] ), date( $dateformat, $info['payable_date'] ), date( $dateformat, $info['past_due_date'] ), date( $dateformat, $info['current_date'] )
      );
    }
    
    return $user_status->status();
  }

  /**
   * shows the current status value
   * 
   * this is used to show the status label in a read-only context
   * 
   * @param \PDb_Field_Item|\PDb_FormElement $field
   * 
   * @return null
   */
  public function set_field_status_value( &$field )
  {
    if ( $this->has_id() ) {
      $status = $this->get_status_value();
      // replace the defined options with the add-on label settings
      $field->options = array_flip( array_filter( self::status_selector_items() ) );
      $field->value = $status;
    }
  }

  /**
   * processes the displayed value for saving
   * 
   * this checks the incoming value to see if it is a status label, then returns 
   * the status value
   * 
   * @param string the incoming value
   * @return string the value as saved
   */
  protected function save_value( $value )
  {
    $status_items = array_merge( array_flip( self::status_selector_items() ), $this->field_labels() );
    if ( isset( $status_items[$value] ) ) {
      $value = $status_items[$value];
    }
    return $value;
  }

  /**
   * status selector items
   * 
   * @return array as value => title
   */
  public static function status_selector_items()
  {
    $selections = array();
    $items = array_merge( self::status_list(), array('') );
    foreach ( $items as $item ) {
      $selection_value = apply_filters( 'pdbmps-' . $item . '_label', $item );
      $selections[$item] = is_array( $selection_value ) ? $selection_value['title'] : $selection_value;
    }
    return $selections;
  }
  
  /**
   * provides the defined status field labels
   * 
   * @return array as label => value
   */
  private function field_labels()
  {
    $field_list = \Participants_Db::$fields;
    $field = $field_list[$this->field_name];
    /* @var $field \PDb_Form_Field_Def */
    return $field->options();
  }
  
  
  
  /**
   * checks for the need to update the db
   * 
   */
  private function check_db_update()
  {
    $last_db_version = get_site_option(  \pdbmps\Plugin::db_version_option );
    if ( !$last_db_version ) {
      $last_db_version = '1.0';
    }
    if ( version_compare( $last_db_version, '1.1', '<' ) ) {
      $this->update_db();
    }
  }
  
  /**
   * cleans up the database if status titles are in there
   * 
   * @global \wpdb $wpdb
   * @return null
   */
  public function update_db()
  {
    $map = array_filter( array_merge( array_flip( self::status_selector_items() ), $this->field_labels() ) );
    
    global $wpdb;
    
    $sql = 'SELECT p.id, p.' . $this->field_name . ' FROM ' . \Participants_Db::$participants_table . ' p WHERE p.' . $this->field_name . ' IN ("' . implode('","', array_keys($map) ) . '")';
    $hit_list = $wpdb->get_results($sql);
    
    foreach( $hit_list as $record ) {
      $result = $wpdb->query( 'UPDATE ' . \Participants_Db::$participants_table . ' p SET p.' . $this->field_name . ' = "' . $map[$record->{$this->field_name}] . '" WHERE p.id = ' . $record->id );
      if ( $result === false ) {
        error_log(__METHOD__.' failed: '.$wpdb->last_error);
        return;
      }
    }
    update_site_option( \pdbmps\Plugin::db_version_option, '1.1' );
  }

}