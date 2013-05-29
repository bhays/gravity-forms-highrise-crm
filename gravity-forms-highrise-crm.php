<?php
/*
Plugin Name: Gravity Forms Highrise CRM
Plugin URI: https://github.com/bhays/gravity-forms-highrise-crm
Description: Integrates Gravity Forms with Highrise CRM allowing form submissions to be automatically sent to your Highrise account
Version: 2.0
Author: Ben Hays
Author URI: http://benhays.com

------------------------------------------------------------------------
Copyright 2013 Ben Hays

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA

*/

add_action('init',  array('GFHighriseCRM', 'init'));
register_activation_hook( __FILE__, array("GFHighriseCRM", "add_permissions"));

class GFHighriseCRM {

	private static $path = "gravity-forms-highrise-crm/gravity-forms-highrise-crm.php";
	private static $url = "http://www.gravityforms.com";
	private static $slug = "gravity-forms-highrise-crm";
	private static $version = "2.0";
	private static $min_gravityforms_version = "1.5";
	private static $supported_fields = array(
		"checkbox", "radio", "select", "text", "website", "textarea", "email",
		"hidden", "number", "phone", "multiselect", "post_title",
		"post_tags", "post_custom_field", "post_content", "post_excerpt"
	);

	//Plugin starting point. Will load appropriate files
	public static function init(){

		//supports logging
		add_filter("gform_logging_supported", array("GFHighriseCRM", "set_logging_supported"));

		if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

			//loading translations
			load_plugin_textdomain('gravity-forms-highrise-crm', FALSE, '/gravity-forms-highrise-crm/languages' );

			//force new remote request for version info on the plugin page
			//self::flush_version_info();
		}

		if(!self::is_gravityforms_supported()){
			return;
		}

		if(is_admin()){
			//loading translations
			load_plugin_textdomain('gravity-forms-highrise-crm', FALSE, '/gravity-forms-highrise-crm/languages' );

			add_filter("transient_update_plugins", array('GFHighriseCRM', 'check_update'));
			add_filter("site_transient_update_plugins", array('GFHighriseCRM', 'check_update'));

			add_action('install_plugins_pre_plugin-information', array('GFHighriseCRM', 'display_changelog'));

			//creates a new Settings page on Gravity Forms' settings screen
			if(self::has_access("gravityforms_highrise")){
				RGForms::add_settings_page("Highrise CRM", array("GFHighriseCRM", "settings_page"));
			}
		}

		//integrating with Members plugin
		if(function_exists('members_get_capabilities'))
			add_filter('members_get_capabilities', array("GFHighriseCRM", "members_get_capabilities"));

		//creates the subnav left menu
		add_filter("gform_addon_navigation", array('GFHighriseCRM', 'create_menu'));

		if(self::is_highrise_page()){

			//enqueueing sack for AJAX requests
			wp_enqueue_script(array("sack"));

			//loading data lib
			require_once(self::get_base_path() . "/inc/data.php");

			//loading Gravity Forms tooltips
			require_once(GFCommon::get_base_path() . "/tooltips.php");
			add_filter('gform_tooltips', array('GFHighriseCRM', 'tooltips'));

			//runs the setup when version changes
			self::setup();

		}
		else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

				//loading data class
				require_once(self::get_base_path() . "/inc/data.php");

