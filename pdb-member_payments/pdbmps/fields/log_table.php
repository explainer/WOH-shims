<?php

/**
 * defines a log table form_element type
 * 
 * this creates a simple table element with named columns
 * the columns are defined in the "values" area of the field definition.
 * For writing, the element only allows data in one new row to be saved, old rows 
 * can't be edited: that makes it a "log" type field as opposed to a "spreadsheet" 
 * type field where all cells are editable
 * 
 * the data for this field is saved in a separate table in the database because 
 * the main Participants Database table can't handle multidimensional arrays
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    1.3
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class log_table extends pdb_custom_field {

  /**
   * @var array of column names in $name => $title format
   */
  private $columns;

  /**
   * @var array of stored values; this is a list of entry ids
   */
  private $values;

  /**
   * @var log_table_db interface to the field's storage table
   */
  private $Db;
  
  /**
   * @var log_table_field the log table field object
   */
  private $log_field;

  /**
   * intantiates the field
   *
   * @param string $title title of the field element
   */
  public function __construct( $title = '' )
  {
    parent::__construct( 'log-table', empty( $title ) ? 'Log Table' : $title  );

    $this->Db = log_table_db::get_instance();

    add_filter( 'pdb-before_submit_signup', array($this, 'update_log') );
    add_filter( 'pdb-before_submit_update', array($this, 'update_log') );
    add_filter( 'pdb-before_submit_add', array($this, 'update_log') );

    add_filter( 'pdb-log_table_get_single_entry_id', array($this, 'get_single_entry_id') );

    add_filter( 'pdb-field_readonly_override', array($this, 'readonly_last_value_fields'), 10, 2 );

    add_action( 'pdb-list_admin_with_selected_delete', array($this, 'clear_all_record_logs') );
    
    add_filter( 'pdb-csv_export_value_raw', array( $this, 'export_all_logs' ), 10, 2 );
    
    add_filter( 'pdb-before_csv_store_record', array( $this, 'store_imported_logs' ) );

    // register the form element as one that uses a set of values
    $field = $this;
    add_filter( 'pdb-value_set_form_elements_list', function ( $list ) use ( $field ) {
      $list[] = $field->name;
      return $list;
    } );
    
    // register the global events
    add_filter( 'pdbmps-global_plugin_events', array( $this, 'register_events' ), 25 );
  }

  /**
   * display the field value in a read context
   * 
   * @return string
   */
  public function display_value()
  {
    $table = sprintf( $this->table_wrap(), $this->field->name(), $this->name, $this->table_header(), $this->table_body() );
    
    return sprintf( '<div class="table-form-element">%s</div>', $table );
  }
  
  /**
   * prepares the log data for export
   * 
   * @param string  $value the stored value of the field
   * @param object  $column the current column
   * 
   * @return string the value to export
   */
  public function export_all_logs( $value, $column )
  {
    /**
     * @filter pdbmps-export_logs
     * @param bool true to include the log data in the CSV export
     * @param object $column the currently exporting field
     */
    if ( $column->form_element === $this->name && apply_filters( 'pdbmps-export_logs', true, $column ) ) {
      $entry_id_list = maybe_unserialize( $value );
      if ( is_array( $entry_id_list ) ) {
        $all_logs = array();
        foreach( $entry_id_list as $entry_id ) {
          $all_logs[$entry_id] = $this->get_log_entry_export( $entry_id );
        }
        $value = serialize( $all_logs );
        /*
         * this is to tell \Participants_Db::_prepare_csv_row the value is ready 
         * to go, no need to process
         */
        $column->form_element = 'skip';
      }
    }
    return $value;
  }
  
  /**
   * stores any log entries found in a CSV import
   * 
   * called on pdb-before_csv_store_record filter
   * 
   * @param array $post the record data before storing
   * 
   * @return array the record data
   */
  public function store_imported_logs( $post )
  {
    $columns = \Participants_Db::$fields;
    
    foreach( $post as $import_field_name => $import_field_data ) {
      if ( isset( $columns[$import_field_name] ) && $columns[$import_field_name]->form_element() === $this->name ) {
        $log_import = new Log_Import( $import_field_data );
        $log_import->store();
        // update the record id if it wasn't known when the log was stored
        add_action( 'pdb-after_import_record', function ( $post, $id, $status ) use ( $log_import ) {
          // we need to do this on an insert because we won't know the ID until after writing
          if ( $status === 'insert' ) {
            $log_import->update_record_id( $id );
          }
        }, 10, 3 );
        $post[$import_field_name] = $log_import->entry_id_list();
      }
    }
    
    return $post;
  }

  /**
   * provides the data for a single entry
   * 
   * @global wpdb $wpdb
   * @param string $entry_id the entry identifier
   * @return array associative array of entry values
   */
  public function get_log_entry( $entry_id )
  {
    // return an empty array if there is no log entry id
    if ( empty( $entry_id ) )
      return array();
    global $wpdb;
    $result = $wpdb->get_results( $wpdb->prepare( 'SELECT `entry_column`,`entry_value` FROM ' . $wpdb->prefix . log_table_db::table_name . ' WHERE `entry_id` = "%s"', $entry_id ) );
    $entry_data = array();
    foreach ( $result as $row ) {
      $entry_data[$row->entry_column] = $row->entry_value;
    }
    return $entry_data;
  }

  /**
   * provides the export data for a single entry
   * 
   * this includes all log columns
   * 
   * @global wpdb $wpdb
   * @param string $entry_id the entry identifier
   * @return array associative array of entry values
   */
  public function get_log_entry_export( $entry_id )
  {
    global $wpdb;
    $result = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . log_table_db::table_name . ' WHERE `entry_id` = "%s"', $entry_id ) );
    
    $entry_export = array();
    foreach ( $result as $row ) {
      foreach( $row as $column => $value ) {
        switch( $column ) {
          case 'entry_id':
          case 'entry_value':
          case 'id':
            break;
          case 'timestamp':
          case 'record_id':
          case 'field':
            if ( ! isset( $entry_export[$row->entry_id][$column] ) ) {
              $entry_export[$column] = $value;
            }
            break;
          case 'entry_column':
            $entry_export['data'][$value] = $row->entry_value;
            break;
        }
      }
    }
