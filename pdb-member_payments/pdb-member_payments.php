<?php

/*
 * Plugin Name: Participants Database Member Payments
 * Version: 1.2.5
 * Description: Adds a PayPal member payment button to forms; keeps a record of payments; keeps track of member payments status
 * Author: Roland Barker, xnau webdesign
 * Plugin URI: https://xnau.com/product/member-payments
 * Text Domain: pdb_member_payments
 * Domain Path: /languages
 */
spl_autoload_register( 'pdb_member_payments_autoload' );

function member_payments_initialize()
{
  global $PDb_Member_Payments;
  if ( !is_object( @$PDb_Member_Payments ) && version_compare( Participants_Db::$plugin_version, '1.7.6', '>=' ) ) {
    $PDb_Member_Payments = new \pdbmps\Plugin( __FILE__ );
  }
}

/**
 * namespace-aware autoload
 * 
 * @param string $class
 */
function pdb_member_payments_autoload( $class )
{

  $file = ltrim( str_replace( '\\', '/', $class ), '/' ) . '.php';

  if ( !class_exists( $class ) && is_file( trailingslashit( plugin_dir_path( __FILE__ ) ) . $file ) ) {
    include $file;
  }
}

/**
 * PHP version checks and notices before initializing the plugin
 */
if ( version_compare( PHP_VERSION, '5.6', '>=' ) )
{
  if ( class_exists( 'Participants_Db' ) ) {
    member_payments_initialize();
  } else {
    add_action( 'participants-database_activated', 'member_payments_initialize' );
  }
} else {

  add_action( 'admin_notices', function () {
    echo '<div class="error"><p><span class="dashicons dashicons-warning"></span>' . sprintf( __( 'Participants Database Member Payments requires PHP version %s to function properly, you have PHP version %s. Please upgrade PHP. The Plugin has been auto-deactivated.', 'participants-database' ), '5.6', PHP_VERSION ) . '</p></div>';
    if ( isset( $_GET['activate'] ) ) {
      unset( $_GET['activate'] );
    }
  } );

  add_action( 'admin_init', function () {
    deactivate_plugins( plugin_basename( __FILE__ ) );
  } );
}

// PUBLIC FUNCTIONS
function pdb_member_payments_print_payment_button()
{
  global $PDb_Member_Payments;
  $PDb_Member_Payments->print_paypal_button( false );
}

function pdb_member_payments_print_record_payment_button()
{
  global $PDb_Member_Payments;
  $PDb_Member_Payments->print_record_payment_button();
}

function pdb_member_payments_print_signup_payment_button()
{
  global $PDb_Member_Payments;
  $PDb_Member_Payments->print_paypal_button();
}

function pdb_member_payments_print_payment_button_label()
{
  global $PDb_Member_Payments;
  echo $PDb_Member_Payments->plugin_option( 'payment_button_label', '' );
}
