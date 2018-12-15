<?php
/**
 * class for providing a PayPal member payments button and payments record
 * 
 * @category   Plugins
 * @package    WordPress
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016 7th Veil, LLC
 * @license    GPLv3
 * @version    1.7
 * @subpackage Participants Database
 */

namespace pdbmps;

class Plugin extends \PDb_Aux_Plugin {

  // plugin slug
  var $aux_plugin_name = 'pdb-member_payments';
  // shortname for the plugin
  var $aux_plugin_shortname = 'pdb_member_payments';

  /**
   * @var string  holds the PayPal submission URL
   */
  public $paypal_submission_url;

  /**
   * @var string name of the ajax action
   */
  const ajax = 'pdbmps-submit';
  
  /**
   * @var name of the unique id option
   */
  const unique_id = 'pdbmps_unique_id';

  /**
   * @var array holds the last received PDT data
   */
  private $transaction_data = array();
  
  /**
   * @var string name of the database version option
   */
  const db_version_option = 'pdbmps-db-version';
  
  /**
   * @var string current db version
   */
  public $db_version = '1.1';
  

  /**
   * 
   * @param string $plugin_file
   */
  public function __construct( $plugin_file )
  {
    parent::__construct( __CLASS__, $plugin_file );

    add_action( 'plugins_loaded', array($this, 'initialize'), 20 );
    add_action( 'template_redirect', array($this, 'check_transaction') );

    register_activation_hook( $plugin_file, array('\pdbmps\Init', 'activate') );
    register_deactivation_hook( $plugin_file, array('\pdbmps\Init', 'deactivate') );
    register_uninstall_hook( $plugin_file, array('\pdbmps\Init', 'uninstall') );

    add_action( 'wp_enqueue_scripts', array($this, 'enqueues') );
    add_action( 'admin_enqueue_scripts', array($this, 'admin_enqueues') );
    add_action( 'wp_ajax_nopriv_' . self::ajax, array('\pdbmps\Ajax', 'process') );
    add_action( 'wp_ajax_' . self::ajax, array('\pdbmps\Ajax', 'process') );

    add_filter( 'pdb-member_payments_query_var', array($this, 'key_cypher') );

    add_action( 'pdb-after_submit_update', '\pdbmps\Events::trigger_submission_actions' );
    add_action( 'pdb-after_submit_signup', '\pdbmps\Events::trigger_submission_actions' );

    add_filter( 'pdbmps-current_time', '\pdbmps\Plugin::current_date' );

    $this->settings_filter_callbacks();
    $this->set_custom_fields();
    
    // set up the member payment form filters
    new Payment_Form();
    
    // sets up the payment status functionality
    new payment_status\controller();
    // set up the shortcodes
    new shortcodes\controller();
    // set up the payment portal transaction listeners
    new transaction\listeners();

    /*
     * sets the verification URL to PayPal
     */
    $plugin = $this;
    add_filter( 'pdb-member_payments_paypal_url', function() use ( $plugin ) {
      return $plugin->paypal_url();
    } );
    
    // sets up the intial payment status on a manual offline payment
    add_action( 'pdbmps-update_log_' . payment_log\log_field::basename, function ( $data, $id ) {
      if ( isset($data[fields\last_payment_type::status_field_name]) && $data[fields\last_payment_type::status_field_name] === 'offline' ) {
        add_filter( 'pdbmps-initial_status', function ( $initial_status ) {
          return empty( $initial_status ) ? 'payable' : $initial_status;
        } );
      }
    }, 10, 2 );

    /*
     * sets up the IPN query var
     */
    add_filter( 'query_vars', function ( $vars ) {
      $vars[] = apply_filters( 'pdb-member_payments_query_var', 'pdb-member_payments' );
      return $vars;
    } );
    parent::set_plugin_options();

    add_filter( 'pdb-register_global_event', array($this, 'register_events'), 30 );

    add_action( 'pdbmps-paypal_log_stored', array($this, 'do_approval_on_payment') );
    
    add_filter( 'pdbmps-template_data', array( $this, 'add_standard_values' ) );
    
    /*
     *  this flags a submission as a pre-payment submission
     * 
     *  the payment submission happens when the transaction is confirmed
     * 
     */
    add_filter( 'pdbmps-pre_payment_submission', function ( $flag, $post) {
      if ( ( isset( $post[fields\last_payment_type::status_field_name] ) && $post[fields\last_payment_type::status_field_name] === 'paypal' ) ) {
        return true;
      }
      return $flag;
    }, 10, 2 );
  }

  /**
   * sets up the plugin options after the main plugin has loaded
   */
  public function initialize()
  {
    //parent::set_plugin_options();
    $this->aux_plugin_title = __( 'Member Payments', 'pdb_member_payments' );
    
    // set up the last payment status field
    new fields\last_payment_type();
    
    $this->setup_pending_status();
    
    // set up payment events
    add_action( 'pdb-member_payments_pdt_response', '\pdbmps\Events::handle_pp_response' );
    // fix any old option names
    $this->update_option_names();
  }
  

  /**
   * provides the current time
   * 
   * this is for testing
   * 
   * @return int unix timestamp
   */
  public static function current_date()
  {
    $current_date = time();
    return $current_date;
  }

  /**
   * checks for any global transactions
   */
  public function check_transaction()
  {
    // check for test variables to maybe run the cron job
    if ( WP_DEBUG && isset( $_GET['pdbmps_update_all'] ) ) {
      do_action( Init::cron_hook );
    }
  }

  /**
   * marks the record as approved when an online signup payment is made
   * 
   * @param int $id of the current record
   * 
   */
  public function do_approval_on_payment( $id )
  {
    if ( $this->plugin_option( 'approve_on_payment', false ) ) {
      $this->approve_record( $id );
    }
  }

  /**
   * marks a record as approved/unapproved
   * 
   * @param int $id the current record
   * @param bool  $approve  whether to approve or unapprove the record
   * 
   */
  public function approve_record( $id, $approve = true )
  {
    $approval_field_name = \Participants_Db::apply_filters( 'approval_field', 'approved' );
    global $wpdb;
    $approval_field = \Participants_Db::$fields[$approval_field_name];
    /* @var $approval_field \PDb_Form_Field_Def */
    list ( $yes, $no ) = $approval_field->option_values();
    $set_value = $approve ? $yes : $no;
    
    \Participants_Db::write_participant(array($approval_field_name => $set_value), $id);
  }

  /**
   * prints a payment button for use on a record form page
   * 
   */
  public function print_record_payment_button()
  {
    $this->add_record_id_to_form();
    $this->print_paypal_button( false );
  }

  /**
   * tells if PDT is set up
   * 
   * @return bool true if PDT is set up
   */
  public function pdt_is_configured()
  {
    $token = $this->pdt_token();
    return !empty( $token );
  }

  /**
   * tells if the payment was completed or not
   * 
   * this does not check for a validated payemnt
   * 
   * this has to be used in the template
   * 
   * @todo this is something that should be handled in the payment portal class
   * 
   * @return bool true if the payment was made
   */
  public function payment_was_made()
  {
    $payment_module = $this->payment_module();
    
    if ( $payment_module === 'offline' ) {
      // call it paid
      return true;
    }
    //$tx = filter_input( INPUT_GET, 'tx', FILTER_SANITIZE_STRING );
    $response = apply_filters( 'pdbmps-pdt_response_data', array() );
    /*
     * if PDT is not configured, we return true because we have no way of knowing 
     * at this moment if a payment was made or not, so we assume it was. The payment 
     * will be verified and logged later if IPN is set up.
     */
    return ( $payment_module && isset( $response['payment_status'] ) && $response['payment_status'] === 'Completed' ) || !$this->pdt_is_configured();
  }