				add_action('wp_ajax_rg_update_feed_active', array('GFHighriseCRM', 'update_feed_active'));
				add_action('wp_ajax_gf_select_highrise_form', array('GFHighriseCRM', 'select_highrise_form'));

			}
		else{
			//handling post submission.
			add_action("gform_after_submission", array('GFHighriseCRM', 'export'), 10, 2);
		}
	}

	public static function update_feed_active(){
		check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
		$id = $_POST["feed_id"];
		$feed = GFHighriseCRMData::get_feed($id);
		GFHighriseCRMData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
	}

	//Displays current version details on Plugin's page
	public static function display_changelog(){
		if($_REQUEST["plugin"] != self::$slug)
			return;

		RGHighriseUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
	}

	public static function check_update($update_plugins_option){
		if ( get_option( 'gf_highrise_crm_version' ) != self::$version ) {
			require_once( 'inc/data.php' );
			GFHighriseCRMData::update_table();
		}

		update_option( 'gf_highrise_crm_version', self::$version );
	}

	private static function get_key(){
		if(self::is_gravityforms_supported())
			return GFCommon::get_key();
		else
			return "";
	}
	//---------------------------------------------------------------------------------------

	//Returns true if the current page is an Feed pages. Returns false if not
	private static function is_highrise_page(){
		$current_page = trim(strtolower(rgget("page")));
		$highrise_pages = array("gf_highrise");

		return in_array($current_page, $highrise_pages);
	}

	//Creates or updates database tables. Will only run when version changes
	private static function setup(){

		if(get_option("gf_highrise_crm_version") != self::$version)
			GFHighriseCRMData::update_table();

		update_option("gf_highrise_crm_version", self::$version);
	}

	//Adds feed tooltips to the list of tooltips
	public static function tooltips($tooltips){
		$highrise_tooltips = array(
			"highrise_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-highrise-crm") . "</h6>" . __("Select the Gravity Form you would like to integrate with Highrise. Contacts generated by this form will be automatically added to your Highrise account.", "gravity-forms-highrise-crm"),
			"highrise_map_fields" => "<h6>" . __("Map Fields", "gravity-forms-highrise-crm") . "</h6>" . __("Associate your Highrise fields to the appropriate Gravity Form fields by selecting.", "gravity-forms-highrise-crm"),
			"highrise_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-highrise-crm") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to Highrise when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-highrise-crm"),
			"highrise_duplicates" => "<h6>" . __("Duplicate Entires", "gravity-forms-highrise-crm") . "</h6>" . __("When a duplicate entry for Highrise is detected, what should happen?", "gravity-forms-highrise-crm"),
			"highrise_note" => "<h6>" . __("Add a Note", "gravity-forms-highrise-crm") . "</h6>" . __("Create a custom note to be added to the contact."),
			"highrise_tags" => "<h6>" . __("Add some Tags", "gravity-forms-highrise-crm") . "</h6>" . __("Add some tags separated by commas to be added to your contact."),
			"highrise_group" => "<h6>" . __("Add to Group", "gravity-forms-highrise-crm") . "</h6>" . __("Will add the newly created contact to the Group of your choice."),
		);
		return array_merge($tooltips, $highrise_tooltips);
	}

	//Creates Highrise left nav menu under Forms
	public static function create_menu($menus){

		// Adding submenu if user has access
		$permission = self::has_access("gravityforms_highrise");
		if(!empty($permission))
			$menus[] = array("name" => "gf_highrise", "label" => __("Highrise CRM", "gravity-forms-highrise-crm"), "callback" =>  array("GFHighriseCRM", "highrise_page"), "permission" => $permission);

		return $menus;
	}

	public static function settings_page(){

		if(rgpost("uninstall")){
			check_admin_referer("uninstall", "gf_highrise_crm_uninstall");
			self::uninstall();

?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Highrise Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-highrise-crm")?></div>
            <?php
			return;
		}
		else if(rgpost("gf_highrise_crm_submit")){
				check_admin_referer("update", "gf_highrise_crm_update");

				$settings = array(
					"account" => stripslashes($_POST["gf_highrise_crm_account"]),
					"token" => stripslashes($_POST["gf_highrise_crm_token"]),
				);

				update_option("gf_highrise_crm_settings", $settings);
			}
		else{
			$settings = get_option("gf_highrise_crm_settings");
		}

		// Make sure username, password and short name are valid
		$is_valid = self::is_valid_login($settings);

		if( $is_valid['status'] ){
			$message = __("Your credentials are valid.", "gravity-forms-highrise-crm");
			$class = "valid_credentials";
		}
		else {
			$message = $is_valid['message'];
			$class = "invalid_credentials";
		}

?>
        <style>
            .valid_credentials{color:green; padding-left: 25px !important; background: url(<?php echo self::get_base_url() ?>/images/tick.png) no-repeat left 8px;}
            .invalid_credentials{color:red; padding-left: 25px !important;  background: url(<?php echo self::get_base_url() ?>/images/stop.png) no-repeat left 8px;}
        </style>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_highrise_crm_update") ?>
            <h3><?php _e("Highrise Settings Information", "gravity-forms-highrise-crm") ?></h3>

            <table class="form-table">
				<tr>
                    <td colspan="2" class="<?php echo empty($class) ? "" : $class ?>"><?php echo empty($message) ? "" : $message ?></td>
                </tr>
				<tr>
                    <th scope="row"><label for="gf_highrise_crm_account"><?php _e("Highrise Account Name", "gravity-forms-highrise-crm"); ?></label> </th>
                    <td>
                        <input type="text" id="gf_highrise_crm_account" name="gf_highrise_crm_account" value="<?php echo empty($settings["account"]) ? "" : esc_attr($settings["account"]) ?>" size="50"/><br/>
                        <?php _e('This is your subdomain, or the XXX from https://XXX.highrisehq.com when using Highrise.','gravity-forms-highrise-crm') ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_highrise_crm_token"><?php _e("API Token", "gravity-forms-highrise-crm"); ?></label> </th>
                    <td><input type="text" id="gf_highrise_crm_token" name="gf_highrise_crm_token" value="<?php echo esc_attr($settings["token"]) ?>" size="50" /><br/>
					<?php _e('Find this in Highrise under Account & settings -> My info in the tab named <em>API token</em>.','gravity-forms-highrise-crm') ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_highrise_crm_submit" class="button-primary" value="<?php _e("Save Settings", "gravity-forms-highrise-crm") ?>" /></td>
                </tr>
            </table>
        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_highrise_crm_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_highrise_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall Highrise Add-On", "gravity-forms-highrise-crm") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Highrise CRM Feeds.", "gravity-forms-highrise-crm") ?>
                    <?php
			$uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Highrise CRM Add-On", "gravity-forms-highrise-crm") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL Highrise Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-highrise-crm") . '\');"/>';
			echo apply_filters("gform_highrise_uninstall_button", $uninstall_button);
