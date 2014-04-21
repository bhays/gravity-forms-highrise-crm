=== Gravity Forms Highrise CRM ===
Contributors: benhays
Donate link: http://benjaminhays.com/gf-highrise-donate
Tags: gravity forms, gravityforms, highrise crm, highrise
Requires at least: 3.3
Tested up to: 3.9
Stable tag: 2.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Send your Gravity Forms submissions to Highrise

== Description ==

Send your Gravity Forms submissions to Highrise.

You choose which of your Gravity Forms fieds go where in Highrise. Select from three types of addresses, websites, emails and all seven types of phones (including pager!). This plugin includes any custom fields you may have setup as well.

If you'd like to include preset data into a field for Highrise, create a hidden field with the desired value in Gravity Forms.

Duplicate entries can either be skipped or added as a duplicate. This option is set via the feed for each form. Dupicates are determined by the email address being used for the contact.

== Installation ==

1. Install as a regular WordPress plugin
3. Create a form with Gravity Forms
4. Input Highrise credentials at Forms->Settings->Highrise CRM
5. Navigate to Forms->Highrise CRM to setup feeds for the desired forms

== Frequently asked questions ==

= What is Address (Full)? =

Address (Full) is the field of type Address under Advanced Fields. This field contains all the address information you'll need and makes things much simpler.

= What's the difference between a Mapped Note and Add a Note? =

A mapped note is a note that is created directly from a field on your form. Add a Note is a custom piece of text you can edit.

= How do tags work? =

Create a comma separated list of tags on your form feed, and those tags will be applied to a new contact when it's added to Highrise.

== Screenshots ==

== Changelog ==

### 2.2
* Add support for single name inputs and optionally split into first & last before sending to Highrise

### 2.1
* Fix check_update() method courtesey of David Smith

### 2.0.3
* jQuery fix for GF 1.7.7 tooltips

### 2.0.2
* Fixed changelog display errors when viewing details

### 2.0.1
* Duplicate check now works for all email types, not just work

### 2.0
* Added more fields for addresses, phones, websites and emails
* Added tags
* Added mapped notes
* Send full country name to Highrise instead of country code

### 1.2
* Added filter for pre-Highrise submission allowing you to modify any data before it's sent. Use the filter `gf_highrise_crm_pre_submission`
* Ability to add contact to a Group

### 1.1
* Make note optional on Highrise submission, allow for customization

### 1.0
* Code cleanup
* Add to WordPress Plugin Repository

### 0.6
* Added note for contact creation in Highrise
* Found a much cooler version number

### 0.1
* Initial release

== Upgrade notice ==
