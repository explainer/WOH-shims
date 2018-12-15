<?php

/**
 * handles importing log data
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

class Log_Import {
  
  /**
   * @var array of pdbmps\fields\Entry objects
   */
  private $entries = array();
  
  /**
   * sets up the object
   * 
   * @param string|array  $data   the imported data array or serialized array
   */
  public function __construct( $data )
  {
    $this->setup_data($data);
  }
  
  /**
   * supplies the log id list in string form
   * 
   * @return string
   */
  public function id_list_string()
  {
    return implode( ',', $this->entry_id_list() );
  }
  
  /**
   * provides a list of entry ids
   * 
   * @return array
   */
  public function entry_id_list()
  {
    $list = array();
    foreach( $this->entries as $entry ) {
      $list[] = $entry->id;
    }
    return $list;
  }
  
  /**
   * stores the log
   * 
   * 
   */
  public function store()
  {
    foreach( $this->entries as $entry ) {
      log_table_db::update_log_entry( $entry->data, $entry->id, $entry->field, $entry->record_id, $entry->timestamp );
    }
  }
  
  /**
   * updates the stored logs with the new record ID
   * 
   * this is needed when new records are added and the ID is not known until after it is stored
   * 
   * @param string  $record_id
   */
  public function update_record_id( $record_id )
  {
    foreach( $this->entries as $entry ) {
      /* @var $entry Entry */
      $entry->write_value('record_id', $record_id );
    }
  }
  
  /**
   * sets up the data array
   * 
   * @param string|array  $data the raw imported data
   */
  private function setup_data( $data )
  {
    $all_entry_data = maybe_unserialize( str_replace( '\"', '"', $data ) ); 
    
    if ( is_array( $all_entry_data ) ) {
      foreach( $all_entry_data as $entry_id => $entry ) {
        if ( isset( $entry['data']) ) {
          $this->entries[] = new Entry( $entry_id, $entry );
        }
      }
    }
  }
}

/**
 * models a single entry from a log
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2017  xnau webdesign
 * @license    GPL3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

class Entry {
  
  /**
   * @var string the ehtry id
   */
  private $entry_id;
  
  /**
   * @var array the entry metadata
   * 
   * this will include:
   *  timestamp
   *  field name
   *  record id
   */
  private $metadata;
  
  /**
   * @var array the entry data
   */
  private $data;
  
  /**
   * constructs the object
   * 
   * @param string  $entry_id
   * @param array $entry the entry info
   */
  public function __construct( $entry_id, $entry ) {
    $this->entry_id = $entry_id;
    $this->data = $entry['data'];
    unset( $entry['data'] );
    $this->metadata = $entry;
  }
  
  /**
   * supplies an object property value
   * 
   * @param string  $name of the property to get
   * 
   * @return mixed the prop value or bool false if no prop by that name
   */
  public function __get( $name )
  {
    switch ( $name ) {
      case 'id':
        return $this->entry_id;
      case 'data':
        return $this->data;
      case 'field':
      case 'record_id':
      case 'timestamp':
        return $this->metadata[$name];
      default:
        return false;
    }
  }
  
  /**
   * writes a specific column in the entry
   * 
   * @param string  $name name of the column
   * @param string $value the value to write
   */
  public function write_value( $name, $value )
  {
    log_table_db::write_entry_value( $this->entry_id, $name,  $value );
  }
}
