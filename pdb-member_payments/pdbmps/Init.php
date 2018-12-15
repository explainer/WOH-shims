<?php

/*
 * manages plugin initialization and uninstallation
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.7
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps;

class Init {
  
  /**
   * @var string name of the cron hook
   */
  const cron_hook = 'pdbmps_cron_updates';

  /**
   * initializes the database table
   */
  public static function activate()
  {
    if (! wp_next_scheduled ( self::cron_hook ) ) {
      wp_schedule_event( strtotime( '+ 12 hours' ), apply_filters( 'pdbmps-cron_frequency', 'daily' ), self::cron_hook );
    }
    payment_log\log_field::activate();
    do_action( 'pdbmps_activate' );
  }

  /**
   * uninstall the tables/options
   */
  public static function uninstall()
  {
    global $PDb_Member_Payments;
    delete_option( $PDb_Member_Payments->settings_name() );
    delete_option( payment_log\log_field::log_field_name_option() );
    delete_option( 'pdb-member_payments_ipn_code' );
    delete_option( payment_status\update_all::saved_list );
    wp_unschedule_event( wp_next_scheduled( self::cron_hook ), self::cron_hook );
    do_action( 'pdbmps-uninstall' );
  }
  
  /**
   * deactivates the plugin
   * 
   */
  public static function deactivate()
  {
    do_action( 'pdbmps-deactivate' );
    wp_unschedule_event( wp_next_scheduled( self::cron_hook ), self::cron_hook );
    payment_log\log_field::deactivate();
    // remove the log field
    self::delete_field( get_option( payment_log\log_field::log_field_name_option() ) );
  }

  /**
   * finds a unique name for our field
   * 
   * @param string $name of the field
   * @return string unique field name
   */
  public static function unique_name( $name )
  {
    $unique_name = $name;
    $i = 1;
    while ( array_key_exists( $name, \Participants_Db::$fields ) ) {
      $unique_name = $name . '-' . $i;
      $i++;
    }
    return $unique_name;
  }

  /**
   * finds a suitable group for the payment log field
   * 
   * first tries to find a user group that is not public, then it will settle for 
   * a public group
   * 
   * @return string name of the group to use
   */
  public static function find_admin_group()
  {
    global $wpdb;
    $result = $wpdb->get_results( 'SELECT * FROM ' . \Participants_Db::$groups_table . ' WHERE `display` = "0" AND `name` <> "internal"' );
    if ( !is_object( current( $result ) ) ) {
      $result = $wpdb->get_results( 'SELECT * FROM ' . \Participants_Db::$groups_table . ' WHERE `name` <> "internal"' );
    }
    return current( $result )->name;
  }

  /**
   * removes a field from Participants Database
   * 
   * @param string  $name name of the field to delete
   */
  public static function delete_field( $name )
  {
    global $wpdb;
    $wpdb->hide_errors();

    $result = $wpdb->query( $wpdb->prepare( '
      DELETE FROM ' . \Participants_Db::$fields_table . '
      WHERE `name` = "%s"', $name )
    );
  }

}
