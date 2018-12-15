=== Participants Database Member Payments ===
Contributors: xnau
Requires at least: 4.1
Tested up to: 4.9.8
License: GPLv3
License URI: https://wordpress.org/about/gpl/

== Description ==
Adds a PayPal member payment button to forms; keeps a record of payments; keeps track of member payments status

== Installation ==
* Download the plugin zip file.
* Unzip the file
* Upload the resulting directory to your plugins folder (typically located at wp-content/plugins/)
* Log in to your site admin, then visit the plugins page
* Locate the plugin in the list of installed plugins, and activate.

= Configuration instructions here: [Member Payments Product Setup](https://xnau.com/product_support/member-payments/#product-setup) =

== Changelog ==

= 1.2.5 =
first payment due date now set to payment date #221
log entries oredered by payment date #220

= 1.2.4 =
improved security on member payment submissions
ignores PayPal data from other plugins

= 1.2.3 =
fixed bug preventing log entry delete
saving record in backend add blank log entry
PayPal refunds and other payment entries with value < 0 do not register as payment

= 1.2.2 =
fixed issue with field recovery on activation

= 1.2.1 =
now enforces min php version 5.6 on init (always required it)
payment log now displays correctly in admin list
several important bug fixes due to changes in Participants Database plugin
fixed next payment due date issue with offline payments

= 1.2 =
fixes issue with member payment status labels #195
updates add-on db to version 1.1, fixing any incorrect status values

= 1.15 =
adds new email trigger on manual payment log entries
new "standard values" for email template tags
payment log entry deletion now correctly updates the payment status values

= 1.14 =
first offline payment on an account now triggers the "paid" email
deleting the last log entry now correctly updates the last value fields
pending state now correctly converted with PayPal payments

= 1.13 =
better handling of offline payments
fixed template not found bug when showing "thanks" page
payment status only updated when payment confirmed complete
added translation template

= 1.12 =
profile payment form now updates record on normal submit

= 1.11 =
bug fix for the record member payments default template

= 1.10 =
offline payments now available in Member Payment and Profile Payment forms #168
added new "pending" status when offline payments are made #170
added support for payment-status-based changes to templates #167
several minor bug fixes

= 1.9 =
added support for field group tabs in the singup and record payment forms #164 #165
offline payment now goes to PDB thanks page setting or "action" shortcode attribute #161

= 1.8.1 =
additional bugfixes for read-only fields and offline payments #157

= 1.8 =
fixed bug in read-only fields in signup forms #155
next due date value correct in user feedback #110
payment select selector now default to PayPal #152

= 1.7.7 =
fixed bug that prevented the default payment type in the signup form #152

= 1.7.6 =
payment log CSV import/export implemented

= 1.7.5 =
last value fields updated on all log changes #147
image display issue on member record payment shortcode fixed #144

= 1.7.4b =
the pdb_member_payment_thanks shortcode can now be used for signup, member and offline payment modes

= 1.7.3b =
added button_html shortcode attribute
added pdb_signup_member_payment_thanks shortcode to handle signup returns

= 1.7.2b =
fixed bugs in user_status class instantiation on backend edit

= 1.7.1b =
log updates resequenced to accurately show new due date, payment status after a payment or log change
internal caching issues addressed

= 1.7b =
now only 2 payment status modes: fixed and period
period mode now has late payment handling option
payment log now includes due date field

= 1.6.9 =
added "period fixed" member payment status mode

= 1.6.8b =
several bugs fixed
PayPal log now uses test date (if enabled) when storing the log entry
manual log entries use the current date/time by default
records with no payment history use the "initial status" setting for their payment status

= 1.6.7b =
added "initial status" setting to handle records with no payment history
last value fields are updated when a log entry is deleted
increased efficiency for the "update all" operation

= 1.6.6b =
added cancel return action
delete a record's logs when the record is deleted
PayPal payment now updates the record log, last value and status fields

= 1.6.5b =
fixed payment return email functionality
thanks message corresponds to the payment module used #94
added actions for each type of payment return

= 1.6.4b =
fixed several bugs: #93 #92
added IPN receipt event for use by email template plugin #85

= 1.6.3b =
corrected namespacing error #84
select payment type default value #89
image and file uploads on signup payment and record forms #87

= 1.6.2b =
fixed bug with payment_date last value field not getting updated #78
status change events not triggered on new or previous records #70

= 1.6.1b =
improvements to payment form UI when using offline payments
payment type column in log

= 1.6b =
added offline payments to signup member payment form

= 1.5 =
default record and signup template match PDB templates
added bootstrap templates

= 1.4.9 =
PDT values now available to user thanks message

= 1.4.8 =
refactored the status fields into new classes
added next due date status field
status fields are deactivated if the member payment status feature is disabled

= 1.4.7 =
fixed some PHP 5.3 incompatibilities
date ranges now include current date
no last payment date results in "past due" status
separate settings for sandboxing

= 1.4.6 =
refactored user payment status functionality
log entries are now sorted by payment date

= 1.4.5 =
subscription status events
global event registration
new record log entry bug, now avoided with error message

= 1.4.4 =
adds subscription status feature

= 1.4.3 =
fixed log erasure bug on empty log entry submission
added tabs to settings page

= 1.4.2 =
compatibility with older jquery

= 1.4 =
added pdb_member_payment shortcode instead of using a global setting
added payment email template
button html can be defined in shortcode
manual log entry now uses field input types set in "last value" fields

= 1.3.2 =
manual log entries now also update the last value fields
added unique ID for manual log entries

= 1.3.1 =
fixed bug preventing manual log entries from getting saved

= 1.3 =
added last value fields functionality
blank fields on payment form don't erase DB data in matched record

= 1.2 =
all log data is stored in it's own table
incoming records that match the txn id are ignored, preventing duplicates

= 1.1 =
added IPN lister
added matching log entry overwrite

= 1.0 =
Public release
