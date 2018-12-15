<?php

/**
 * maintains the last value fields
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2017  xnau webdesign
 * @license    GPL3
 * @version    0.2
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class last_value_fields {
  
  /**
   * @var array of column names
   */
  private $column_list;
  
  /**
   * stores the last column field values
   * 
   * @param array $column_list
   * @param int $record_id the record
   * @param string  $entry_id the optional entry ID to store/overwrite
   * @return null
   */
  public static function store( $column_list, $record_id, $entry_id = false )
  {
    $lvf = new self( $column_list );
    $lvf->_store_last_value_fields($record_id, $entry_id);
  }
  
  /**
   * updates the last value fields in the post array
   * 
   * @param array $column_list
   * @param array $post the post array to update
   * @param array $entry_data the raw entry data
   * 
   * @return array the post array with the last value fields
   */
  public static function update( $column_list, $post, $entry_data)
  {
    $lvf = new self( $column_list );
    return $lvf->_update_last_value_fields($post, $entry_data);
  }
  
  /**
   * 
   * @param array $column_list list of column names
   */
  private function __construct( array $column_list )
  {
    $this->column_list = $column_list;
  }

  /**
   * stores the last value field values
   * 
   * @param int     $record_id  id of the current record
   * @param string  $entry_id     id of the entry to use, defaults to the last one for the record
   * @global \wpdb $wpdb
   */
  private function _store_last_value_fields( $record_id, $entry_id = false )
  {
    //global $wpdb;
    $entry_id = $entry_id ? : $this->get_last_entry_id( $record_id );
    $last_log = log_table_db::get_log( $entry_id );
    
    $lv_field_data = array();
    
    foreach ( $this->column_list as $name ) {
      $fieldname = $this->pdb_column_name( $name );
      if ( \Participants_Db::is_column( $fieldname ) ) {
        $value = array_key_exists( $name, $last_log ) ? $this->last_value_value( $last_log[$name], $fieldname ) : '';
        if ( $value !== '' ) {
          $lv_field_data[$fieldname] = $value;
        }
      }
    }
    
    assert( !empty( $lv_field_data ), 'store last value fields data');
    if ( ! empty( $lv_field_data ) ) {
      \Participants_Db::write_participant($lv_field_data, $record_id);
      //$wpdb->update( \Participants_Db::$participants_table, $lv_field_data, array('id' => $record_id) );
    }
  }

  /**
   * updates any "last value" fields with the supplied data
   * 
   * if a field in Participants Database has the same name as one of the data columns 
   * for the log, we write the latest value into it
   * 
   * @param array $post the posted data
   * @param array $entry_data the log entry data
   * 
   * @return array $post with the latest fields updated
   */
  private function _update_last_value_fields( $post, $entry_data )
  {
    foreach ( array_keys( $this->column_list ) as $name ) {
      if ( \Participants_Db::is_column( $this->pdb_column_name( $name ) ) && array_key_exists( $name, $entry_data ) ) {
        $value = $this->last_value_value( $entry_data[$name], $this->pdb_column_name( $name ) );
        if ( $value !== false ) {
          $post[$this->pdb_column_name( $name )] = $value;
        }
      }
    }
    return $post;
  }

  /**
   * process a log value for saving in a last value field
   * 
   * @param string|int  $value the raw, incoming value
   * @param string      $fieldname the current field name
   * @return string|int|bool  the processed value or bool false if the field is 
   *                          not valid for this purpose
   */
  private function last_value_value( $value, $fieldname )
  {
    /**
     * @filter pdbmps-last_value_value
     * @param string  $value  the transferred value
     * @param object $field the current field
     * @return string|bool the value to use, bool false to skip and have the value added to the post
     */
    if ( has_filter( 'pdbmps-last_value_value' ) ) {
      return apply_filters( 'pdbmps-last_value_value', $value, $fieldname );
    }
    return $value;
  }

  /**
   * supplies the log ID of the last log for the current field
   * 
   * @param int $record_id
   * @global \wpdb $wpdb
   * @return string|bool the found entry ID or bool false if there are multiple matches
   */
  public function get_last_entry_id( $record_id )
  {
    global $wpdb;
    $result = $wpdb->get_results( $wpdb->prepare( 'SELECT `entry_id` FROM ' . $wpdb->prefix . log_table_db::table_name . ' WHERE `record_id` = "%s"', $record_id ) );
    if ( ! empty( $result ) ) {
      return end( $result )->entry_id;
    }
    return false;
  }

  /**
   * provides the PDB column name that corresponds to the log column
   * 
   * @param string $name
   * @param string the PDB column name
   */
  private function pdb_column_name( $name )
  {
    return apply_filters( 'pdbmps-log_column_name_to_pdb_column_name', $name );
  }

  /**
   * tells if a field type is appropriate for a last value field
   * 
   * @param string  $form_element
   * 
   * @return  bool true if the field type is OK to use
   */
  public static function field_type_ok_for_last_value( $form_element )
  {
    switch ( $form_element ) {
      // all of these are not suitable for a simple text value
      case 'multi-select-other' :
      case 'link' :
      case 'image-upload' :
      case 'file-upload' :
      case 'hidden' :
      case 'password' :
      case 'captcha' :
      case 'placeholder' :
      case 'multi-checkbox' :
      case 'multi-dropdown' :
      case 'rich-text' :
      case 'checkbox' :
      case 'text-area' :
        $ok = false;
        break;
      default:
        $ok = true;
    }
    return $ok;
  }
}