?>
                </div>
            <?php } ?>
        </form>
        <?php
	}

	public static function highrise_page(){
		$view = rgar($_GET, 'view');
		if( $view == 'edit' )
			self::edit_page($_GET['id']);
		else
			self::list_page();
	}

	//Displays the highrise feeds list page
	private static function list_page(){
		if(!self::is_gravityforms_supported()){
			die(__(sprintf("Highrise CRM Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-highrise-crm"));
		}

		if(rgpost("action") == "delete"){
			check_admin_referer("list_action", "gf_highrise_crm_type");

			$id = absint($_POST["action_argument"]);
			GFHighriseCRMData::delete_feed($id);
?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-highrise-crm") ?></div>
            <?php
		}
		else if (!empty($_POST["bulk_action"])){
				check_admin_referer("list_action", "gf_highrise_crm_type");
				$selected_feeds = $_POST["feed"];
				if(is_array($selected_feeds)){
					foreach($selected_feeds as $feed_id)
						GFHighriseCRMData::delete_feed($feed_id);
				}
?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-highrise-crm") ?></div>
            <?php
			}

?>
        <div class="wrap">

            <h2><?php _e("Highrise CRM Feeds", "gravity-forms-highrise-crm"); ?>
            <a class="add-new-h2" href="admin.php?page=gf_highrise&view=edit&id=0"><?php _e("Add New", "gravity-forms-highrise-crm") ?></a>
            </h2>
            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_highrise_crm_type') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-highrise-crm") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-highrise-crm") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-highrise-crm") ?></option>
                        </select>
                        <?php
		echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-highrise-crm") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-highrise-crm") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-highrise-crm") .'\')) { return false; } return true;"/>';
?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-highrise-crm") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Highrise CRM", "gravity-forms-highrise-crm") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-highrise-crm") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Highrise CRM", "gravity-forms-highrise-crm") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
					<?php
					$settings = GFHighriseCRMData::get_feeds();
					if(is_array($settings) && sizeof($settings) > 0):
						foreach($settings as $setting): ?>
                        <tr class='author-self status-inherit' valign="top">
                            <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                            <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-highrise-crm") : __("Inactive", "gravity-forms-highrise-crm");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-highrise-crm") : __("Inactive", "gravity-forms-highrise-crm");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                            <td class="column-title">
                                <a href="admin.php?page=gf_highrise&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-highrise-crm") ?>"><?php echo $setting["form_title"] ?></a>
                                <div class="row-actions">
                                    <span class="edit"><a href="admin.php?page=gf_highrise&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-highrise-crm") ?>"><?php _e("Edit", "gravity-forms-highrise-crm") ?></a> | </span>
                                    <span class="trash"><a title="<?php _e("Delete", "gravity-forms-highrise-crm") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-highrise-crm") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-highrise-crm") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-highrise-crm")?></a></span>
                                </div>
                            </td>
                            <td class="column-date"><?php echo $setting["meta"]["survey_name"] ?></td>
                        </tr>
                        <?php
						endforeach;
					elseif( self::get_api() ):?>
						<tr>
						    <td colspan="4" style="padding:20px;">
						        <?php _e(sprintf("You don't have any Highrise CRM feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_highrise&view=edit&id=0">', "</a>"), "gravity-forms-highrise-crm"); ?>
						    </td>
						</tr>
					<?php else: ?>
						<tr>
						    <td colspan="4" style="padding:20px;">
						        <?php _e(sprintf("To get started, please configure your %sHighrise Settings%s.", '<a href="admin.php?page=gf_settings&addon=Highrise">', "</a>"), "gravity-forms-highrise-crm"); ?>
						    </td>
						</tr>
					<?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-highrise-crm") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-highrise-crm") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-highrise-crm") ?>').attr('alt', '<?php _e("Active", "gravity-forms-highrise-crm") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-highrise-crm" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
	}

	private static function is_valid_login($settings){
		if( !class_exists('HighriseAPI') ){
			require_once('inc/HighriseAPI.php');
		}

		if( !empty($settings) ){
			extract($settings);
		}

		if( !empty($account) && !empty($token) ) {

			self::log_debug("Validating login for api token '{$token}' and account '{$account}'");
			try {
				$api = new HighriseAPI;
				$api->setAccount($account);
				$api->setToken($token);

				$test = $api->findAllUsers();

			} catch (Exception $e){

				$api_error = TRUE;
				$api_error_message = $e->getMessage();

			}

			// Set logs and return response
			if( isset($api_error) ){
				self::log_error("Login valid: false. Nothing returned from Highrise.");
				return array('status' => false, 'message' => 'Your credentials are incorrect.');
			} else {
				self::log_debug("Login valid: true");
				return array('status' => true);
			}
		}
		return array('status' => false, 'message' => "No credentials set yet.");
	}

	private static function get_api() {
		//global highrise settings
		$settings = get_option("gf_highrise_crm_settings");
		$api = null;

		if( !empty($settings) ){
			extract($settings);
		}

		if( !empty($account) && !empty($token) ) {
			if( !class_exists('HighriseAPI') ){
				require_once('inc/HighriseAPI.php');
			}
			self::log_debug("Retriving authorization code for account '{$account}' and token '{$token}'");

			$api = new HighriseAPI;
			$api->setAccount($account);
			$api->setToken($token);

		} else {
			self::log_debug("API credentials not set");
			return null;
		}

		if(!$api){
			self::log_error("Failed to set up the API");
			return null;
		} elseif (isset($auth->errorResponse)) {
			self::log_error("No response received or an error: " . $auth->errorResponse->code . " - " . $auth->errorResponse->message);
			return null;
		}

		self::log_debug("Successful API response received");

		return $api;

	}

	private static function edit_page(){
?>
        <style>
        	#wpfooter { position: relative !important; }
         	.highrise_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold;}
            .highrise_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .gfield_required{color:red;}
            p.description { margin-left: 200px !important;}
            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0;}
            #highrise_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
            .highrise_group_condition{padding-bottom:6px; padding-left:20px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <h2><?php _e("Highrise CRM Feed", "gravity-forms-highrise-crm") ?></h2>
        <?php
		//get Highrise API
		$api = self::get_api();

		//ensures valid credentials were entered in the settings page
		if(!$api){
?>
            <div><?php echo sprintf(__("We are unable to login to Highrise with the provided credentials. Please make sure they are valid in the %sSettings Page%s", "gravity-forms-highrise-crm"), "<a href='?page=gf_settings&addon=Highrise'>", "</a>"); ?></div>
            <?php
			return;
		}

		//getting setting id (0 when creating a new one)
		$id = !empty($_POST["highrise_setting_id"]) ? $_POST["highrise_setting_id"] : absint($_GET["id"]);
		$config = empty($id) ? array("meta" => array("double_optin" => true), "is_active" => true) : GFHighriseCRMData::get_feed($id);

		if(!isset($config["meta"]))
			$config["meta"] = array(
				'note'  => '',
				'group' => '',
			);

		self::log_debug('Meta: '.print_r($config['meta'], true));

		// Get details from survey if we have one
		if (rgempty("contact_type", $config["meta"]))
		{
			$merge_vars = array();
		}
		else
		{
			$details = self::get_contact_details( $config["meta"]["contact_type"] );
		}

		//updating meta information
		if(rgpost("gf_highrise_crm_submit")){
			//self::log_debug('Posting: '.print_r($_POST, true));
			$contact_type = rgpost('gf_highrise_crm_type');
			$list_name = rgpost('gf_list_name');
			$config["meta"]["contact_type"] = $contact_type;
			$config["meta"]["survey_name"] = $list_name;
			$config["form_id"] = absint($_POST["gf_highrise_crm_form"]);

			$is_valid = true;
			$details = self::get_contact_details( $config["meta"]["contact_type"] );

			$field_map = array();
			foreach($details as $k=>$v){

				//Skip titles
				if( strstr($k, 'hastitle') )
					continue;

				$field_name = "highrise_map_field_" . $k;
				$mapped_field = stripslashes($_POST[$field_name]);

				if(!empty($mapped_field)){
					$field_map[$k] = $mapped_field;
				}
				else{
					unset($field_map[$k]);
					if( isset($v['required']) ){
						$is_valid = false;
					}
				}
			}

			$config['meta']['field_map'] = $field_map;
			$config['meta']['optin_enabled'] = rgpost('highrise_optin_enable') ? true : false;
			$config['meta']['optin_field_id'] = $config['meta']['optin_enabled'] ? rgpost('highrise_optin_field_id') : '';
			$config['meta']['optin_operator'] = $config['meta']['optin_enabled'] ? rgpost('highrise_optin_operator') : '';
			$config['meta']['optin_value'] = $config['meta']['optin_enabled'] ? rgpost('highrise_optin_value') : '';
			$config['meta']['group'] = rgpost('highrise_group');
			$config['meta']['duplicates'] = rgpost('highrise_duplicates');
			$config['meta']['note'] = rgpost('highrise_note');
			$config['meta']['tags'] = rgpost('highrise_tags');
			$config['meta']['advanced_fields'] = rgpost('advanced_fields');

			if($is_valid){
				$id = GFHighriseCRMData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-highrise-crm"), "<a href='?page=gf_highrise'>", "</a>") ?></div>
                <input type="hidden" name="highrise_setting_id" value="<?php echo $id ?>"/>
                <?php
			}
			else{
?>
                <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-highrise-crm") ?></div>
                <?php
			}
		}

?>
            <form method="post" action="">
            <input type="hidden" name="highrise_setting_id" value="<?php echo $id ?>"/>
            <input type="hidden" name="gf_highrise_crm_type" value="person"/>

            <?php /* No types quite yet
            <div class="margin_vertical_10">
                <label for="gf_highrise_crm_type" class="left_header"><?php _e("Highrise Contact Type", "gravity-forms-highrise-crm"); ?> <?php gform_tooltip("highrise_crm_type") ?></label>
                    <select id="gf_highrise_crm_type" name="gf_highrise_crm_type" onchange="SelectList(jQuery(this).val());">
                        <option value="person"><?php _e("Person", "gravity-forms-highrise-crm"); ?></option>
                        <option value="company"><?php _e("Company", "gravity-forms-highrise-crm"); ?></option>
                  </select>
            </div>
            */?>
            <div id="highrise_form_container" valign="top" class="margin_vertical_10">
                <label for="gf_highrise_crm_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-highrise-crm"); ?> <?php gform_tooltip("highrise_gravity_form") ?></label>

                <select id="gf_highrise_crm_form" name="gf_highrise_crm_form" onchange="SelectForm(jQuery('#gf_highrise_crm_type').val(), jQuery(this).val());">
                <option value=""><?php _e("Select a form", "gravity-forms-highrise-crm"); ?> </option>
                <?php
		$forms = RGFormsModel::get_forms();
		foreach($forms as $form){
			$selected = absint($form->id) == rgar($config,"form_id") ? "selected='selected'" : "";
?>
                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                    <?php
		}
