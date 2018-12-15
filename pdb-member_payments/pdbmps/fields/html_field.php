<?php

/**
 * this creates a generic HTML form element
 * 
 * it provides a simple way to build an arbitrary element using HTML and tag replacements
 * 
 * the basic idea is that the "values" parameter holds the actual HTML like this:
 * 
 * <div><h2>Test Element</h2></div>
 * 
 * or
 * 
 * html::<div><h2>Test Element</h2></div>
 * 
 * it will choke on commas until I get a way to enclose values, so anything like that will need to be entered as entities
 *
 * @package    WordPress
 * @subpackage Participants Database Plugin
 * @author     Roland Barker <webdesign@xnau.com>
 * @copyright  2016  xnau webdesign
 * @license    GPLv3
 * @version    0.1
 * @link       http://xnau.com/wordpress-plugins/
 * @depends    
 */

namespace pdbmps\fields;

class html_field extends pdb_custom_field {
  /**
   * intantiates the field
   * 
   * @param string $name name of the field element
   * @param string $title title of the field element
   */
  public function __construct( $title = '' )
  {
    parent::__construct( 'html', empty( $title ) ? 'HTML' : $title );
  }
  /**
   * provides the form element HTML
   * 
   * @return null
   */
  public function form_element_html()
  {
    $html = '';
    /*
     * the value can be entered bare or it can be a named element in the values array
     */
    if ( isset( $this->field->options['html'] ) ) {
      $html = $this->field->options['html'];
      unset( $this->field->options['html'] );
    } elseif ( isset( $this->field->options[0] ) ) {
      $html = $this->field->options[0];
      unset( $this->field->options[0] );
    }
    $this->field->output = $html;
  }
}
