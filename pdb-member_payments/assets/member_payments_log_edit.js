/**
 * UI script for working with member payment logs
 * 
 * @version 0.3
 * @author Roland Barker <webdesign@xnau.com>
 */
PDbPaymentLog = (function ($) {
  "use strict";
  var dialogOptions = {
    autoOpen : false,
    height : 'auto',
    minHeight : '20',
    title : PDb_MPs.dialog_title
  };
  var confirmationBox = $('<div id="confirmation-dialog" />');
  var deleteField = function (event) {
    var el = $(this);
    var row = el.closest('tr');
    var thing = PDb_MPs.logentry;
    var data = {
      entry_id : row.data('entryid'),
      action : PDb_MPs.action.delete,
      fieldname : el.closest('[data-fieldname]').data('fieldname'),
      record_id : el.closest('form').find('input[name=id]').val()
    };
    row.css('background-color', '#ffff99');
    confirmationBox.html(PDb_MPs.delete_confirm.replace('{thing}', thing));
    // initialize the dialog action
    confirmationBox.dialog(dialogOptions, {
      buttons : {
        "Ok" : function () {
          $(this).dialog('close');
          $.ajax({
            type : 'post',
            url : PDb_MPs.ajax_url,
            data : data,
            beforeSend : function () {
            },
            success : function (response) {
              if (response) {
                row.slideUp(600, function () { //remove the Table row .
                  row.remove();
                  $('input[type=submit][value='+PDb_MPs.apply+']').click();
                });
              }
            }
          }); // ajax
        }, // ok
        "Cancel" : function () {
          row.css('background-color', 'inherit');
          $(this).dialog('close');
        } // cancel
      } // buttons
    });// dialog
    confirmationBox.dialog('open'); //Display confirmation dialog when user clicks on "delete Image"
    return false;
  };
  var serializeList = function (container) {
    /*
     * grabs the id's of the anchor tags and puts them in a string for the 
     * ajax reorder functionality
     */
    var str = '';
    var n = 0;
    var els = container.find('a');
    for (var i = 0; i < els.length; ++i) {
      var el = els[i];
      var p = el.id.lastIndexOf('_');
      if (p != -1) {
        if (str !== '')
          str = str + '&';
        str = str + el.id + '=' + n;
        ++n;
      }
    }
    return str;
  };
  var cancelReturn = function (event) {
    // disable autocomplete
    if ($.browser.mozilla) {
      $(this).attr("autocomplete", "off");
    }
    if (event.keyCode === 13)
      return false;
  };
  return {
    serializeList : serializeList,
    init : function () {
      var logtable = $('table.'+PDb_MPs.log_field_name+'-log-table');
      $('body').append(confirmationBox);
      // set up the delete functionality
      logtable.find('.entry-control .dashicons-no').click(deleteField);
    }
  };
}(jQuery));
jQuery(function () {
  "use strict";
  PDbPaymentLog.init();
});