  /**
   * provides the name of the payment module that was used
   * 
   * @return string member, signup, profile, offline
   */
  public function payment_module()
  {
    $payment_module = \Participants_Db::$session->get( 'payment_module' );
    switch ( true ) {
      case stripos( $payment_module, 'profile' ) !== false:
        return 'profile';
      case stripos( $payment_module, 'offline' ) !== false:
        return 'offline';
      case stripos( $payment_module, 'signup' ) !== false:
        return 'signup';
      case stripos( $payment_module, 'member-payment' ) !== false:
        return 'member';
      default:
        return 'offline';
    }
  }
  
  /**
   * supplies the thanks message template
   * 
   * @param string  $template optional template to use
   * 
   * @return string template
   */
  public function thanks_message_template ( $template = '' )
  {
    if ( empty( $template ) ) {
      switch ( $this->payment_module() ) {
        case 'member':
        case 'profile':
          $setting = 'member_payment_thanks_messsage';
          break;
        case 'signup':
          $setting = 'signup_payment_thanks_message';
          break;
        case 'offline':
          $setting = 'offline_payment_thanks_message';
          break;
      }
      $template = $this->plugin_option( $setting );
    }
    return $template;
  }

  /**
   * provides the latest user status info
   * 
   * @param int $record_id
   * @return array of printable (not raw) user status values
   */
  public function user_status_info( $record_id )
  {
    return payment_status\user_status::info( $record_id );
  }

  /**
   * provides the last recieved transaction data
   * 
   * @return array
   */
  public function transaction_data()
  {
    return $this->transaction_data;
  }

  /**
   * sets the transaction data
   * 
   * @param array $data
   */
  public function set_transaction_data( $data )
  {
    assert( is_array( $this->transaction_data ), 'transaction data' );
    $this->transaction_data = (array) $data;
  }

  /**
   * tells if the transactions data is set
   * 
   * @return bool
   */
  public function transaction_data_is_set()
  {
    return !empty( $this->transaction_data );
  }

  /**
   * provides a value from the last transaction
   * 
   * @param $fieldname  name of the field value to get
   * 
   * @return int|bool false if not found
   */
  public function last_tx_value( $fieldname )
  {
    return isset( $this->transaction_data[$fieldname] ) ? $this->transaction_data[$fieldname] : false;
  }
  
  /**
   * sets up the pending status functionality
   */
  private function setup_pending_status()
  {
    // set up the pending status field
    $pending_status_field = new fields\pending_payment_field();
    /**
     * @filter pdbmps-pending_payment_status
     * @return fields\pending_payment_field instance
     */
    add_filter( 'pdbmps-pending_payment_status', function () use ($pending_status_field) { 
      return $pending_status_field;
    } );
    // add the pending item to the list of statuses
    add_filter( 'pdbmps-payment_status_list', function ($list) {
      $list[] = 'pending';
      return $list;
    });
  }

  /**
   * prints some inline JS to include the participant id in the submission
   * 
   */
  private function add_record_id_to_form()
  {
    ?>
    <script>
      jQuery(document).ready(function ($) {
        var id_input = $('input[name=pid]').closest('form').find('input[name=id]').clone();
        $('form[action*=paypal]').prepend(
                $('<input>', {
                  type : 'hidden',
                  name : 'notify_url',
                  value : '<?php echo $this->notify_url() ?>' + id_input.val()
                })
                );
      });
    </script>

    <?php
  }

  /**
   * supplies the PayPal submission URL
   * 
   * @return string
   */
  public function paypal_url()
  {
    return $this->sandboxing() ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
  }

  /**
   * supplies the PayPal IPN endpoint URL
   * 
   * @todo do we need this at all?
   * 
   * @return string
   */
  public function paypal_endpoint()
  {
    //return $this->sandboxing() ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';
    return $this->sandboxing() ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' : 'https://ipnpb.paypal.com/cgi-bin/webscr';
  }

  /**
   * provides the notify url
   * 
   * @return string
   */
  public function notify_url()
  {
    $baseurl = get_bloginfo( 'url' );
    return $baseurl . ( strpos( $baseurl, '?' ) === false ? '?' : '&' );
  }
  

  /**
   * provides the query var key cypher
   * 
   * the cypher is stored as an option, this is so the query var key is unique to the installation
   * 
   * @return  string  the site cypher
   */
  public static function key_cypher()
  {
    $cypher = apply_filters( 'pdbmps-transaction_id_code', get_site_transient( self::unique_id ) );
    // if $cypher is bool false it means we need to generate a new one
    if ( $cypher === false ) {
      $cypher = self::new_cypher();
      set_site_transient( self::unique_id, $cypher );
    }
    return $cypher;
  }

  /**
   * provides a random cypher
   * 
   * uses the filter pdb-member_payments_new_ipn_code if a different code generation method is preferred
   * 
   * @return string
   */
  private static function new_cypher()
  {
    return apply_filters( 'pdbmps-new_transaction_id_code', uniqid() );
  }
  
  /**
   * provides the PayPal "custom" value
   * 
   * @param string $id the record id
   * @return string
   */
  public static function pp_custom_value( $id )
  {
    /**
     * @filter pdbmps_pp_custom_value
     * @parm  string the combined value
     * @param atring the record id
     * @return string
     */
    return apply_filters( 'pdbmps_pp_custom_value', self::key_cypher() . $id, $id );
  }
  
  /**
   * decodes the PP custom value using the unique ID for the plugin
   * 
   * @param string $code
   * @return int|bool the record ID or bool false if it doesn't match the unique ID
   */
  public static function get_record_id_from_return_code( $code )
  {
    $id = false;
    
    if ( strpos( $code, self::key_cypher() ) !== false ) {
      $id = str_replace( self::key_cypher(), '', $code ); 
    }
    /**
     * @filter pdbmps_decode_pp_custom_value
     * @param string the decoded ID
     * @param string the raw code
     * @return int the record ID
     */
    return apply_filters( 'pdbmps_decode_pp_custom_value', $id, $code );
  }