//    error_log(__METHOD__.' export: '.print_r($entry_export,1));
    return $entry_export;
  }

  /**
   * supplies the entry id given a column value
   * 
   * @param string  $value  value of the column
   * 
   * @return string|bool the found entry ID or bool false if there are multiple matches
   */
  public function get_single_entry_id( $value )
  {
    global $wpdb;
    $result = $wpdb->get_results( $wpdb->prepare( 'SELECT `entry_id` FROM ' . $wpdb->prefix . log_table_db::table_name . ' WHERE `entry_value` = "%s"', $value ) );
    if ( count( $result ) === 1 ) {
      return current( $result )->entry_id;
    }
    return false;
  }

  /**
   * updates all log fields in the current posted data
   * 
   * @param array  the incoming unsanitized post data
   * 
   * @return array the modified data array
   */
  public function update_log( $post )
  {
    $record_id = isset( $post['id'] ) ? filter_var( $post['id'], FILTER_SANITIZE_NUMBER_INT ) : false;

    if ( $record_id ) {
      /*
       * loop through all the log table fields in the whole plugin and update each one
       */
      foreach ( $this->table_fields() as $fieldname ) {
        $post = $this->update_field_log( $fieldname, $post, $record_id );
      }
    }

    return $post;
  }

  /**
   * updates a log
   * 
   * @param string  $fieldname  name of the log field to update
   * @param array   $post       the incoming data
   * @param int     $record_id  id of the current record
   * 
   * @return array the posted data with the log and last value fields updated
   */
  public function update_field_log( $fieldname, $post, $record_id )
  {
    $log_field = new log_table_field( $fieldname, $record_id );
    
    $post = $log_field->update_field_log( $post );
    
    if ( $log_field->has_log_data( $post ) ) {
      /*
       * set up the manual payment log entry trigger
       * 
       */
      if ( $this->is_manual_entry( $post ) && $fieldname === \pdbmps\payment_log\log_field::basename && $log_field->new_log_id() ) {
        do_action( 'pdbmps-manual_payment_entry', apply_filters( 'pdbmps-template_data', array_merge( $post, log_table_db::get_log( $log_field->new_log_id() ) ) ) );
      }
    }
    
    return $post;
  }

  /**
   * enforces readonly on last value fields
   * 
   * fired on the pdb-field_readonly_override filter
   * 
   * @param bool $readonly_enabled if true, the field will be rendered as readonly
   * @param object  $field the current field
   * @return bool the filtered result
   */
  public function readonly_last_value_fields( $readonly_enabled, $field )
  {
    if ( $this->is_last_value_field( $field->name ) && apply_filters( 'pdbmps-readonly_last_value_fields', true ) ) {
      $readonly_enabled = true;
    }
    return $readonly_enabled;
  }

  /**
   * tells if the field is a last value field
   * 
   * @param string  $fieldname
   * @return  bool  true if the field is a last value field
   */
  public function is_last_value_field( $fieldname )
  {
    $last_value_fields = wp_cache_get( 'pdbmps-last_value_fields' );
    if ( $last_value_fields === false ) {
      $last_value_fields = $this->get_last_value_fields();
      wp_cache_set( 'pdbmps-last_value_fields', $last_value_fields );
    }
    return in_array( $fieldname, $last_value_fields );
  }
  
  /**
   * registers the log entry events
   * 
   * @param array $events
   * @return array as $tag => $title
   */
  public function register_events( $events )
  { 
    $events['pdbmps-manual_payment_entry'] = __( 'Member Payments: Offline Payment Entry Receipt', 'pdb_member_payments' );
    
    return $events;
  }
  
  /**
   * determines if the current log fiel update is a manual entry
   * 
   * @param array $post the incoming data
   * @return bool true if the current update is a manual entry
   */
  protected function is_manual_entry( $post )
  {
    /*
     * we just check if we are in the admin because only manual entries will come 
     * through the admin
     */
    return is_admin();
  }

  /**
   * sets up the last values fields array
   */
  protected function get_last_value_fields()
  {
    $last_value_fields = array();
    $record_id = $this->get_record_id();
    foreach ( $this->table_fields() as $fieldname ) {
      $field = new log_table_field( $fieldname, $record_id );
      if ( !is_array( $field->options ) )
        continue;
      foreach ( array_keys( $field->options ) as $option ) {
        $last_value_fields[] = $option;
      }
    }
    return $last_value_fields;
  }

  /**
   * handles an ajax call to delete an entry
   * 
   * @return  string  reuslt
   */
  public function delete_log_entry()
  {
    $post = filter_input_array( INPUT_POST, FILTER_SANITIZE_STRING );
    wp_send_json( $this->delete_entry( $post['entry_id'], $post['record_id'], $post['fieldname'] ) );
    exit;
  }

  /**
   * deletes a log entry
   * 
   * @param string  $entry_id
   * @param int     $record_id
   * @param object  $fieldname
   * 
   * @return bool true if successful
   */
  public function delete_entry( $entry_id, $record_id, $fieldname )
  {
    $this->setup_log_table_field( $fieldname, $record_id );
    $result = $this->log_field->remove_entry($entry_id);
    do_action( 'pdbmps-update_status_fields', $record_id, 'due' );
    return $result;
  }

  /**
   * handles clearing all logs for deleted records
   * 
   * @param array $records array of record ids
   */
  public function clear_all_record_logs( $records )
  {
    foreach ( (array) $records as $record_id ) {
      $this->clear_record_entries( $record_id );
    }
  }

  /**
   * handles the deletion of all log entries for a record that is getting deleted
   * 
   * @param int $record_id
   * 
   * @return bool true if successful
   */
  private function clear_record_entries( $record_id )
  {
    return $this->Db->delete_all_record_entries( $record_id );
  }

  /**
   * provides the list of IDs from the log_table database table
   * 
   * this is to rebuild the PDB record value based on what's actually in the log_table DB
   * 
   * @param int $record_id the pdb record id
   * @return array of log entry ids
   */
  public function entry_id_list( $record_id )
  {
    $this->setup_log_table_field();
    return $this->log_field->entry_id_list();
  }

  /**
   * provides the form element HTML
   * 
   * @return null
   */
  protected function form_element_html()
  {
    $html = '';
    $this->field->output = $this->editable_table();
  }

  /**
   * supplies the editable table element
   * 
   * @return string HTML
   */
  private function editable_table()
  {
    if ( ! $this->record_has_id() ) {
      return sprintf( '<p>%s</p>', __( 'you must save the record before adding a log entry', 'pdb-member_payments' ) );
    }
    return sprintf( $this->table_wrap(), $this->field->name, $this->name, $this->table_header(), $this->table_body() . $this->editable_row()
    );
  }
  
  /**
   * provides the outer wrapper for the log table HTML
   * 
   * @return string sprintf template
   */
  private function table_wrap()
  {
    return apply_filters( 'pdb-member_payments_table_html_wrap', '<table class="%1$s-%2$s" data-fieldname="%1$s"><thead>%3$s</thead><tbody>%4$s</tbody></table>' );
  }

  /**
   * provides the table header row
   * 
   * @return string HTML
   */
  private function table_header()
  {
    $header = '';
    $row_template = apply_filters( 'pdb-member_payments_table_header_row', '<tr><th class="entry-control"></th>%s</tr>' );
    $cell_template = apply_filters( 'pdb-member_payments_table_header_cell', '<th class="%1$s-column">%2$s</th>' );
    
    foreach ( $this->field_columns() as $name => $title ) {
      $header .= sprintf( $cell_template, $name, $title );
    }
    
    return sprintf( $row_template, $header );
  }

  /**
   * provides the table body HTML
   * 
   * @return  string  HTML
   */
  private function table_body()
  {
    if ( $this->value_row_count() === 0 ) {
      // dont show if there are no rows in the data
      return '';
    }
    $rows = array();
    $row_template = apply_filters(
            'pdb-member_payments_table_body_row', '<tr data-index="%1$s" data-entryid="%3$s"><td class="entry-control">%4$s</td>%2$s</tr>'
    );
    $cell_template = apply_filters(
            'pdb-member_payments_table_body_cell', '<td class="%1$s-column">%2$s</td>'
    );
    $icon_set = apply_filters(
                    'pdb_member_payments_allow_entry_edit', false ) ?
            '<span title="delete" class="dashicons dashicons-no"></span><span  title="edit" class="dashicons dashicons-edit"></span>' :
            '<span title="delete" class="dashicons dashicons-no"></span>';
    $edit_icons = apply_filters( 'pdb_member_payments_log_edit_lock', false ) ? '' : $icon_set;
    
    // each value here will be a log entry id
    foreach ( $this->log_field->entry_id_list() as $i => $entry_id ) {
      $cells = '';
      $entry_columns = $this->Db->get_entry_display( $entry_id );
      foreach ( array_keys( $this->field_columns() ) as $name ) {
        $cells .= sprintf( $cell_template, $name, isset( $entry_columns[$name] ) ? $entry_columns[$name] : ''  );
      }
      $rows[] = sprintf( $row_template, $i, $cells, $entry_id, $edit_icons, $this->field->name );
    }
    return implode( "\n", $rows );
  }

  /**
   * tells whether to add the editable row or not
   * 
   * checks for a record id in the admin edit acreen URL, don't use edit row if 
   * there is no ID
   * 
   * @return bool true if the editable row should be added
   */
  private function show_editable_row()
  {
    $permission = \Participants_Db::current_user_has_plugin_role( 'editor', __METHOD__ );

    return ( apply_filters( 'pdb_member_payments_log_edit_lock', true ) === false ) && $permission;
  }

  /**
   * tells if the record has been saved and has an id
   * 
   * @return bool true if the record has an id
   */
  private function record_has_id()
  {
    return (bool) $this->get_record_id();
  }

  /**
   * supplies the editable row
   * 
   * @return string HTML
   */
  private function editable_row()
  {
    if ( !$this->show_editable_row() ) {
      return '';
    }
    $row = '';
    $index = $this->value_row_count() + 1;
    $values = $this->post_data_array();
    $new_log_label = __( 'New Entry', 'pdb-member_payments' );
    $row_template = apply_filters( 'pdb-member_payments_table_body_row', '<tr data-index="%1$s" class="log_table_editable_row"><td class="new-entry-label">%3$s</td>%2$s</tr>' );
    $cell_template = apply_filters( 'pdb-member_payments_table_body_cell', '<td class="%1$s-column">%2$s</td>' );
    foreach ( $this->field_columns() as $name => $title ) {
      $input = $this->log_field_input( $name, $title, ( isset( $values[$name] ) ? $values[$name] : '' ) );
      $row .= sprintf( $cell_template, $name, $input );
    }
    return sprintf( $row_template, $index, $row, $new_log_label );
  }

  /**
   * supplies the input element
   * 
   * @param string $name name of the log column
   * @param string $title of the log column
   * @param string $value current value of the field
   * 
   * @return string HTML
   */
  private function log_field_input( $name, $title, $value )
  {
    $field_defs = \Participants_Db::$fields;
    $default = array('type' => 'text-line');
    $field_atts = array(
        'attributes' => array('title' => $title),
        'name' => $this->field->name . '[' . $name . ']',
        'value' => $value,
    );
    
    //set up the default field object
    $last_value_field_atts = new \PDb_Form_Field_Def( (object) array(
                'name' => $name,
                'form_element' => 'text-line',
                'values' => '',
    ) );
    $base_element_atts = array();
    if ( array_key_exists( $this->pdb_column_name( $name ), $field_defs ) ) {
      $last_value_field_atts = $field_defs[$this->pdb_column_name( $name )];
      /* @var $last_value_field_atts \PDb_Form_Field_Def */
    }
    if ( last_value_fields::field_type_ok_for_last_value( $last_value_field_atts->form_element() ) ) {
      $base_element_atts = $this->get_element_atts( $last_value_field_atts );
    }
    /**
     * @filter pdbmps-log_edit_row_element_attributes
     * @param array  the field config array
     * @param object  the field definition object
     * @return array field config array
     */
    $element_atts = apply_filters( 'pdbmps-log_edit_row_element_attributes', array_merge( $default, $base_element_atts, $field_atts ), $last_value_field_atts );
    return \PDb_FormElement::get_element( $element_atts );
  }

  /**
   * converts a field attribute object to a
   * 
   * @param \PDb_Form_Field_Def $field_def the field definition as gotten from Participants_Db::$fields
   * 
   * @return array suitable for use in PDb_FormElement::get_element
   */
  private function get_element_atts( $field_def )
  {
    return array(
        'type' => $field_def->form_element(),
        'options' => $field_def->options(),
        'attributes' => '',
    );
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
   * sets up the field object and columns in preparation for showing the form element
   * 
   * upstream, this is called by pdb-form_element_build_{$form_element}
   * 
   * @param PDb_FormElement $field the incoming object
   */
  protected function setup_field( $field )
  {
    $this->field = $field;
    $this->setup_log_table_field();
    $this->setup_value();
  }

  /**
   * sets the field value property
   * 
   * this gets the values from the database if there is a new value in the $_POST 
   * array because the Shortcode class overrides the stored value with the POST 
   * value if it exists. We need to add the stored value to the incoming value in 
   * the POST to get the complete value for the field.
   * 
   */
  private function setup_value()
  {
    if ( isset($_POST) && array_key_exists( $this->field->name, $_POST ) ) {
      /*
       * if the last item is the same as what is in the POST array, delete it from the post array
       */
      if ( $this->Db->get_log_entry( $this->log_field->last_entry_id() ) === $this->post_data_array() ) {
        unset( $_POST[$this->field->name] );
      }
    }
  }

  /**
   * provides the form element's mysql datatype
   * 
   * @return string
   */
  protected function element_datatype()
  {
    return 'TEXT'; // ought to be big enough
  }

  /**
   * supplies the post values for a row
   * 
   * @return array the data array
   */
  private function post_data_array()
  {
    if ( array_key_exists( $this->field->name, $_POST ) ) {
      return filter_var_array( $_POST[$this->field->name], FILTER_SANITIZE_STRING );
    }
    return array();
  }

  /**
   * supplies a list of field names that are using this form element
   * 
   * @return array
   */
  private function table_fields()
  {
    $table_fields = wp_cache_get( 'table_fields_list', self::label );
    if ( $table_fields === false ) {
      $table_fields = array();
      foreach ( \Participants_Db::$fields as $field ) {
        /* @var $field \PDb_Form_Field_Def */
        if ( $field->form_element() === $this->name ) {
          $table_fields[] = $field->name();
        }
      }
      wp_cache_set( 'table_fields_list', self::label );
    }
    return $table_fields;
  }

  /**
   * provides a count of the number of rows in the data
   * 
   * ignores an empty row at the end of the array
   * 
   * @return int
   */
  private function value_row_count()
  {
    $columns = $this->field_columns();
    $count = count( $columns );
    $endvalue = end( $columns );
    if ( $endvalue !== false && empty( $endvalue ) ) {
      return $count - 1;
    }
    return $count;
  }
  
  /**
   * supplies the current field columns
   * 
   * @return array
   */
  private function field_columns()
  {
    $this->setup_log_table_field();
    return $this->log_field->columns();
  }

  /**
   * tells if an array has non-empty values
   * 
   * @param array $array the array to test
   * @return bool true if the array has values
   */
  public static function array_has_values( $array )
  {
    return strlen( implode('', (array) $array ) ) > 0;
  }
  
  /**
   * supplies the record ID
   * 
   * @param int $record_id
   * @return int|bool false if not available
   */
  private function get_record_id( $record_id = false )
  {
    return \pdbmps\Plugin::determine_record_id( $record_id );
  }
  
  /**
   * sets up a log_table_field instance
   * 
   * @param object|string $field object or fieldname
   * @param int $record_id
   * 
   */
  private function setup_log_table_field( $field = false, $record_id = false )
  {
    if ( ! $record_id && is_a( $this->field, '\PDb_Field_Item' ) ) {
      $record_id = $this->field->record_id;
    }
    if ( ! is_a( $this->log_field, 'log_table_field' ) ) {
      if ( ! $field ) {
        $field = $this->field;
      }
      /* @var $field \PDb_Field_Item */
      $this->log_field = new log_table_field( $field, $this->get_record_id( $record_id ) );
    } elseif ( ( is_object( $field ) && $field->name !== $this->log_field->name ) || ( is_string( $field ) && $field !== $this->log_field->name ) ) {
      $this->log_field = new log_table_field( $field, $this->get_record_id( $record_id ) );
    }
  }

}
