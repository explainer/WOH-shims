<?php

/**
 * models a status field
 * 
 * these are PDB fields that hold current values for the user's status
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPL3
 * @version    0.4
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class status_field {

  /**
   * @var array the status field base name
   */
  protected $field_name;

  /**
   * @var array the configuration
   *      title   string        display title for the field
   *      type    string        form element type for the field
   *      values  string|array  value configuration for dropdowns, checkboxes, etc.
   */
  private $config;

  /**
   * @var int the record ID
   */
  protected $record_id;

  /**
   * initializes the status field object
   * 
   * @param array   $config the configuration array:
   *      title   string        display title for the field
   *      type    string        form element type for the field
   *      options  string|array  value configuration for dropdowns, checkboxes, etc.
   */
  protected function __construct( $name, $config = array() )
  {
    $this->field_name = $name;
    $this->set_config( $config );

    if ( !empty( $config ) && !$this->is_defined() ) {
      $this->define_field();
    }

    // provides the form element HTML in a form context
    add_action( 'pdb-form_element_build_' . $this->config['form_element'], array($this, 'status_display_value') );

    // provides the element HTML in a non-form context
    $status_field = $this;
    add_filter( 'pdb-before_display_form_element', array($this, 'display_value'), 10, 2 );

    add_filter( 'pdb-before_submit_update', function ( $post ) use ( $status_field ) {
      if ( isset( $post[$status_field->field_name] ) ) {
        $post[$status_field->field_name] = $status_field->save_value( $post[$status_field->field_name] );
      }
      return $post;
    }, 5 );


    add_filter( 'pdb-field_readonly_override', array($this, 'readonly_status_field'), 10, 2 );

    add_action( 'pdbmps-uninstall', array($this, 'uninstall') );
    add_action( 'pdbmps-deactivate', array($this, 'deactivate') );
  }

  /**
   * provides the name of the status field
   * 
   * @return string the fieldname for this instance
   */
  public function status_field_name()
  {
    return apply_filters( 'pdbmps-status_field_name', $this->field_name );
  }

  /**
   * sets the field's value
   * 
   * @param string|int $value the new field value
   * @param int $record_id the updating record
   */
  public function update( $value, $record_id )
  {
    global $wpdb;
    $wpdb->update( \Participants_Db::$participants_table, array($this->status_field_name() => empty($value) ? null : $value ), array('id' => $record_id) );
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
    if ( $field->name() === $this->status_field_name() ) {

      $this->get_record_id_from_field( $field );

      $value = $this->get_status_value();
    }
    return $value;
  }

  /**
   * sets up the field's display value in a form context
   * 
   * called on the pdb-form_element_build_{$form_element} filter
   * 
   * @param \PDb_FormElement  $field
   */
  public function status_display_value( $field )
  {
    if ( $field->name === $this->status_field_name() ) {

      $this->get_record_id_from_field( $field );
      $this->set_field_status_value( $field );
    }
  }

  /**
   * stores the current status values to the database
   * 
   * called on the pdb-after_submit_signup and pdb=after_submit_update actions
   * 
   * @param array $record the newly-stored or -updated record
   */
  public function store_status_value( $record )
  {
    if ( isset( $record['id'] ) ) {
      $this->record_id = $record['id'];
      $this->update( $this->get_status_value(), $record['id'] );
    }
  }

  /**
   * provides the current status value
   * 
   * @return string
   */
  protected function get_status_value()
  {
    return false; // if not overridden, use the normal form element method
  }

  /**
   * sets the field object's value property to the current status value
   * 
   * @param PDb_Field_Item $field
   * 
   * @return null
   */
  protected function set_field_status_value( &$field )
  {
    $field->value = $this->get_status_value();
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
    return $label;
  }

  /**
   * tells if the user record id is known
   * 
   * @return bool true if the user reocrd ID is known
   */
  public function has_id()
  {
    return !empty( $this->record_id );
  }

  /**
   * deletes the field def to deactivate the field
   * 
   * @param string  $name of the field
   * 
   */
  public static function deactivate_field( $name )
  {
    $status_field = new self( $name );
    if ( $status_field->is_defined() ) {
      $status_field->delete_field();
    }
  }

  /**
   * deactivates all add-on generated fields
   */
  public function deactivate()
  {
    $this->delete_field();
  }

  /**
   * deletes options, etc.
   */
  public function uninstall()
  {
    $this->_delete_field_def();
  }

  /**
   * enforces readonly on status field
   * 
   * fired on the pdb-field_readonly_override filter
   * 
   * 
   * @param bool $readonly_enabled if true, the field will be rendered as readonly
   * @param object  $field the current field
   * @return bool the filtered result
   */
  public function readonly_status_field( $readonly_enabled, $field )
  {
    if ( $field->name === $this->status_field_name() ) {
      $readonly_enabled = true;
    }
    return $readonly_enabled;
  }

  /**
   * processes the displayed value for saving
   * 
   * @param string the incoming value
   * @return string the value as saved
   */
  protected function save_value( $value )
  {
    return $value;
  }

  /**
   * checks for the status field and create it if it doesn't exist
   * 
   * we check the data table instead of the field defs because if the user decides 
   * to delete the field, we want it to stay deleted.
   * 
   * @global \wpdb $wpdb
   * 
   */
  private function is_defined()
  {
    $result = wp_cache_get( $this->status_field_name(), 'status_field_is_defined' );
    if ( $result === false ) {
      global $wpdb;

      // this one checks for the data column
      //$result = $wpdb->query( 'SHOW COLUMNS FROM ' . \Participants_Db::$participants_table . ' LIKE "' . $this->status_field_name() . '"' );
      // this one checks for the field def
      $result = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Participants_Db::$fields_table . ' WHERE `name` = "' . $this->status_field_name() . '"' );
      
      //error_log(__METHOD__.' sql: '.$wpdb->last_query );

      wp_cache_set( $this->status_field_name(), $result, 'status_field_is_defined' );
    }
    return $result > 0;
  }

  /**
   * defines the status field
   * 
   */
  private function define_field()
  {
    $field_def_atts = array_merge(
                    array(
        'name' => $this->status_field_name(),
        'title' => $this->config['title'],
        'form_element' => $this->config['form_element'],
        'values' => is_array( $this->config['values'] ) ? serialize( $this->config['values'] ) : $this->config['values'],
        'group' => \pdbmps\Init::find_admin_group(),
        'order' => 200, // this doesn't matter much
        'validation' => 'no',
        'readonly' => 1,
        'CSV' => 1,
                    )
                    // this is to restore the original def if it exists
                    ,$this->_get_field_def()
            );
    
    if ( is_array($field_def_atts['values'] ) ) {
      $field_def_atts['values'] = serialize( $field_def_atts['values'] );
    }
    
    \Participants_Db::add_blank_field( $field_def_atts );
  }

  /**
   * sets up the config property
   * 
   * @param array $config the instantiating config array
   */
  private function set_config( $config )
  {
    $this->config = array_merge( array(
        'title' => $this->status_field_name(),
        'form_element' => 'text',
        'values' => '',
            ), $config );
  }

  /**
   * prevents the status field from getting saved in a regular update
   * 
   * @param array $post the incoming data
   * @return array the data to save
   */
  public function prevent_status_save( $post )
  {
    unset( $post[$this->status_field_name()] );
    return $post;
  }

  /**
   * deletes the status field
   */
  public function delete_field()
  {
    $this->_store_field_def();
    \pdbmps\Init::delete_field( $this->status_field_name() );
  }

  /**
   * provides the stored field definition
   * 
   * @return array
   */
  public static function get_field_def( $name )
  {
    $status_field = new self( $name );
    return $status_field->_get_field_def();
  }

  /**
   * stores the field definition
   * 
   */
  public static function store_field_def( $name )
  {
    $status_field = new self( $name );
    $status_field->_store_field_def();
  }

  /**
   * save the field definition
   * 
   */
  private function _store_field_def()
  {
    if( isset( \Participants_Db::$fields[$this->status_field_name()] ) ) {
      update_option( $this->status_field_name() . '_field_def', $this->field_def_props_array( \Participants_Db::$fields[$this->status_field_name()] ) );
    }
  }

  /**
   * save the field definition
   * 
   * @return array
   */
  private function _get_field_def()
  {
    $field_def = get_option( $this->status_field_name() . '_field_def', array() );
    return empty( $field_def ) ? array() : $this->field_def_props_array($field_def);
  }
  
  /**
   * converts a PDb_Form_Field_Def object to an array of its properties
   * 
   * this is not needed once we implement this in the PDb_Form_Field_Def class
   * 
   * @param object|array $field_def
   * @return array as $name => $value
   */
  private function field_def_props_array( $field_def )
  {
    if ( is_array( $field_def ) ) {
      return $field_def;
    }
    if ( is_a( $field_def, '\PDb_Form_Field_Def' ) ) {
      /* @var $field_def \PDb_Form_Field_Def */
      if ( method_exists( $field_def, 'to_array' ) ) {
        return $field_def->to_array();
      }
      return array(
        'name' => $field_def->name(),
        'title' => $field_def->title(),
        'form_element' => $field_def->form_element(),
        'values' => $field_def->options(),
        'group' => $field_def->group(),
        'validation' => $field_def->validation,
        'readonly' => $field_def->is_readonly() ? 'yes' : 'no',
        'CSV' => $field_def->CSV,
                    );
    }
    return (array) $field_def;
  }

  /**
   * save the field definition
   * 
   * @return array
   */
  private function _delete_field_def()
  {
    delete_option( $this->status_field_name() . '_field_def' );
  }

  /**
   * sets the record ID property from the field object
   * 
   * @param PDb_Field $field
   */
  protected function get_record_id_from_field( $field )
  {
    $id = isset( $field->record_id ) ? $field->record_id : false;
    $this->determine_record_id( $id );
  }

  /**
   * gets the current record ID
   * 
   * this tries several possible methods for getting the current record ID
   * 
   * @return int the record ID
   */
  protected function determine_record_id( $id = false )
  {
    $this->record_id = \pdbmps\Plugin::determine_record_id( $id );
  }

}