?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFHighriseCRM::get_base_url() ?>/images/loading.gif" id="highrise_wait" style="display: none;"/>
            </div>
            <div id="highrise_field_group" valign="top" <?php echo empty($config["meta"]["contact_type"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                <div id="highrise_field_container" valign="top" class="margin_vertical_10" >
                    <label for="highrise_fields" class="left_header"><?php _e("Map Fields", "gravity-forms-highrise-crm"); ?> <?php gform_tooltip("highrise_map_fields") ?></label>

                    <div id="highrise_field_list">
                    <?php
						if(!empty($config["form_id"])){
				
							//getting list of all Highrise details for the selected survey
							if(empty($details))
							{
								$details = self::get_contact_details($config["meta"]["contact_type"]);
							}
							//getting field map UI
							echo self::get_field_mapping($config, $config["form_id"], $details);
				
							//getting list of selection fields to be used by the optin
							$form_meta = RGFormsModel::get_form_meta($config["form_id"]);
						}
					?>
                    </div>
                </div>
				<div class="margin_vertical_10">
					<label class="left_header"><?php _e( 'Add some Tags', 'gravity-forms-highrise-crm' ); ?> <?php gform_tooltip('highrise_tags') ?></label>
	
					<div id="form_fields">
						<input type="text" name="highrise_tags" value="<?php echo isset($config['meta']['tags']) ? $config['meta']['tags'] : ''; ?>" class="regular-text"/>
					</div>
				</div>
				<div class="margin_vertical_10">
					<label class="left_header"><?php _e( 'Add a Note', 'gravity-forms-highrise-crm' ); ?> <?php gform_tooltip('highrise_note') ?></label>
	
					<div id="form_fields">
						<input type="text" name="highrise_note" value="<?php echo isset($config['meta']['note']) ? $config['meta']['note'] : ''; ?>" class="regular-text"/>
						<p class="description">Use <em>{formurl}</em> to display the form URL and <em>{ipaddress}</em> to display users IP address. If the field is empty, no note will be sent.</p>
					</div>
				</div>

                <?php $groups = $api->getGroups(); ?>
                <?php if( !empty($groups) ): ?>
                <div class="margin_vertical_10">
                	<label for="highrise_group" class="left_header"><?php _e("Add to group", "gravity-forms-highrise-crm") ?><?php gform_tooltip("highrise_group") ?></label>
                	<select name="highrise_group" id="highrise_group">
	                	<option value=""></option>
	                	<?php foreach( $groups as $k=>$v ): ?>
	                	<option value="<?php echo $k ?>" <?php if(isset($config['meta']['group']) && $config['meta']['group'] == $k): ?>selected<?php endif; ?>><?php echo $v ?></option>
	                	<?php endforeach; ?>
                	</select>
                </div>
                <?php endif; ?>

                <div id="highrise_duplicate_container" valign="top" class="margin_vertical_10">
                	<label for="highrise_duplicates" class="left_header"><?php _e("Duplicate entries?", "gravity-forms-highrise-crm") ?><?php gform_tooltip("highrise_duplicates") ?></label>
                	<select name="highrise_duplicates" id="highrise_duplicates">
                		<option value="dupe" <?php echo 'selected' ? (isset($config['meta']['duplicates']) &&$config['meta']['duplicates'] == 'dupe') : '' ?>>Add duplicate anyways, and I'll deal with it in Highrise</option>
	                	<option value="ignore" <?php echo 'selected' ? (isset($config['meta']['duplicates']) &&$config['meta']['duplicates'] == 'ignore') : '' ?>>Don't add duplicate to Highrise</option>
                	</select>
                	<p class="description"><?php _e('Duplicates are currently detected by email address only.', 'gravity-forms-highrise-crm') ?></p>
                </div>
                <?php /*
                <div id="highrise_optin_container" valign="top" class="margin_vertical_10">
                    <label for="highrise_optin" class="left_header"><?php _e("Opt-In Condition", "gravity-forms-highrise-crm"); ?> <?php gform_tooltip("highrise_optin_condition") ?></label>
                    <div id="highrise_optin">
                        <table>
                            <tr>
                                <td>
                                    <input type="checkbox" id="highrise_optin_enable" name="highrise_optin_enable" value="1" onclick="if(this.checked){jQuery('#highrise_optin_condition_field_container').show('slow');} else{jQuery('#highrise_optin_condition_field_container').hide('slow');}" <?php echo rgar($config["meta"],"optin_enabled") ? "checked='checked'" : ""?>/>
                                    <label for="highrise_optin_enable"><?php _e("Enable", "gravity-forms-highrise-crm"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="highrise_optin_condition_field_container" <?php echo !rgar($config["meta"],"optin_enabled") ? "style='display:none'" : ""?>>
                                        <div id="highrise_optin_condition_fields" style="display:none">
                                            <?php _e("Export to Highrise if ", "gravity-forms-highrise-crm") ?>
                                            <select id="highrise_optin_field_id" name="highrise_optin_field_id" class='optin_select' onchange='jQuery("#highrise_optin_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                            <select id="highrise_optin_operator" name="highrise_optin_operator" >
                                                <option value="is" <?php echo rgar($config["meta"], "optin_operator") == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-highrise-crm") ?></option>
                                                <option value="isnot" <?php echo rgar($config["meta"], "optin_operator") == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-highrise-crm") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'optin_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravity-forms-highrise-crm") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'optin_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravity-forms-highrise-crm") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'optin_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravity-forms-highrise-crm") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'optin_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravity-forms-highrise-crm") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'optin_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravity-forms-highrise-crm") ?></option>
                                            </select>
                                            <div id="highrise_optin_value_container" name="highrise_optin_value_container" style="display:inline;"></div>
                                        </div>
                                        <div id="highrise_optin_condition_message" style="display:none">
                                            <?php _e("To create an Opt-In condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                    */?>
                    <?php if(!empty($config["form_id"])): ?>
                    <script type="text/javascript">
                            //creating Javascript form object
                            form = <?php echo GFCommon::json_encode($form_meta)?> ;

                            //initializing drop downs
                            jQuery(document).ready(function(){
                                var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
                                var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
                                SetOptin(selectedField, selectedValue);
                            });
					</script>
					<?php endif; ?>
                </div>

                <div id="highrise_submit_container" class="margin_vertical_10">
                    <input type="submit" name="gf_highrise_crm_submit" value="<?php echo empty($id) ? __("Save", "gravity-forms-highrise-crm") : __("Update", "gravity-forms-highrise-crm"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravity-forms-highrise-crm"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_highrise'" />
                </div>
            </div>
        </form>
        </div>
        <script type="text/javascript">
            function SelectList(listId){
                if(listId){
                    jQuery("#highrise_form_container").slideDown();
                    jQuery("#gf_highrise_crm_form").val("");
                }
                else{
                    jQuery("#highrise_form_container").slideUp();
                    EndSelectForm("");
                }
            }

            function SelectForm(listId, formId){
                if(!formId){
                    jQuery("#highrise_field_group").slideUp();
                    return;
                }

                jQuery("#highrise_wait").show();
                jQuery("#highrise_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_highrise_form" );
                mysack.setVar( "gf_select_highrise_form", "<?php echo wp_create_nonce("gf_select_highrise_form") ?>" );
                mysack.setVar( "contact_type", listId);
                mysack.setVar( "form_id", formId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#highrise_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-highrise-crm") ?>' )};
                mysack.runAJAX();
				
                return true;
            }

            function SetOptin(selectedField, selectedValue){

                //load form fields
                jQuery("#highrise_optin_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#highrise_optin_field_id").val();

                if(optinConditionField){
                    jQuery("#highrise_optin_condition_message").hide();
                    jQuery("#highrise_optin_condition_fields").show();
                    jQuery("#highrise_optin_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#highrise_optin_value").val(selectedValue);
                }
                else{
                    jQuery("#highrise_optin_condition_message").show();
                    jQuery("#highrise_optin_condition_fields").hide();
                }
            }

            function EndSelectForm(fieldList, form_meta, grouping, groups){
                //setting global form object
                form = form_meta;
                if(fieldList){

                    SetOptin("","");

                    jQuery("#highrise_field_list").html(fieldList);
                    jQuery("#highrise_groupings").html(grouping);

                    for(var i in groups)
                        SetGroupCondition(groups[i]["main"], groups[i]["sub"],"","");

                    //initializing highrise group tooltip
                    jQuery('.tooltip_highrise_groups').qtip({
                         content: jQuery('.tooltip_highrise_groups').attr('tooltip'), // Use the tooltip attribute of the element for the content
                         show: { delay: 500, solo: true },
                         hide: { when: 'mouseout', fixed: true, delay: 200, effect: 'fade' },
                         style: "gformsstyle",
                         position: {
                          corner: {
                               target: "topRight",
                               tooltip: "bottomLeft"
                               }
                          }
                      });

                    jQuery("#highrise_field_group").slideDown();

                }
                else{
                    jQuery("#highrise_field_group").slideUp();
                    jQuery("#highrise_field_list").html("");
                }
                jQuery("#highrise_wait").hide();
            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters, inputName){
                if(!inputName){
                    inputName = 'highrise_optin_value';
                }

                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
					str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "highrise_optin_value", "name"=> "highrise_optin_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
				}
				else if(field.choices){
					str += '<select id="' + inputName +'" name="' + inputName +'" class="optin_select">';

	                for(var i=0; i<field.choices.length; i++){
	                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
	                    var isSelected = fieldValue == selectedValue;
	                    var selected = isSelected ? "selected='selected'" : "";
	                    if(isSelected)
	                        isAnySelected = true;

	                    str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
	                }

	                if(!isAnySelected && selectedValue){
	                    str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
	                }
	            	str += "</select>";
				}
				else
				{
					selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
					//create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
					str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='" + inputName + "' name='" + inputName +"' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
				}

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;

                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
			    inputType = field.inputType ? field.inputType : field.type;
			    var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea",
			    "email", "hidden", "number", "phone", "multiselect", "post_title",
			                            "post_tags", "post_custom_field", "post_content", "post_excerpt"];

			    var index = jQuery.inArray(inputType, supported_fields);

			    return index >= 0;
			}
        </script>
        <?php
	}

	public static function add_permissions(){
		global $wp_roles;
		$wp_roles->add_cap("administrator", "gravityforms_highrise");
		$wp_roles->add_cap("administrator", "gravityforms_highrise_uninstall");
	}

	public static function selected($selected, $current){
		return $selected === $current ? " selected='selected'" : "";
	}

	//Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
	public static function members_get_capabilities( $caps ) {
		return array_merge($caps, array("gravityforms_highrise", "gravityforms_highrise_uninstall"));
	}

	public static function disable_highrise(){
		delete_option("gf_highrise_crm_settings");
	}

	public static function select_highrise_form(){

		check_ajax_referer("gf_select_highrise_form", "gf_select_highrise_form");
		$form_id =  intval(rgpost("form_id"));
		self::log_debug('Selcting form with: '.rgpost('contact_type'));

		$contact_type = rgpost('contact_type');
		$setting_id =  intval(rgpost("setting_id"));

		$api = self::get_api();
		if(!$api)
			die("EndSelectForm();");

		//getting list of all Highrise details for the selected contact list
		//self::log_debug('Contact type: '.$contact_type);
		$details = self::get_contact_details($contact_type);

		//getting configuration
		$config = GFHighriseCRMData::get_feed($setting_id);

		//getting field map UI
		$field_map = self::get_field_mapping($config, $form_id, $details);

		// Escape quotes and strip extra whitespace and line breaks
		$field_map = str_replace("'","\'",$field_map);
		//self::log_debug("Field map is set to: " . $field_map);

		//getting list of selection fields to be used by the optin
		$form_meta = RGFormsModel::get_form_meta($form_id);
		$selection_fields = GFCommon::get_selection_fields($form_meta, rgars($config, "meta/optin_field_id"));
		$group_condition = array();
		$group_names = array();
		$grouping = '';

		//fields meta
		$form = RGFormsModel::get_form_meta($form_id);
		die("EndSelectForm('".$field_map."', ".GFCommon::json_encode($form).", '" . str_replace("'", "\'", $grouping) . "', " . json_encode($group_names) . " );");
	}

	private static function get_field_mapping($config, $form_id, $details){

		//getting list of all fields for the selected form
		$form_fields = self::get_form_fields($form_id);

		$str = "<table cellpadding='0' cellspacing='0'><tr><td class='highrise_col_heading'>" . __("Highrise Fields", "gravity-forms-highrise-crm") . "</td><td class='highrise_col_heading'>" . __("Form Fields", "gravity-forms-highrise-crm") . "</td></tr>";

		if(!isset($config["meta"]))
			$config["meta"] = array("field_map" => "");

		foreach( $details as $k=>$v ){
			if( strstr($k, 'hastitle') )
			{
				// Display titles
				if( is_array($v) )
				{
					if( $v['type'] == 'checkbox' )
					{
						$checked = !empty($config['meta']['advanced_fields']) ? 'checked="checked"' : '';
						$str .= '<tr><td class="highrise_field_cell">Advanced Fields</td><td class="highrise_field_cell"><label><input type="checkbox" name="advanced_fields" id="advanced_fields" '.$checked.'/> More fields please...</label></td></tr>';
					}
					$str .= "<tr class='".$v['class']."'><td colspan='2'><h4>".$v['name']."</h4></td></tr>";
				}
				else
				{
					$str .= "<tr><td colspan='2'><h4>".$v."</h4></td></tr>";
				}
			}
			else
			{
				$selected_field = rgar($config["meta"]["field_map"], $k);
				$required = isset($v['required']) ? "<span class='gfield_required'>*</span>" : '';
				$error_class = isset($v['required']) && empty($selected_field) && !empty($_POST["gf_highrise_crm_submit"]) ? " feeds_validation_error" : "";
				$row_class = isset($v['class']) ? $v['class'] : "";
				$str .= "<tr class='$error_class $row_class'><td class='highrise_field_cell'>".self::ws_clean($v['name'])." $required</td><td class='highrise_field_cell'>".self::get_mapped_field_list($k, $selected_field, $form_fields)."</td></tr>";
			}
		}

		$str .= "</table>";

		return $str;
	}

	public static function get_form_fields($form_id){
		$form = RGFormsModel::get_form_meta($form_id);
		$fields = array();

		//Adding default fields
		array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravity-forms-highrise-crm")));
		array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravity-forms-highrise-crm")));
		array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravity-forms-highrise-crm")));
		array_push($form["fields"],array("id" => "form_title" , "label" => __("Form Title", "gravity-forms-highrise-crm")));
		$form = self::get_entry_meta($form);
		if(is_array($form["fields"])){
			foreach($form["fields"] as $field){
				if(is_array(rgar($field, "inputs"))){

					//If this is an address field, add full name to the list
					if(RGFormsModel::get_input_type($field) == "address")
						$fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravity-forms-highrise-crm") . ")");

					foreach($field["inputs"] as $input)
						$fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
				}
				else if(!rgar($field,"displayOnly")){
						$fields[] =  array($field["id"], GFCommon::get_label($field));
					}
			}
		}
		return $fields;
	}

	private static function get_entry_meta($form){
		$entry_meta = GFFormsModel::get_entry_meta($form["id"]);
		$keys = array_keys($entry_meta);
		foreach ($keys as $key){
			array_push($form["fields"],array("id" => $key , "label" => $entry_meta[$key]['label']));
		}
		return $form;
	}

	private static function get_address($entry, $field_id, $return='string'){
		$street_value     = str_replace("  ", " ", trim($entry[$field_id.".1"]));
		$street2_value    = str_replace("  ", " ", trim($entry[$field_id.".2"]));
		$city_value       = str_replace("  ", " ", trim($entry[$field_id.".3"]));
		$state_value      = str_replace("  ", " ", trim($entry[$field_id.".4"]));
		$zip_value        = trim($entry[$field_id . ".5"]);
		$country_value    = trim($entry[$field_id . ".6"]);
		
		if( $return == 'array' )
		{
			$address = array(
				'street'    => $street_value.' '.$street2_value,
				'city'      => $city_value,
				'state'     => $state_value,
				'zip'       => $zip_value,
				'country'   => $country_value
			);
		}
		else
		{
			$address = $street_value;
			$address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
			$address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
			$address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
			$address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
			$address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;
		}

		return $address;
	}

	public static function get_mapped_field_list($variable_name, $selected_field, $fields){
		$field_name = "highrise_map_field_" . $variable_name;
		$str = "<select name='$field_name' id='$field_name'><option value=''></option>";
		foreach($fields as $field){
			$field_id = $field[0];
			$field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

			$selected = $field_id == $selected_field ? "selected='selected'" : "";
			$str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
		}
		$str .= "</select>";
		return $str;
	}

	public static function export($entry, $form, $is_fulfilled = false){

		//Login to Highrise
		$api = self::get_api();
		if(!$api)
			return;

		//loading data class
		require_once(self::get_base_path() . "/inc/data.php");

		//getting all active feeds
		$feeds = GFHighriseCRMData::get_feed_by_form($form["id"], true);
		foreach($feeds as $feed){
			//only export if user has opted in
			if(self::is_optin($form, $feed, $entry))
			{
				self::export_feed($entry, $form, $feed, $api);
				//updating meta to indicate this entry has already been subscribed to Highrise. This will be used to prevent duplicate subscriptions.
				self::log_debug("Marking entry " . $entry["id"] . " as fed");
				gform_update_meta($entry["id"], "highrise_is_subscribed", true);
			}
			else
			{
				self::log_debug("Opt-in condition not met; not subscribing entry " . $entry["id"] . " to list");
			}
		}
	}

	public static function has_highrise($form_id){
		if(!class_exists("GFHighriseCRMData"))
			require_once(self::get_base_path() . "/inc/data.php");

		// Getting settings associated with this form
		$config = GFHighriseCRMData::get_feed_by_form($form_id);

		if(!$config)
			return false;

		return true;
	}

	// Magic goes here
	public static function export_feed($entry, $form, $feed, $api){

		// Build parameter list of questions and values
		$params = array(
			'contact_type' => $feed['meta']['contact_type'],
		);

		foreach( $feed['meta']['field_map'] as $k => $v )
		{
			if($v == intval($v) && strpos($k, 'address') !== false )
			{
				//handling full address
				$params[$k] = self::get_address($entry, $v, 'array');
			}
			else {
				$params[$k] = apply_filters("gform_highrise_field_value", rgar($entry, $v), $form['id'], $v, $entry);
			}
		}

		//self::log_debug('Params: '.print_r($params, true));
		//self::log_debug('Entry: '.print_r($entry, true));
		//self::log_debug('Feed: '.print_r($feed, true));
		
		$params = apply_filters('gf_highrise_crm_pre_submission', $params);
		
		//self::log_debug('Params post filter: '.print_r($params, true));

		// Send info to Highrise
		if( !empty($params) )
		{
			// Set date for create or update
			$current_time = date('Y-m-d').'T'.date('h:i:s').'Z';

			$person = new HighrisePerson($api);
			$person->setCreatedAt($current_time);

			// Check for duplicate
			if( !empty($params['email']) )
			{
				$user = $person->findPeopleByEmail($params['email']);
				if( !empty($user) && array_key_exists(0, $user) )
				{
					// Found duplicate, what should we do?
					if( $feed['meta']['duplicates'] == 'ignore' )
					{
						self::log_debug('Duplicate ignored and not sent to Highrise');
						return false;
					}
					//$user = $user[0];
					//$person->setId($user->id);
					//$person->setUpdatedAt($current_time);
				}
			}

			foreach( $params as $field => $value )
			{
				// Default location
				$location = 'work';

				// Check for locations appended to field
				if( strpos($field, ':') )
				{
					list($field, $location) = explode(':', $field);
				}
				
				// There's probably a better way to do this
				if( strpos($field, 'address') !== false )
				{
					$address = new HighriseAddress();

					if( isset($value['street']) )
						$address->setStreet($value['street']);

					if( isset($value['city']) )
						$address->setCity($value['city']);

					if( isset($value['state']) )
						$address->setState($value['state']);

					if( isset($value['zip']) )
						$address->setZip($value['zip']);

					if( isset($value['country']) )
						$address->setCountry($value['country']);

					$address->setLocation($location);
					$person->addAddress($address);
					
				}
				elseif( strpos($field, 'phone') !== false )
				{
					$person->addPhoneNumber($value, $location);					
				}
				elseif( strpos($field, 'email') !== false )
				{
					$person->addEmailAddress($value, $location);
				}
				elseif( strpos($field, 'website') !== false )
				{
					$person->addWebAddress($value, $location);
				}
				else
				{
					switch( $field )
					{
						case 'name_first':
							$person->setFirstName($value);
							break;
						case 'name_last':
							$person->setLastName($value);
							break;
						case 'company':
							$person->setCompanyName($value);
							break;
						case 'title':
							$person->setTitle($value);
							break;
						case 'twitter':
							$person->addTwitterAccount($value);
							break;
						case 'note_1': case 'note_2': case 'note_3':
							// Note needs to be inserted after people creation, so save for later
							$custom_note[] = $value;
							break;
						// These should all be custom fields
						default:
							if( is_int($field) )
							{
								$person->addCustomField($field, $value);
							}
					}
				}
			}
			
			// Set group if there is one
			if( !empty($feed['meta']['group']) )
			{
				$person->setVisibleTo('NamedGroup');
				$person->setGroupId($feed['meta']['group']);
			}
			
			// Add tags if there are any
			if( !empty($feed['meta']['tags']) )
			{
				$tags = explode(',', $feed['meta']['tags']);
				foreach( $tags as $t )
				{
					$person->addTag(trim($t));
				}
			}
			
			try
			{
				// Add contact to Highrise
				$person->save();
				$id = $person->getId();
				
				// Highrise was successful, let's make a note
				if( !empty($feed['meta']['note']) )
				{	
					$note_txt = $feed['meta']['note'];
					$note_txt = str_replace('{ipaddress}', $entry['ip'], $note_txt);
					$note_txt = str_replace('{formurl}', $entry['source_url'], $note_txt);

					$note = new HighriseNote($api);
					$note->setSubjectType("Party");
					$note->setSubjectId($id);
					$note->setBody($note_txt);
					$note->save();					
				}
				
				// Add custom note
				if( isset($custom_note) && is_array($custom_note) )
				{
					foreach( $custom_note as $n )
					{
						$note = new HighriseNote($api);
						$note->setSubjectType("Party");
						$note->setSubjectId($id);
						$note->setBody($n);
						$note->save();
					}
				}

				self::log_debug("Created contact on Highrise - ID: ".$person->getId());
			}
			catch (Exception $e)
			{
				self::log_error("Error from Highrise: ".$e->getMessage());
			}
		}
	}

	public static function uninstall(){

		//loading data lib
		require_once(self::get_base_path() . "/inc/data.php");

		if(!GFHighriseCRM::has_access("gravityforms_highrise_uninstall"))
			die(__("You don't have adequate permission to uninstall Highrise Add-On.", "gravity-forms-highrise-crm"));

		//droping all tables
		GFHighriseCRMData::drop_tables();

		//removing options
		delete_option("gf_highrise_crm_crm_settings");
		delete_option("gf_highrise_crm_crm_version");

		//Deactivating plugin
		$plugin = "gravity-forms-highrise-crm/highrise.php";
		deactivate_plugins($plugin);
		update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
	}

	public static function is_optin($form, $settings, $entry){
		$config = $settings["meta"];

		$field = RGFormsModel::get_field($form, $config["optin_field_id"]);

		if(empty($field) || !$config["optin_enabled"])
			return true;

		$operator = isset($config["optin_operator"]) ? $config["optin_operator"] : "";
		$field_value = RGFormsModel::get_field_value($field, array());
		$is_value_match = RGFormsModel::is_value_match($field_value, $config["optin_value"], $operator);
		$is_visible = !RGFormsModel::is_field_hidden($form, $field, array(), $entry);

		$is_optin = $is_value_match && $is_visible;

		return $is_optin;

	}

	private static function get_contact_details( $type )
	{
		$fields = array();
		$cf = array();

		if( $type == 'company' )
		{
			$fields['company'] = array('name'=>'Company', 'required'=>TRUE);

		} else {

			$fields['name_first'] = array('name' => 'First Name', 'required'=>TRUE);
			$fields['name_last']  = array('name' => 'Last Name', 'required'=>TRUE);
			$fields['company']    = array('name' => 'Company');
			$fields['title']      = array('name' => 'Title');
		}

		$generic_fields = array(
			'address'            => array('name' => 'Work Address (Full)'),
			'address:home'       => array('name' => 'Home Address (Full)'),
			'address:other'      => array('name' => 'Other Address (Full)'),
			'phone'              => array('name' => 'Phone (work)'),
			'phone:home'         => array('name' => 'Phone (home)'),
			'phone:mobile'       => array('name' => 'Phone (mobile)'),
			'phone:fax'          => array('name' => 'Phone (fax)'),
			'phone:pager'        => array('name' => 'Phone (pager)'),
			'phone:skype'        => array('name' => 'Phone (skype)'),
			'phone:other'        => array('name' => 'Phone (other)'),
			'email'              => array('name' => 'Email (work)'),
			'email:home'         => array('name' => 'Email (home)'),
			'email:other'        => array('name' => 'Email (other)'), 			
			'website'            => array('name' => 'Website (work)'),
			'website:personal'   => array('name' => 'Website (personal)'),
			'website:other'      => array('name' => 'Website (other)'), 			
			'twitter'            => array('name' => 'Twitter'),
		);
		
		$advanced_fields = array(
		);

		$api = self::get_api();

		// Add custom fields
		$custom_fields = $api->getCustomFields();

		if( !empty($custom_fields) )
		{
			$cf['hastitle_custom'] = "Custom Fields";
			foreach( $custom_fields as $k=>$v )
			{
				$cf["$k"] = array('name' => $v);
			}
		}
		
		// Add note and tags
		$eratta = array(
			'hastitle_errata' => "Mapped Notes",
			'note_1' => array('name'=>'Note One'),
			'note_2' => array('name'=>'Note Two'),
			'note_3' => array('name'=>'Note Three'),
		);
		
		return $fields + $generic_fields + $advanced_fields + $cf + $eratta;
	}

	private static function is_gravityforms_installed(){
		return class_exists("RGForms");
	}

	private static function is_gravityforms_supported(){
		if(class_exists("GFCommon")){
			$is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
			return $is_correct_version;
		}
		else{
			return false;
		}
	}

	protected static function has_access($required_permission){
		$has_members_plugin = function_exists('members_get_capabilities');
		$has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
		if($has_access)
			return $has_members_plugin ? $required_permission : "level_7";
		else
			return false;
	}

	// Clean strings from Highrise, we don't need any HTML or line breaks
	protected function ws_clean($string){
		$chars = array("
", "\n", "\r", "chr(13)",  "\t", "\0", "\x0B");
		$string = str_replace($chars, '', trim(strip_tags($string)));
		return $string;
	}

	//Returns the url of the plugin's root folder
	protected function get_base_url(){
		return plugins_url(null, __FILE__);
	}

	//Returns the physical path of the plugin's root folder
	protected function get_base_path(){
		$folder = basename(dirname(__FILE__));
		return WP_PLUGIN_DIR . "/" . $folder;
	}

	function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "Highrise CRM";
		return $plugins;
	}

	private static function log_error($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
		}
	}

	private static function log_debug($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
		}
	}
}

if(!function_exists("rgget")){
	function rgget($name, $array=null){
		if(!isset($array))
			$array = $_GET;

		if(isset($array[$name]))
			return $array[$name];

		return "";
	}
}

if(!function_exists("rgpost")){
	function rgpost($name, $do_stripslashes=true){
		if(isset($_POST[$name]))
			return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

		return "";
	}
}

if(!function_exists("rgar")){
	function rgar($array, $name){
		if(isset($array[$name]))
			return $array[$name];

		return '';
	}
}

if(!function_exists("rgempty")){
	function rgempty($name, $array = null){
		if(!$array)
			$array = $_POST;

		$val = rgget($name, $array);
		return empty($val);
	}
}

if(!function_exists("rgblank")){
	function rgblank($text){
		return empty($text) && strval($text) != "0";
	}
}

?>