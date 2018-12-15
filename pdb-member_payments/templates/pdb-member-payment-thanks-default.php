<?php
/*
 * default template for the member payment thanks page
 *
 */
// $this->payment_was_made() returns true if a valid payment was made
?>
<div class="<?php echo $this->wrap_class ?> member-payment-thanks">
  
  <?php
  if ( $this->payment_was_made() ) :
    /* get_non_payment_message
     * You can also pass in a message string to override the plugin setting.
     */
    echo $this->get_thanks_message();
  else :
    echo $this->get_non_payment_message();
  endif;
  ?>
</div>