  /**
   * enqueue assets
   */
  public function enqueues()
  {
    // embed the javascript file that makes the AJAX request
    wp_register_script( 'pdb-member-payments', plugin_dir_url( $this->plugin_path ) . 'assets/member_payments.js', array('jquery') );

    // set up our dynamic variables
    wp_localize_script( 'pdb-member-payments', 'pdb_ajax', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( self::ajax ),
        'action' => self::ajax,
        'paypalURL' => $this->paypal_url(),
        'notify_url' => $this->notify_url(),
        'query_var' => apply_filters( 'pdb-member_payments_query_var', 'pdb-member_payments' ),
        'loading_indicator' => \Participants_Db::get_loading_spinner(),
        'payment_type_field' => fields\last_payment_type::status_field_name,
            )
    );
    // the script is enqueued in the shortcode classes
  }

  /**
   * sets up the asset queueing in the backed
   */
  public function admin_enqueues( $hook )
  {
    if ( preg_match( '/participants-database-add_participant|participants-database-edit_participant|participants-database/', $hook ) === 1 ) {
      wp_enqueue_style( 'pdb-member-payments-admin', plugin_dir_url( $this->plugin_path ) . 'assets/member_payments_admin.css' );
      wp_register_script( 'pdb-member-payments-admin', plugin_dir_url( $this->plugin_path ) . 'assets/member_payments_log_edit.js', array('jquery', 'jquery-ui-core', 'jquery-ui-dialog') );
      wp_localize_script( 'pdb-member-payments-admin', 'PDb_MPs', array(
          'apply' => \Participants_Db::$i18n['apply'],
          'log_field_name' => payment_log\log_field::log_field_name(),
          'logentry' => __( 'Log Entry', 'pdb-member_payments' ),
          'delete_confirm' => __( 'Delete this log entry?', 'pdb-member_payments' ),
          'dialog_title' => __( 'Confirm', 'pdb-member_payments' ) . ':',
          'action' => $this->ajax_handles(),
          'ajax_url' => admin_url( 'admin-ajax.php' ),
      ) );
      wp_enqueue_script( 'pdb-member-payments-admin' );
      wp_enqueue_style( 'wp-jquery-ui-dialog' );
    }
  }

  /**
   * supplies the initial payment log column names
   * 
   * the names correspond to field names in the PayPal PDT reponse
   * @link https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNandPDTVariables/#id091EB04C0HS
   * 
   * option_selection1 for the selected value of the first selector
   * 
   * @retrun array of $name => $title
   */
  public function initial_log_columns()
  {
    return array(
        'payment_date' => __( 'Date', 'pdb_member_payments' ),
        'payment_status' => __( 'Transaction Status', 'pdb_member_payments' ),
        'txn_id' => __( 'Transaction ID', 'pdb_member_payments' ),
        'mc_gross' => __( 'Amount', 'pdb_member_payments' ),
        fields\last_payment_type::status_field_name => __( 'Payment Type', 'pdb_member_payments' ),
    );
  }
  
  /**
   * provides some standard value keys for use in email and feedback message templates
   * 
   * @param array $data the raw data array as $name => $value
   * 
   * @return array with the standard values added
   */
  public function add_standard_values( $data )
  {
    foreach ( $this->standard_value_name_map() as $input_name => $standard_name ) {
      if ( array_key_exists( $input_name, $data ) && !array_key_exists( $standard_name, $data ) ) {
        /**
         * provides access to individual raw template data values before they are 
         * translated to standard values
         * 
         * @filter pdbmps-standard_value_{$standard_name}
         * @param mixed the raw value
         * @param array the full data array
         * @return the value to assign to the standard value
         */
        $data[$standard_name] = apply_filters( 'pdbmps-standard_value_' . $standard_name, $data[$input_name], $data );
      }
    }
    return $data;
  }
  
  /**
   * provides a standard value name map
   * 
   * @return array as $field_name => $standard_name
   */
  public function standard_value_name_map()
  {
    /**
     * the way this works is for evey item in the incoming array that matches the 
     * $input_name, we add an alias of the same value with the $standard_name
     * 
     * @filter pdbmps-standard_template_values_map
     * @param array of the form $input_name => $standard_name
     * @return array
     */
    return apply_filters( 'pdbmps-standard_template_values_map', array(
        'mc_gross'                      => 'payment_amount',
        'payment_amount'                => 'txn_amount',
        'pdbmps_payment_type'           => 'payment_type',
        'pdbmps_member_payment_status'  => 'member_payment_status',
        'pdbmps_next_due_date'          => 'next_due_date',
        'payment_date'                  => 'payment_date_timestamp',
        'pdbmps_payment_date'           => 'payment_date',
        'pdbmps_payment_type'           => 'payment_type',
        ) );
  }

  /**
   * sets up the custom fields
   */
  private function set_custom_fields()
  {
    $log_table = new fields\log_table( __( 'Log Table', 'pdb_member_payments' ) );

    /**
     * @filter pdbmps-update_field_log
     * @param string  $fieldname  name of the log field to update
     * @param array   $post       the incoming data
     * @param int     $record_id  id of the current record
     * 
     * @return array  the posted data
     */
    add_filter( 'pdbmps-update_field_log', array($log_table, 'update_field_log'), 10, 3 );
    // add our ajax methods
    foreach ( $this->ajax_handles() as $handle ) {
      add_action( 'wp_ajax_' . $handle, array($log_table, $handle) );
    }
  }

  /**
   * provides the button html
   * 
   * @param bool $strip_form if true, strips out the form tags
   * @return string
   * 
   */
  private function button_html( $strip_form = false )
  {
    $pattern = $strip_form ? '/(<\/?form.*>)/' : '/^$/';
    /**
     * @filter pdb-member_payments_button_html
     * @param string  $button_html the setting value
     * @return sring html
     */
    $button_html = apply_filters( 'pdb-member_payments_button_html', $this->plugin_option( ( $this->sandboxing() ? 'sandbox_button_html' : 'button_html' ) ) );
    /**
     * @filter pdb-member_payments_button_wrap
     * @param string $wrap html with placeholder for the button html
     * @return string
     */
    return sprintf( apply_filters( 'pdb-member_payments_button_wrap', '<div class="pdb-member_payment_button">%s</div>' ), preg_replace( $pattern, '', $button_html ) );
  }

  /**
   * prints the paypal button code
   * 
   * this optionally strips out the <form> tag so it can be embedded in a signup form
   * 
   * @param bool $strip_form if true, strips out the form tags
   * 
   * @return null|string string if printing is suppressed
   */
  public function print_paypal_button( $strip_form = true )
  {
    echo $this->button_html( $strip_form );
  }

  /**
   * provides the paypal button code
   * 
   * @param bool $strip_form if true, strips out the form tags
   * 
   * @return null|string string if printing is suppressed
   */
  public function paypal_button( $strip_form = false )
  {
    return $this->button_html( $strip_form );
  }

  /**
   * tells whether to use PayPal sandbox
   * 
   * @return bool true to use the sandbox
   */
  public function sandboxing()
  {
    return $this->plugin_option( 'sandboxing', '0' );
  }

  /**
   * provides the PDT token
   * 
   * @return string
   */
  public function pdt_token()
  {
    return $this->plugin_option( $this->sandboxing() ? 'sandbox_token' : 'token'  );
  }

  /**
   * provides an array of ajax callback handles
   * 
   * @return array
   */
  public function ajax_handles()
  {
    return array(
        'delete' => 'delete_log_entry',
    );
  }

  /**
   * registers the aux plugin events
   * 
   * @return array as $tag => $title
   */
  public function register_events()
  {
    // any global events for this plugin should go here
    // other plugin events are added in their respective modules 
    $events = array(
        'pdbmps-ipn_response_data' => __( 'Member Payments: IPN Received', 'pdb_member_payments' ),
        'pdb-member_payments_pdt_response' => __( 'Member Payments: PDT Received', 'pdb_member_payments' ),
    );
    return apply_filters( 'pdbmps-global_plugin_events', $events );
  }

  /**
   * provides the label for the given status
   * 
   * @param string  $status
   * @return  string  status label
   */
  public function status_label_string( $status )
  {
    $terms = $this->plugin_option( $status . '_label', $status );
    return is_array( $terms ) ? $terms['title'] : $terms;
  }

  /**
   * gets the current record ID
   * 
   * this tries several possible methods for getting the current record ID
   * 
   * @todo replace this func with a call to \Participants_Db::get_record_id()
   * 
   * @return int the record ID
   */
  public static function determine_record_id( $id = false )
  {
    if ( !$id && is_admin() ) {
      $id = filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT );
    }
    if ( !$id ) {
      $id = filter_input( INPUT_GET, \Participants_Db::$single_query, FILTER_SANITIZE_NUMBER_INT );
    }
    if ( !$id ) {
      $id = \Participants_Db::get_participant_id( filter_input( INPUT_GET, \Participants_Db::$record_query, FILTER_SANITIZE_STRING ) );
    }
    if ( !$id ) {
      $id = \Participants_Db::get_participant_id( get_query_var( 'pdb-record-edit-slug', false ) );
    }
    if ( !$id ) {
      $id = \Participants_Db::get_record_id_by_term( 'record_slug', get_query_var( 'pdb-record-slug', 0 ) );
    }
    if ( !$id ) {
      $id = \Participants_Db::$session->get( 'pdbid' );
    }
    return $id;
  }

  /**
   * SETTINGS API
   */

  /**
   * determines if the current user should see the advanced settings
   * 
   * @return bool true to show the advanced tab
   */
  public function show_adv_settings()
  {
    return \Participants_Db::current_user_has_plugin_role( 'admin', $this->aux_plugin_shortname . ' advanced settings' );
  }

  function settings_api_init()
  {
    register_setting( $this->aux_plugin_name . '_settings', $this->settings_name() );
// define settings sections
    $sections = array(
        array(
            'title' => __( 'PayPal Settings', 'pdb_member_payments' ),
            'slug' => 'paypal_setting_section',
        ),
        array(
            'title' => __( 'Member Payment Settings', 'pdb_member_payments' ),
            'slug' => 'member_payment_setting_section',
        ),
        array(
            'title' => __( 'Signup Payment Settings', 'pdb_member_payments' ),
            'slug' => 'signup_setting_section',
        ),
        array(
            'title' => __( 'Payment Status Settings', 'pdb_member_payments' ),
            'slug' => 'payment_status_setting_section',
        ),
        array(
            'title' => __( 'Offline Payment Settings', 'pdb_member_payments' ),
            'slug' => 'offline_payment_setting_section',
        ) 
    );
    if ( $this->show_adv_settings() ) {
      $sections[] = array(
          'title' => __( 'Advanced Settings', 'pdb_member_payments' ),
          'slug' => 'advanced_setting_section',
      );
    }
    $this->_add_settings_sections( $sections );


    $this->check_options();

    /** PAYPAL SETTINGS ** */
    $this->add_setting( array(
        'name' => 'payment_button_label',
        'title' => __( 'PayPal Payment Button Label', 'pdb_member_payments' ),
        'type' => 'text',
        'default' => 'Click to make your payment with PayPal',
        'help' => __( 'text shown next to the PayPal payment button', 'pdb_member_payments' ),
        'style' => 'width:100%',
        'section' => 'paypal_setting_section',
            )
    );

    $this->add_setting( array(
        'name' => 'token',
        'title' => __( 'PayPal Identity Token', 'pdb_member_payments' ),
        'type' => 'text',
        'default' => '',
        'help' => __( 'this is provided by PayPal when you set up PDT in your account. Leave this blank if you are not using PDT.', 'pdb_member_payments' ),
        'style' => 'width:100%',
        'section' => 'paypal_setting_section',
            )
    );


    $this->add_setting( array(
        'name' => 'button_html',
        'title' => __( 'PayPal Button HTML', 'pdb_member_payments' ),
        'type' => 'textarea',
        'default' => '',
        'help' => __( 'this is obtained from your account at PayPal', 'pdb_member_payments' ),
        'style' => 'width:100%;height:30em;',
        'section' => 'paypal_setting_section',
            )
    );

    $this->add_setting( array(
        'name' => 'paypal_type_label',
        'title' => __( 'Payment Type Label', 'pdb_member_payments' ),
        'type' => 'text',
        'default' => 'PayPal',
        'help' => __( 'this is the visible name of this payment type', 'pdb_member_payments' ),
        'style' => 'width:100%',
        'section' => 'paypal_setting_section',
            )
    );

    /** MEMBER PAYMENT FORM SETTINGS ** */
    $this->add_setting( array(
        'name' => 'match_fields',
        'title' => __( 'Payment Form Match Fields', 'pdb_member_payments' ),
        'type' => 'text',
        'help' => __( 'comma-separated list of field names. These fields are checked to find a member\'s record. The information these fields provide must be enough to identify only one record.', 'pdb_member_payments' ),
        'default' => 'first_name,last_name',
        'style' => 'width:100%',
        'section' => 'member_payment_setting_section',
            )
    );

    $this->add_setting( array(
        'name' => 'not_found_message',
        'title' => __( 'Record Not Found Message', 'pdb_member_payments' ),
        'type' => 'textarea',
        'style' => 'width:100%;height: 5em',
        'section' => 'member_payment_setting_section',
        'help' => __( 'message to show if the member\'s record can\'t be found with the info supplied.', 'pdb_member_payments' ),
        'default' => 'We can\'t find your record. Please make sure your name and ID are correct.',
            )
    );


    $this->add_setting( array(
        'name' => 'member_payment_thanks_messsage',
        'title' => __( 'Payment Thanks Message', 'pdb_member_payments' ),
        'type' => 'richtext',
        'style' => 'width:100%',
        'section' => 'member_payment_setting_section',
        'help' => __( 'message shown on screen when the user returns from making a payment using the member payment form. Placeholder tags can be used.', 'pdb_member_payments' ),
        'default' => __( 'Thanks, [first_name], for making a payment.', 'pdb_member_payments' ),
            )
    );


    $this->add_setting( array(
        'name' => 'member_payment_cancel_return_messsage',
        'title' => __( 'Cancel Return Message', 'pdb_member_payments' ),
        'type' => 'richtext',
        'style' => 'width:100%',
        'section' => 'member_payment_setting_section',
        'help' => __( 'message shown on screen when the user returns without successfully making a payment. Placeholder tags can be used.', 'pdb_member_payments' ),
        'default' => __( 'You must make a payment to renew your membership.', 'pdb_member_payments' ),
            )
    );

    $this->add_setting( array(
        'name' => 'member_payment_offline_payments_enable',
        'title' => __( 'Enable Offline Payments', 'pdb_member_payments' ),
        'type' => 'checkbox',
        'default' => '0',
        'help' => __( 'when checked, the user can select an offline payment method, such as a mailing a check or giving a credit card by phone.', 'pdb_member_payments' ),
        'section' => 'member_payment_setting_section',
            )
    );

    /** SIGNUP PAYMENT SETTINGS ** */
    $this->add_setting( array(
        'name' => 'approve_on_payment',
        'title' => __( 'Approve on Payment', 'pdb_member_payments' ),
        'type' => 'checkbox',
        'default' => '0',
        'help' => __( 'when checked, the user\'s record will be marked "approved" when an online payment is made.', 'pdb_member_payments' ),
        'section' => 'signup_setting_section',
            )
    );

    $this->add_setting( array(
        'name' => 'signup_payment_thanks_message',
        'title' => __( 'Signup Payment Thanks Message', 'pdb_member_payments' ),
        'type' => 'richtext',
        'style' => 'width:100%',
        'section' => 'signup_setting_section',
        'help' => __( 'thanks message shown after making a PayPal payment from the signup form. May include value tags from the signup submission. PayPal PDT must be working to show payment values.', 'pdb_member_payments' ),
        'default' => __( 'Thank you, [first_name], for your payment of $[mc_gross].', 'pdb_member_payments' ),
            )
    );


    $this->add_setting( array(
        'name' => 'signup_payment_cancel_return_messsage',
        'title' => __( 'Cancel Return Message', 'pdb_member_payments' ),
        'type' => 'richtext',
        'style' => 'width:100%',
        'section' => 'signup_setting_section',
        'help' => __( 'message shown on screen when the user returns without successfully making a payment. Placeholder tags can be used.', 'pdb_member_payments' ),
        'default' => __( 'You must make a payment to register.', 'pdb_member_payments' ),
            )
    );

    $this->add_setting( array(
        'name' => 'offline_payments_enable',
        'title' => __( 'Enable Offline Payments', 'pdb_member_payments' ),
        'type' => 'checkbox',
        'default' => '0',
        'help' => __( 'when checked, the user can select an offline payment method, such as a mailing a check or giving a credit card by phone.', 'pdb_member_payments' ),
        'section' => 'signup_setting_section',
            )
    );
    
    /* OFFLINE PAYMENTS */


    $this->add_setting( array(
        'name' => 'payment_type_selector_label',
        'title' => __( 'Payment Type Selector Label', 'pdb_member_payments' ),
        'type' => 'text',
        'default' => __( 'Payment Type', 'pdb_member_payments' ),
        'help' => __( 'this labels the radio control that selects the payment type', 'pdb_member_payments' ),
        'style' => 'width:100%',
        'section' => 'offline_payment_setting_section',
            )
    );


    $this->add_setting( array(
        'name' => 'offline_type_label',
        'title' => __( 'Offline Payment Label', 'pdb_member_payments' ),
        'type' => 'text',
        'default' => __( 'Pay by Check', 'pdb_member_payments' ),
        'help' => __( 'this is the visible name of this payment type', 'pdb_member_payments' ),
        'style' => 'width:100%',
        'section' => 'offline_payment_setting_section',
            )
    );


    $this->add_setting( array(
        'name' => 'offline_submit_button',
        'title' => __( 'Offline Submit Button Text', 'pdb_member_payments' ),
        'type' => 'text',
        'default' => _x( 'Pay Offline', 'submit button text', 'pdb_member_payments' ),
        'help' => __( 'text on the offline payment submit button', 'pdb_member_payments' ),
        'style' => 'width:100%',
        'section' => 'offline_payment_setting_section',
            )
    );

    $this->add_setting( array(
        'name' => 'offline_payment_help_text',
        'title' => __( 'Offline Payment Help Message', 'pdb_member_payments' ),
        'type' => 'textarea',
        'style' => 'width:100%',
        'section' => 'offline_payment_setting_section',
        'help' => __( 'message shown by the offline payment selector in the signup form.', 'pdb_member_payments' ),
        'default' => __( 'Mail in your check or money order.', 'pdb_member_payments' ),
            )
    );



    $this->add_setting( array(
        'name' => 'offline_payment_thanks_message',
        'title' => __( 'Offline Payment Thanks Message', 'pdb_member_payments' ),
        'type' => 'richtext',
        'style' => 'width:100%',
        'section' => 'offline_payment_setting_section',
        'help' => __( 'message shown on screen when the user chooses the offline payment option. May include value tags from the signup submission.', 'pdb_member_payments' ),
        'default' => __( 'Please mail your check.', 'pdb_member_payments' ),
            )
    );


    $this->add_setting( array(
        'name' => 'pending_label',
        'title' => __( '"Pending" Status Label', 'pdb_member_payments' ),
        'type' => 'status_label',
        'style' => 'width:100%',
        'section' => 'offline_payment_setting_section',
        'help' => __( 'This is the number of days after an offline payment promise is submitted that the "pending" status will expire. The label is the visible label used for this status', 'pdb_member_payments' ),
        'default' => array('offset' => '30', 'title' => 'Pending'),
            )
    );

//    $this->add_setting( array(
//        'name' => 'profile_payment_offline_payments_enable',
//        'title' => __( 'Enable Offline Payments in Profile Form', 'pdb_member_payments' ),
//        'type' => 'checkbox',
//        'default' => '0',
//        'help' => __( 'when checked, the user can select an offline payment method, such as a mailing a check or giving a credit card by phone.', 'pdb_member_payments' ),
//        'section' => 'offline_payment_setting_section',
//            )
//    );


    /** PAYMENT STATUS ** */
    $this->add_setting( array(
        'name' => 'payment_status_enable',
        'title' => __( 'Enable Member Payment Status', 'pdb_member_payments' ),
        'type' => 'checkbox',
        'default' => '0',
        'help' => __( 'when checked, the user\'s current payment status will be kept in the "Member Payment Status" field.', 'pdb_member_payments' ),
        'section' => 'payment_status_setting_section',
            )
    );

    $this->add_setting( array(
        'name' => 'payment_due_mode',
        'title' => __( 'Payment Due Mode', 'pdb_member_payments' ),
        'type' => 'radio',
        'options' => array(
            'period' => __( 'Fixed Period', 'pdb_member_payments' ),
            'fixed' => __( 'Fixed Dates', 'pdb_member_payments' ),
        ),
        'default' => 'period',
        'help' => __( 'determines how the payment due date is calculated.', 'pdb_member_payments' ),
        'section' => 'payment_status_setting_section',
        'class' => 'pdbmps-type-switch',
            )
    );


    $this->add_setting( array(
        'name' => 'renewal_date_list',
        'title' => __( 'Renewal Dates', 'pdb_member_payments' ),
        'type' => 'textarea',
        'style' => 'width:100%;height: 6em',
        'section' => 'payment_status_setting_section',
        'help' => __( 'list of payment due dates. Each date must be on it\'s own line, do not include the year because these dates will apply to any year.', 'pdb_member_payments' ),
        'default' => 'Jan 1
April 1
July 1
November 1',
        'class' => 'pdbmps-type-switch pdbmps-type-switch-fixed',
            )
    );

    $this->add_setting( array(
        'name' => 'renewal_period',
        'title' => __( 'Renewal Period', 'pdb_member_payments' ),
        'type' => 'dropdown',
        'options' => $this->period_selector_items(),
        'section' => 'payment_status_setting_section',
        'help' => __( 'the renewal period for the payment.', 'pdb_member_payments' ),
        'default' => 'year',
        'class' => 'pdbmps-type-switch pdbmps-type-switch-period',
            )
    );

    $this->add_setting( array(
        'name' => 'late_payment_setting',
        'title' => __( 'Late Payment Setting', 'pdb_member_payments' ),
        'type' => 'radio',
        'options' => array(
            'last_due' => __( 'Payment goes to last due date', 'pdb_member_payments' ),
            'last_payment' => __( 'Payment goes to last payment date', 'pdb_member_payments' ),
        ),
        'default' => 'last_due',
        'help' => __( 'determines what date a late payment is applied to', 'pdb_member_payments' ),
        'section' => 'payment_status_setting_section',
        'class' => 'pdbmps-type-switch pdbmps-type-switch-period control-left',
            )
    );

    $this->add_setting( array(
        'name' => 'paid_label',
        'title' => __( '"Paid" Status Label', 'pdb_member_payments' ),
        'type' => 'text',
        'style' => 'width:100%',
        'section' => 'payment_status_setting_section',
        'help' => __( 'status label to use when the user has paid.', 'pdb_member_payments' ),
        'default' => 'Current',
            )
    );


    $this->add_setting( array(
        'name' => 'due_label',
        'title' => __( '"Payment Due" Status Label', 'pdb_member_payments' ),
        'type' => 'text',
        'style' => 'width:100%',
        'section' => 'payment_status_setting_section',
        'help' => __( 'status label to use after the payment is due.', 'pdb_member_payments' ),
        'default' => 'Payment Due',
            )
    );


    $this->add_setting( array(
        'name' => 'payable_label',
        'title' => __( '"Payable" Status', 'pdb_member_payments' ),
        'type' => 'status_label',
        'style' => 'width:100%',
        'section' => 'payment_status_setting_section',
        'help' => __( 'days before due and status label to use before the payment is due. IMPORTANT: the days before and the days after settings here must total less than the number of days between due dates.', 'pdb_member_payments' ),
        'default' => array('offset' => '14', 'title' => 'Payable'),
            )
    );


    $this->add_setting( array(
        'name' => 'past_due_label',
        'title' => __( '"Past Due" Status', 'pdb_member_payments' ),
        'type' => 'status_label',
        'style' => 'width:100%',
        'section' => 'payment_status_setting_section',
        'help' => __( 'days after due and status label to use after the payment is past due.', 'pdb_member_payments' ),
        'default' => array('offset' => '14', 'title' => 'Payment Past Due'),
            )
    );

    $this->add_setting( array(
        'name' => 'initial_status',
        'title' => __( 'Initial Payment Status', 'pdb_member_payments' ),
        'type' => 'dropdown',
        'options' => payment_status\payment_status_field::status_selector_items(),
        'section' => 'payment_status_setting_section',
        'help' => __( 'records with no payment history are assumed to have this payment status. If left blank, records with no payment history will not trigger payment status change events (such as to send an email) until after a payment is made.', 'pdb_member_payments' ),
        'default' => '',
            )
    );





    /** ADVANCED SETTINGS ** */
    if ( $this->show_adv_settings() ) :

      $this->add_setting( array(
          'name' => 'log_edit_lock',
          'title' => __( 'Payment Log Edit Lock', 'pdb_member_payments' ),
          'type' => 'checkbox',
          'default' => '1',
          'help' => __( 'if checked, the payment log cannot have entries added manually.', 'pdb_member_payments' ),
          'section' => 'advanced_setting_section',
              )
      );


      $this->add_setting( array(
          'name' => 'sandboxing',
          'title' => __( 'Use PayPal Sandbox', 'pdb_member_payments' ),
          'type' => 'checkbox',
          'default' => '0',
          'help' => sprintf( __( 'when checked, all payments will use %sPayPal Sandbox%s. NO REAL TRANSACTIONS WILL BE PROCESSED. You must also use both a token and button HTML from your sandbox account in the settings below.', 'pdb_member_payments' ), '<a href="https://www.sandbox.paypal.com" target="_blank" >', '</a>' ),
          'section' => 'advanced_setting_section',
              )
      );

      $this->add_setting( array(
          'name' => 'sandbox_token',
          'title' => __( 'PayPal Sandbox Identity Token', 'pdb_member_payments' ),
          'type' => 'text',
          'default' => '',
          'help' => __( 'this is provided by PayPal Sandbox when you set up PDT in your account. Leave this blank if you are not using PDT.', 'pdb_member_payments' ),
          'style' => 'width:100%',
          'section' => 'advanced_setting_section',
              )
      );


      $this->add_setting( array(
          'name' => 'sandbox_button_html',
          'title' => __( 'PayPal Sandbox Button HTML', 'pdb_member_payments' ),
          'type' => 'textarea',
          'default' => '',
          'help' => __( 'this is obtained from your account at PayPal Sandbox', 'pdb_member_payments' ),
          'style' => 'width:100%;height:30em;',
          'section' => 'advanced_setting_section',
              )
      );

      $this->add_setting( array(
          'name' => 'test_date',
          'title' => __( 'Testing Date', 'pdb_member_payments' ),
          'type' => 'text',
          'style' => 'width:100%',
          'section' => 'advanced_setting_section',
          'help' => __( 'this date will be used as the current date when testing. Leave blank to use the normal current date. This setting is only active if WP_DEBUG is enabled.', 'pdb_member_payments' ),
          'default' => '',
          'class' => ( PDB_DEBUG ? '' : '" readonly="readonly' ), // this setting is only editable if WP_DEBUG is enabled
              )
      );

    endif;
    // save the initial settings
    add_option( $this->settings_name(), $this->plugin_options );
  }

  /**
   * saves the initial set of settings
   * 
   * @param array $atts an array of settings parameters
   * 
   */
  protected function add_setting( $atts )
  {
    parent::add_setting( $atts );
    /*
     * all this is to ensure the options are available when the plugin first activates
     */
    $this->plugin_options[$atts['name']] = isset( $this->plugin_options[$atts['name']] ) ? $this->plugin_options[$atts['name']] : $atts['default'];
  }

  /**
   * renders the plugin settings page
   */
  function render_settings_page()
  {
    ?>
    <style>
      input.checkbox+p.description {
        display: inline-block;
      }
    </style>
    <div class="wrap pdb-aux-settings-tabs participants_db" style="max-width:670px;">

      <div id="icon-plugins" class="icon32"></div>
      <h2><?php printf( _x( '%s Setup', 'the plugin title', 'pdb_member_payments' ), \Participants_Db::$plugin_title . ' ' . $this->aux_plugin_title ) ?></h2>
      <?php settings_errors(); ?>
      <p><?php _e( 'Provides a PayPal member payment button three different ways.', 'pdb_member_payments' ) ?></p>
      <ul style="list-style: inside;">
        <li><?php printf( __( '%sSignup Payment:%s the user registers and makes a PayPal payment in one form submission.', 'pdb_member_payments' ), '<strong>', '</strong>' ) ?></li>
        <li><?php printf( __( '%sMember Payment:%s the user enters identifying information and makes a payment, credited to their account.', 'pdb_member_payments' ), '<strong>', '</strong>' ) ?></li>
        <li><?php printf( __( '%sProfile Payment:%s The user, after using their private link to edit their profile, can make a payment on that page.', 'pdb_member_payments' ), '<strong>', '</strong>' ) ?></li>
      </ul>
      <p><?php _e( 'The plugin works by placing a PayPal payment button in a Participants Database form. When the user clicks on the PayPal payment button, the contents of the form is processed into Participants database and the user is sent to PayPal to make the payment. When they return, the payment details are logged via PayPal Payment Data Transfer. This data can also be sent by PayPal asynchronously using Instant Payment Notification.', 'pdb_member_payments' ) ?></p>
      <p><?php printf( __( 'This plugin requires the PayPal account be configured to send %sInstant Payment Notifications%s or process a %sPayment Data Transfer.%s Instructions for setting that up can be found by clicking on these links.', 'pdb_member_payments' ), '<a href="https://www.paypal.com/us/cgi-bin/webscr?cmd=p/acc/ipn-info-outside" >', '</a>', '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=p/xcl/rec/pdt-intro-outside" >', '</a>' ) ?></p>
      <form method="post" action="options.php">
        <?php settings_fields( $this->aux_plugin_name . '_settings' ); ?>
        <div class="ui-tabs">
          <?php $this->print_settings_tab_control() ?>
          <?php do_settings_sections( $this->aux_plugin_name ); ?>
        </div>
        <?php submit_button(); ?>
      </form>
      <aside class="attribution"><?php echo $this->attribution ?></aside>
    </div><!-- /.wrap -->  

    <?php
  }

  /**
   * renders a section heading
   * 
   * @param array $section information about the section
   */
  function setting_section_callback_function( $section )
  {
    printf( '<a name="%s"></a>', $section['id'] );
    switch ( $section['id'] ) {
      case 'member_payment_setting_section':
        ?>
        <p><?php _e( '"Member Payments" are payments made by people who already have a record in the database. When they fill out the payment form, the "match fields" are checked to locate the member\'s record. If a single record is found, the payment is logged in that record. If a matching record cannot be found, the "Record Not Found" message is shown and the form is not submitted.', 'pdb_member_payments' ) ?></p>
        <p><?php _e( 'The member payment form is shown by using the [pdb_member_payment] shortcode.', 'pdb_member_payments' ) ?></p>
        <?php
        break;
      case 'payment_status_setting_section':
        ?>
        <p><?php _e( 'Use these settings to configure your dues schedule or payment period for recurring payments. Also configures the payment (or dues) status field, which holds the current user\'s payment status.', 'pdb_member_payments' ) ?></p>
        <ul style="list-style: inside;">
          <li><?php _e( '<strong>Fixed Period:</strong> The due date is calculated based on the last payment date.', 'pdb_member_payments' ) ?></li>
          <li><?php _e( '<strong>Fixed Date:</strong> The due date is based on a set of fixed dates in the year.', 'pdb_member_payments' ) ?></li>
        </ul>
        <p><?php _e( 'See the <a href="https://xnau.com/product_support/member-payments/" target="_blank">documentation</a> for additional information.', 'pdb_member_payments' ) ?></p>
        <p><?php _e( 'Sending email reminders when a member\'s payment status changes requires the use of the <a href="https://xnau.com/product/email-expansion/" target="_blank">Email Expansion Kit</a> plugin.', 'pdb_member_payments' ) ?></p>
        <script>
          jQuery(function ($) {
            var type_selector = $('input[name="pdb_member_payments_settings[payment_due_mode]"]');
            type_selector.on('change', function (e) {
              set_state($(e.target).val());
            });
            var set_state = function (value) {
              var fixed_set = $('tr.pdbmps-type-switch-fixed');
              var period_set = $('tr.pdbmps-type-switch-period')
              switch (value) {
                case 'fixed':
                  fixed_set.show();
                  period_set.hide();
                  break;
                case 'period':
                case 'period-fixed':
                  fixed_set.hide();
                  period_set.show();
                  break;
              }
            }
            set_state(type_selector.filter(':checked').val());
            $('#payment_status_setting_section .form-table tr').unwrap('tbody');
            $('tr.pdbmps-type-switch').wrapAll('<tbody class="type-switch-group" />');
        //            $('tbody.type-switch-group').before('</tbody>');
        //            $('tbody.type-switch-group').after('<tbody>');
          });
        </script>
        <style>
          .type-switch-group {
            background-color: #dedede;
          }
          .type-switch-group th {
            padding-left: 10px;
          }
        </style>
        <?php
        break;
      case 'signup_setting_section':
        ?>
        <p><?php _e( 'Signup form payments are usually used in cases where the person needs to pay to become a member, but can also be used when an organization is accepting donations and wants to build a database of new donors.', 'pdb_member_payments' ) ?></p>
        <p><?php _e( 'Offline payments are payment that are not made with the help of your website, such as mailed-in or in-person check or cash payments. When such a payment is made, the transaction would be manually logged into the person\'s record.', 'pdb_member_payments' ) ?></p>
      <?php
      case 'offline_payment_setting_section':
        ?>
        <p><?php _e( 'Offline payments are payments such as mailed checks, in-person payments or over-the-phone credit card payments.', 'pdb_member_payments' ) ?></p>
        <p><?php _e( 'When an offline payment is submitted, it becomes a dated "promise to pay" and the user\'s payment status will change to "pending" until a payment is logged or the expiration date is passed.', 'pdb_member_payments' ) ?></p>
        <?php
        break;
    }
  }

  /**
   * provides a time period selector
   * 
   * @filter pdb-member_payments_period_selector_values
   * @param array of offset string => title (offset strings must use a numeral 
   *              and be in a form that strtotime will understand)
   * @return array
   * 
   * @return array as value => title
   */
  public function period_selector_items()
  {
    return apply_filters( 'pdb-member_payments_period_selector_values', array(
        '1 week' => __( '1 week', 'pdb_member_payments' ),
        '1 month' => '1 ' . _n( 'month', 'months', 1, 'pdb_member_payments' ),
        '3 months' => '3 ' . _n( 'month', 'months', 3, 'pdb_member_payments' ),
        '4 months' => '4 ' . _n( 'month', 'months', 4, 'pdb_member_payments' ),
        '6 months' => '6 ' . _n( 'month', 'months', 6, 'pdb_member_payments' ),
        '1 year' => __( '1 year', 'pdb_member_payments' ),
            ) );
  }

  /**
   * builds a text setting element
   * 
   * @param array $values array of setting values
   *                       0 - setting name (%1$s)
   *                       1 - element type (%2$s)
   *                       2 - setting value
   *                       3 - title
   *                       4 - CSS class
   *                       5 - CSS style
   *                       6 - help text
   *                       7 - setting options array
   *                       8 - select string
   * @return string HTML
   */
  protected function _build_status_label( $values )
  {
    $offset_note = '';
    switch ( $values[0] ) {
      case 'payable_label':
        $atts = 'step="1"  min="1"';
        $offset_note = __( 'days or less before due date is labeled:', 'pdb_member_payments' );
        break;
      case 'due_label':
        $atts = 'readonly="readonly"';
        $values[2][offset] = '0';
        break;
      case 'past_due_label':
        $atts = 'step="1" min="1"';
        $offset_note = __( 'days or more after due date is labeled:', 'pdb_member_payments' );
        break;
      case 'pending_label':
        $atts = 'step="1" min="1"';
        $offset_note = __( 'days while awaiting payment will be labeled:', 'pdb_member_payments' );
        break;
    }

    $pattern = "\n" . '<div class="status-label-setting"><input name="' . $this->settings_name() . '[%1$s][offset]" type="number" value="' . $values[2]['offset']
            . '" title="%4$s Offset" ' . $atts . ' class="%5$s status-label-offset" style="width: 4em"  /><span class="offset_note">' . $offset_note . '</span>';
    $pattern .= '<input name="' . $this->settings_name() . '[%1$s][title]" type="text" value="' . $values[2]['title']
            . '" title="%4$s Title" class="%5$s status-label-title" style="width: 24em;" />';
    if ( !empty( $values[6] ) ) {
      $pattern .= "\n" . '<p class="description">%7$s</p></div>';
    }
    return vsprintf( $pattern, $values );
  }

  /**
   * sets up the settings callbacks
   */
  public function settings_filter_callbacks()
  {
    $plugin = $this;
    /**
     * applies the edit lock setting
     */
    add_filter( 'pdb_member_payments_log_edit_lock', function () use( $plugin ) {
      return $plugin->plugin_option( 'log_edit_lock', '0' ) == '1';
    } );
    /**
     * applies the payment status setting
     */
    add_filter( 'pdbmps-payment_status_enable', function () use( $plugin ) {
      return $plugin->payment_status_enabled() == '1';
    } );
    /**
     * applies the member payments setting
     */
    add_filter( 'pdb_member_payments_member_payment', function () use( $plugin ) {
      return $plugin->plugin_option( 'member_payment', '0' ) == '1';
    } );
    /**
     * applies the not found message setting
     */
    add_filter( 'pdb-member_payments_not_found_message', function () use( $plugin ) {
      return $plugin->plugin_option( 'not_found_message' );
    } );
    /**
     * applies the test date setting
     */
    add_filter( 'pdb-member_payments_test_date', function ( $date ) use( $plugin ) {
      $setting = $plugin->plugin_option( 'test_date', '' );
      return \PDb_Date_Parse::timestamp( ( empty( $setting ) || WP_DEBUG === false ) ? $date : $setting  );
    } );

    /**
     * supplies the offline payment thanks message setting
     */
    add_filter( 'pdb-member_payments_offline_payment_thanks_message', function () use( $plugin ) {
      return $plugin->plugin_option( 'offline_payment_thanks_message' );
    } );

    // 'signup_payment_thanks_message',


    /**
     * supplies the paypal payment thanks message setting
     */
    add_filter( 'pdb-member_payments_paypal_payment_thanks_message', function () use( $plugin ) {
      return $plugin->plugin_option( 'signup_payment_thanks_message' );
    } );

    /**
     * supplies the current user's renewal period
     * 
     * @param int $recoord_id the current member's record id
     */
    add_filter( 'pdbmps-renewal_period', function ( $record_id ) use( $plugin ) {
      /*
       * this is where we would implement payment periods based on the member, for 
       * instance if they paid for multiple periods, their due date could be calculated 
       * accordingly
       */
      return $plugin->plugin_option( 'renewal_period' );
    } );
    /**
     * applies the match_fields setting
     */
    add_filter( 'pdb-member_payments_match_fields', function () use( $plugin ) {
      return explode( ',', str_replace( ' ', '', $plugin->plugin_option( 'match_fields' ) ) );
    } );
    /*
     * applies a filter to the list of settings
     */
    foreach ( array(
'payment_due_mode',
 'renewal_date_list',
 'paid_label',
 'due_label',
 'past_due_label',
 'payable_label',
 'pending_label',
 'offline_type_label',
 'paypal_type_label',
 'payment_button_label',
 'offline_payment_help_text',
 'offline_submit_button',
 'payment_type_selector_label',
 'initial_status',
 'late_payment_setting',
    ) as $setting ) {
      add_filter( 'pdbmps-' . $setting, function ( $default = '' ) use ( $plugin, $setting ) {
        return $plugin->plugin_option( $setting, $default );
      } );
    }
  }

  /**
   * supplies the offline payment enable setting
   * 
   * @param string  $module the current module
   * @return bool
   */
  public function offline_payments_enabled( $module = 'signup' )
  {
    switch ( $module ) {
      case 'member-payment';
        $setting = 'member_payment_offline_payments_enable';
        break;
      case 'record-member-payment';
        $setting = 'profile_payment_offline_payments_enable';
        break;
      case 'signup':
      default:
        $setting = 'offline_payments_enable';
    }
    $settings = get_option( $this->settings_name() );
    
    return isset($settings[$setting]) ? (bool) $settings[$setting] : false;
  }

  /**
   * supplies the payment status enable setting
   * 
   * @return bool
   */
  public function payment_status_enabled()
  {
    $settings = get_option( $this->settings_name() );
    return isset( $settings['payment_status_enable'] ) ? (bool) $settings['payment_status_enable'] : false;
  }

  /**
   * supplies a list of database columns to select
   * 
   * @param bool  $null if true, include a blank selection
   * @retrun array
   */
  private function id_columns( $null = false )
  {
    $columns = \PDb_Settings::_get_identifier_columns( true );

    $columns = array_flip( array_filter( $columns ) );
    return $null ? array('' => __( 'none', 'pdb-permalinks' )) + $columns : $columns;
  }

  /**
   * check for a PayPal button HTML
   * 
   */
  private function check_options()
  {
    if ( stripos( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING ), 'participants-database' ) === false ) {
      return;
    }
    $button_html = $this->plugin_option( 'button_html', '' );
    $sandbox_button_html = $this->plugin_option( 'sandbox_button_html', '' );
    $sandboxing = $this->plugin_option( 'sandboxing', '0' ) == '1';


    if ( (!$sandboxing && empty( $button_html ) ) || ( $sandboxing && empty( $sandbox_button_html ) ) ) {
      $settings_page = admin_url( 'admin.php?page=participants-database-pdb-member_payments_settings' );
      $message = $sandboxing ?
              sprintf(
                      __( 'You must define the "PayPal Sandbox Button HTML" in the %sParticipants Database Member Payments%s advanced settings to test using PayPal Sandbox', 'pdb_member_payments' ), '<a href="' . $settings_page . '">', '</a>'
              ) :
              sprintf(
                      __( 'You must define the "PayPal Button HTML" in the %sParticipants Database Member Payments%s settings to take PayPal payments.', 'pdb_member_payments' ), '<a href="' . $settings_page . '">', '</a>'
      );
      add_action( 'admin_notices', function () use ( $message ) {
        echo '<div class="notice notice-warning is-dismissible"><p class="dashicons-before dashicons-warning">' . $message . '</p></div>';
      } );
    }
  }
  
  /**
   * this series of callbacks check to make sure there are no duplicate status labels
   * 
   * 
   * @param string $new_value
   * @param string $old_value
   * @return string value to save
   */
  public function setting_callback_for_paid_label( $new_value, $old_value )
  {
    return $this->label_duplicate( 'paid_label', $new_value ) ? $old_value: $new_value;
  }
  /**
   * this series of callbacks check to make sure there are no duplicate status labels
   * 
   * 
   * @param string $new_value
   * @param string $old_value
   * @return string value to save
   */
  public function setting_callback_for_due_label( $new_value, $old_value )
  {
    return $this->label_duplicate( 'due_label', $new_value ) ? $old_value: $new_value;
  }
  /**
   * this series of callbacks check to make sure there are no duplicate status labels
   * 
   * 
   * @param string $new_value
   * @param string $old_value
   * @return string value to save
   */
  public function setting_callback_for_past_due_label( $new_value, $old_value )
  {
    return $this->label_duplicate( 'past_due_label', $new_value ) ? $old_value: $new_value;
  }
  /**
   * this series of callbacks check to make sure there are no duplicate status labels
   * 
   * 
   * @param string $new_value
   * @param string $old_value
   * @return string value to save
   */
  public function setting_callback_for_payable_label( $new_value, $old_value )
  {
    return $this->label_duplicate( 'payable_label', $new_value ) ? $old_value: $new_value;
  }
  
  /**
   * shows an error message when there is a duplicate label
   * 
   * @param string $name
   * @param string  $label the candidate label
   * @return bool true if there is a duplicate label
   */
  private function label_duplicate( $name, $label )
  {
    if ( method_exists($this, 'setting_definition' ) ) {
      $setting = $this->setting_definition($name);
    } else {
      $setting = (object) array('title' => $name);
    }
    if ( $this->is_duplicate_label( $name, $label ) ) {
      add_settings_error( $setting->title, $name, sprintf( __('Each status label must be unique. Choose a different value for %s', 'pdb_member_payments' ), $setting->title ) );
      return true;
    }
    return false;
  }
  
  /**
   * checks the status labels for a duplicate
   * 
   * @param string  $name of the setting
   * @param string  $label to test
   * @return bool true if the label is already in use
   */
  private function is_duplicate_label( $name, $label )
  {
    $settings = get_option( $this->settings_name() );
    $label_list = array();
    foreach( array('paid_label','due_label','past_due_label','payable_label') as $label_setting ) {
      if ( $name !== $label_setting && $settings[$label_setting] === $label ) {
        return true;
      }
    }
    return false;
  }
  
  /**
   * updates old option names to new names
   */
  private function update_option_names()
  {
    // oldname => new name
    $map = array(
        'payment_thanks_message' => 'member_payment_thanks_messsage',
        'payment_non_payment_message' => 'member_payment_cancel_return_messsage',
        'paypal_payment_thanks_message' => 'signup_payment_thanks_message',
    );
    $changed = false;
    foreach ( $map as $option_name => $new_name ) {
      if ( $this->plugin_option( $new_name, false ) === false ) {
        $this->plugin_options[$new_name] = $this->plugin_option( $option_name );
        $changed = true;
      }
    }
    if ( $changed ) {
      update_option($this->settings_name(),$this->plugin_options );
    }
  }

}
