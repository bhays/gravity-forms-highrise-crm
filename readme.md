Gravity Forms Highrise CRM
==========================

Version 2.0.2

Send your Gravity Forms submissions to Highrise.

You choose which of your Gravity Forms fieds go where in Highrise. Select from three types of addresses, websites, emails and all seven types of phones (including pager!). This plugin includes any custom fields you may have setup as well.

If you'd like to include preset data into a field for Highrise, create a hidden field with the desired value in Gravity Forms.

Duplicate entries can either be skipped or added as a duplicate. This option is set via the feed for each form. Dupicates are determined by the email address being used for the contact.

## Requirements
* Highrise account - [Sign up for a free account](https://signup.37signals.com/highrise/Free/signup/new)
* WordPress 3.5
* PHP 5.3
* Gravity Forms 1.5 - [Get a license here](http://benjaminhays.com/gravityforms)

## Installation
1. Install as a regular WordPress plugin
3. Create a form with Gravity Forms
4. Input Highrise credentials at Forms->Settings->Highrise CRM
5. Navigate to Forms->Highrise CRM to setup feeds for the desired forms

## Changelog

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

## License
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to:

Free Software Foundation, Inc. 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.