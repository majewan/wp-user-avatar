<?php
/**
 * @package WP User Avatar
 * @version 1.5
 */
/*
Plugin Name: WP User Avatar
Plugin URI: http://wordpress.org/extend/plugins/wp-user-avatar/
Description: Use any image in your WordPress Media Libary as a custom user avatar. Add your own Default Avatar.
Version: 1.5
Author: Bangbay Siboliban
Author URI: http://siboliban.org/
*/

if(!defined('ABSPATH')){
  die('You are not allowed to call this page directly.');
  @header('Content-Type:'.get_option('html_type').';charset='.get_option('blog_charset'));
}

// Define paths and variables
define('WPUA_VERSION', '1.5');
define('WPUA_FOLDER', basename(dirname(__FILE__)));
define('WPUA_ABSPATH', trailingslashit(str_replace('\\', '/', WP_PLUGIN_DIR.'/'.WPUA_FOLDER)));
define('WPUA_URLPATH', trailingslashit(plugins_url(WPUA_FOLDER)));

// Define global variables
$avatar_default = get_option('avatar_default');
$wpua_avatar_default = get_option('avatar_default_wp_user_avatar');
$show_avatars = get_option('show_avatars');
$wpua_tinymce = get_option('wp_user_avatar_tinymce');
$wpua_allow_upload = get_option('wp_user_avatar_allow_upload');
$mustache_original = WPUA_URLPATH.'images/wp-user-avatar.png';
$mustache_medium = WPUA_URLPATH.'images/wp-user-avatar-300x300.png';
$mustache_thumbnail = WPUA_URLPATH.'images/wp-user-avatar-150x150.png';
$mustache_avatar = WPUA_URLPATH.'images/wp-user-avatar-96x96.png';
$mustache_admin = WPUA_URLPATH.'images/wp-user-avatar-32x32.png';
$ssl = is_ssl() ? 's' : "";

// Check for updates
$wpua_default_avatar_updated = get_option('wp_user_avatar_default_avatar_updated');
$wpua_users_updated = get_option('wp_user_avatar_users_updated');
$wpua_media_updated = get_option('wp_user_avatar_media_updated');

// Server upload size limit
$upload_size_limit = wp_max_upload_size();
// Convert to KB
if($upload_size_limit > 1024){
  $upload_size_limit /= 1024;
}
$upload_size_limit_with_units = (int) $upload_size_limit.'KB';

// User upload size limit
$wpua_user_upload_size_limit = get_option('wp_user_avatar_upload_size_limit');
if($wpua_user_upload_size_limit == 0 || $wpua_user_upload_size_limit > wp_max_upload_size()){
  $wpua_user_upload_size_limit = wp_max_upload_size();
}
// Value in bytes
$wpua_upload_size_limit = $wpua_user_upload_size_limit;
// Convert to KB
if($wpua_user_upload_size_limit > 1024){
  $wpua_user_upload_size_limit /= 1024;
}
$wpua_upload_size_limit_with_units = (int) $wpua_user_upload_size_limit.'KB';

// Load add-ons
if($wpua_tinymce == 1){
  include_once(WPUA_ABSPATH.'includes/tinymce.php');
}

// Initialize default settings
register_activation_hook(WPUA_ABSPATH.'wp-user-avatar.php', 'wpua_options');

// Remove subscribers edit_posts capability
register_deactivation_hook(WPUA_ABSPATH.'wp-user-avatar.php', 'wpua_deactivate');

// Settings saved to wp_options
function wpua_options(){
  global $wp_user_roles;
  add_option('avatar_default_wp_user_avatar', "");
  add_option('wp_user_avatar_tinymce', '1');
  add_option('wp_user_avatar_allow_upload', '0');
  add_option('wp_user_avatar_disable_gravatar', '0');
  add_option('wp_user_avatar_upload_size_limit', '0');
}
add_action('admin_init', 'wpua_options');

// Update default avatar to new format
if(empty($wpua_default_avatar_updated)){
  function wpua_default_avatar(){
    global $avatar_default, $wpua_avatar_default, $mustache_original;
    // If default avatar is the old mustache URL, update it
    if($avatar_default == $mustache_original){
      update_option('avatar_default', 'wp_user_avatar');
    }
    // If user had an image URL as the default avatar, replace with ID instead
    if(!empty($wpua_avatar_default)){
      $wpua_avatar_default_image = wp_get_attachment_image_src($wpua_avatar_default, 'medium');
      if($avatar_default == $wpua_avatar_default_image[0]){
        update_option('avatar_default', 'wp_user_avatar');
      }
    }
    update_option('wp_user_avatar_default_avatar_updated', '1');
  }
  add_action('admin_init', 'wpua_default_avatar');
}

// Rename user meta to match database settings
if(empty($wpua_users_updated)){
  function wpua_user_meta(){
    global $wpdb, $blog_id;
    $wpua_metakey = $wpdb->get_blog_prefix($blog_id).'user_avatar';
    // If database tables start with something other than wp_
    if($wpua_metakey != 'wp_user_avatar'){
      $users = get_users();
      // Move current user metakeys to new metakeys
      foreach($users as $user){
        $wpua = get_user_meta($user->ID, 'wp_user_avatar', true);
        if(!empty($wpua)){
          update_user_meta($user->ID, $wpua_metakey, $wpua);
          delete_user_meta($user->ID, 'wp_user_avatar');
        }
      }
    }
    update_option('wp_user_avatar_users_updated', '1'); 
  }
  add_action('admin_init', 'wpua_user_meta');
}

// Add media state to existing avatars
if(empty($wpua_media_updated)){
  function wpua_media_state(){
    global $wpdb, $blog_id;
    // Find all users with WPUA
    $wpua_metakey = $wpdb->get_blog_prefix($blog_id).'user_avatar';
    $wpuas = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->usermeta WHERE meta_key = %s AND meta_value != %d AND meta_value != %d", $wpua_metakey, 0, ""));
    foreach($wpuas as $usermeta){
      add_post_meta($usermeta->meta_value, '_wp_attachment_wp_user_avatar', $usermeta->user_id);
    }
    update_option('wp_user_avatar_media_updated', '1'); 
  }
  add_action('admin_init', 'wpua_media_state');
}

// Settings for Subscribers
if($wpua_allow_upload == 1){
  // Allow multipart data in form
  function wpua_add_edit_form_multipart_encoding(){
    echo ' enctype="multipart/form-data"';
  }
  add_action('user_edit_form_tag', 'wpua_add_edit_form_multipart_encoding');

  // Check user role
  function check_user_role($role, $user_id=null){
    global $current_user;
    if(is_numeric($user_id)){
      $user = get_userdata($user_id);
    } else {
      $user = $current_user->ID;
    }
    if(empty($user)){
      return false;
    }
    return in_array($role, (array) $user->roles);
  }

  // Give subscribers edit_posts capability
  function wpua_subscriber_add_cap(){
    global $wpdb, $blog_id;
    $wp_user_roles = $wpdb->get_blog_prefix($blog_id).'user_roles';
    $user_roles = get_option($wp_user_roles);
    $user_roles['subscriber']['capabilities']['edit_posts'] = true;
    update_option($wp_user_roles, $user_roles);
  }
  add_action('admin_init', 'wpua_subscriber_add_cap');

  // Remove menu items
  function wpua_subscriber_remove_menu_pages(){
    global $current_user;
    if(check_user_role('subscriber', $current_user->ID)){
      remove_menu_page('edit.php');
      remove_menu_page('edit-comments.php');
      remove_menu_page('tools.php');
    }
  }
  add_action('admin_menu', 'wpua_subscriber_remove_menu_pages');

  // Remove dashboard items
  function wpua_subscriber_remove_dashboard_widgets(){
    global $current_user;
    if(check_user_role('subscriber', $current_user->ID)){
      remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
      remove_meta_box('dashboard_recent_drafts', 'dashboard', 'side');
      remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
    }
  }
  add_action('wp_dashboard_setup', 'wpua_subscriber_remove_dashboard_widgets');

  // Restrict access to pages
  function wpua_subscriber_offlimits(){
    global $current_user, $pagenow;
    $offlimits = array('edit.php', 'post-new.php', 'edit-comments.php', 'tools.php');
    if(check_user_role('subscriber', $current_user->ID)){
      if(in_array($pagenow, $offlimits)){
        do_action('admin_page_access_denied');
        wp_die(__('You do not have sufficient permissions to access this page.'));
      }
    }
  }
  add_action('admin_init', 'wpua_subscriber_offlimits');
}

// Remove subscribers edit_posts capability
function wpua_subscriber_remove_cap(){
  global $wpdb, $blog_id;
  $wp_user_roles = $wpdb->get_blog_prefix($blog_id).'user_roles';
  $user_roles = get_option($wp_user_roles);
  unset($user_roles['subscriber']['capabilities']['edit_posts']);
  update_option($wp_user_roles, $user_roles);
}

// On deactivation
function wpua_deactivate(){
  // Remove subscribers edit_posts capability
  wpua_subscriber_remove_cap();
  // Reset all default avatar to Mystery Man
  update_option('avatar_default', 'mystery');
}

// WP User Avatar
if(!class_exists('wp_user_avatar')){
  class wp_user_avatar{
    function wp_user_avatar(){
      global $current_user, $current_screen, $show_avatars, $wpua_allow_upload, $pagenow;
      // Adds WPUA to profile
      if(current_user_can('upload_files') || ($wpua_allow_upload == 1 && is_user_logged_in())){
        add_action('show_user_profile', array('wp_user_avatar', 'wpua_action_show_user_profile'));
        add_action('edit_user_profile', array($this, 'wpua_action_show_user_profile'));
        add_action('personal_options_update', array($this, 'wpua_action_process_option_update'));
        add_action('edit_user_profile_update', array($this, 'wpua_action_process_option_update'));
        if(is_admin()){
          // Adds scripts to admin
          add_action('admin_enqueue_scripts', array($this, 'wpua_media_upload_scripts'));
          // Admin settings
          add_action('admin_menu', 'wpua_admin');
          add_filter('plugin_action_links', array($this, 'wpua_plugin_settings_links'), 10, 2);
        } else {
          if(!function_exists('get_current_screen')){
            require_once(ABSPATH.'wp-admin/includes/screen.php');
          }
          // Adds scripts to front pages
          add_action('wp_enqueue_scripts', array($this, 'wpua_media_upload_scripts'));
        }
        // Only add attachment field for WP 3.4 and older
        if(!function_exists('wp_enqueue_media') && $pagenow == 'media-upload.php'){
          add_filter('attachment_fields_to_edit', array($this, 'wpua_add_attachment_field_to_edit'), 10, 2); 
        }
        // Hide column in Users table if default avatars are enabled
        if(is_admin() && $show_avatars != 1){
          add_filter('manage_users_columns', array($this, 'wpua_add_column'), 10, 1);
          add_filter('manage_users_custom_column', array($this, 'wpua_show_column'), 10, 3);
        }
      }
    }

    // Add to edit user profile
    function wpua_action_show_user_profile($user){
      global $wpdb, $blog_id, $current_user, $show_avatars, $wpua_upload_size_limit_with_units;
      // Get WPUA attachment ID
      $wpua = get_user_meta($user->ID, $wpdb->get_blog_prefix($blog_id).'user_avatar', true);
      // Show remove button if WPUA is set
      $hide_remove = !has_wp_user_avatar($user->ID) ? ' hide-me' : "";
      // If avatars are enabled, get original avatar image or show blank
      $avatar_medium_src = ($show_avatars == 1 && is_admin()) ? wpua_get_avatar_original($user->user_email, 96) : includes_url().'images/blank.gif';
      // Check if user has wp_user_avatar, if not show image from above
      $avatar_medium = has_wp_user_avatar($user->ID) ? get_wp_user_avatar_src($user->ID, 'medium') : $avatar_medium_src;
      // Check if user has wp_user_avatar, if not show image from above
      $avatar_thumbnail = has_wp_user_avatar($user->ID) ? get_wp_user_avatar_src($user->ID, 96) : $avatar_medium_src;
      // Change text on message based on current user
      $profile = ($current_user->ID == $user->ID) ? 'Profile' : 'User';
      // Max upload size
      if(!function_exists('wp_max_upload_size')){
        require_once(ABSPATH.'wp-admin/includes/template.php');
      }
    ?>
      <?php if(class_exists('bbPress') && !is_admin()) : // Add to bbPress profile with same style ?>
        <h2 class="entry-title"><?php _e('WP User Avatar'); ?></h2>
        <fieldset class="bbp-form">
          <legend><?php _e('WP User Avatar'); ?></legend>
      <?php else : // Add to profile with admin style ?>
        <h3><?php _e('WP User Avatar') ?></h3>
        <table class="form-table">
          <tr>
            <th><label for="wp_user_avatar"><?php _e('WP User Avatar'); ?></label></th>
            <td>
      <?php endif; ?>
      <input type="hidden" name="wp-user-avatar" id="wp-user-avatar" value="<?php echo $wpua; ?>" />
      <?php if(current_user_can('upload_files')) : // Button to launch Media uploader ?>
        <p><button type="button" class="button" id="add-wp-user-avatar" name="add-wp-user-avatar"><?php _e('Edit WP User Avatar'); ?></button></p>
      <?php elseif(!current_user_can('upload_files') && !has_wp_user_avatar($current_user->ID)) : // Upload button ?>
        <input name="wp-user-avatar-file" id="wp-user-avatar-file" type="file" />
         <button type="submit" class="button" id="upload-wp-user-avatar" name="upload-wp-user-avatar" value="<?php _e('Upload'); ?>"><?php _e('Upload'); ?></button>
        <p>
          <?php _e('Maximum upload file size: '.esc_html($wpua_upload_size_limit_with_units)); ?>
          <br />
          <?php _e('Allowed file formats: JPG, GIF, PNG'); ?>
        </p>
      <?php elseif(!current_user_can('upload_files') && has_wp_user_avatar($current_user->ID) && wpua_author($wpua, $current_user->ID)) : // Edit button ?>
        <?php $edit_attachment_link = function_exists('wp_enqueue_media') ? add_query_arg(array('post' => $wpua, 'action' => 'edit'), admin_url('post.php')) : add_query_arg(array('attachment_id' => $wpua, 'action' => 'edit'), admin_url('media.php')) ?>
        <p><button type="button" class="button" id="edit-wp-user-avatar" name="edit-wp-user-avatar" onclick="window.open('<?php echo $edit_attachment_link; ?>', '_self');"><?php _e('Edit WP User Avatar'); ?></button></p>
      <?php endif; ?>
      <p id="wp-user-avatar-preview">
        <img src="<?php echo $avatar_medium; ?>" alt="" />
        <?php _e('Original'); ?>
      </p>
      <p id="wp-user-avatar-thumbnail">
        <img src="<?php echo $avatar_thumbnail; ?>" alt="" />
        <?php _e('Thumbnail'); ?>
      </p>
      <p><button type="button" class="button<?php echo $hide_remove; ?>" id="remove-wp-user-avatar" name="remove-wp-user-avatar"><?php _e('Remove'); ?></button></p>
      <p id="wp-user-avatar-message"><?php _e('Press "Update '.$profile.'" to save your changes.'); ?></p>
      <?php if(class_exists('bbPress') && !is_admin()) : // Add to bbPress profile with same style ?>
        </fieldset>
      <?php else : // Add to profile with admin style ?>
            </td>
          </tr>
        </table>
      <?php endif; ?>
      <?php echo wpua_js($user->display_name, $avatar_medium_src); // Add JS ?>
      <?php
    }

    // Update user meta
    function wpua_action_process_option_update($user_id){
      global $wpdb, $blog_id;
      // Check if user has upload_files capability
      if(current_user_can('upload_files')){
        $wpua_id = isset($_POST['wp-user-avatar']) ? intval($_POST['wp-user-avatar']) : "";
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %d", '_wp_attachment_wp_user_avatar', $user_id));
        add_post_meta($wpua_id, '_wp_attachment_wp_user_avatar', $user_id);
        update_user_meta($user_id, $wpdb->get_blog_prefix($blog_id).'user_avatar', $wpua_id);
      } else {
        if(isset($_POST['wp-user-avatar']) && empty($_POST['wp-user-avatar'])){
          // Uploads by user
          $attachments = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE post_author = %d AND post_type = %s", $user_id, 'attachment'));
          foreach($attachments as $attachment){
            // Delete attachment if not used by another user
            if(!wpua_image($attachment->ID, $user_id)){
              wp_delete_post($attachment->ID);
            }
          }
          update_user_meta($user_id, $wpdb->get_blog_prefix($blog_id).'user_avatar', "");
        }
        // Create attachment from upload
        if(isset($_POST['upload-wp-user-avatar']) && $_POST['upload-wp-user-avatar']){
          if(!function_exists('wp_handle_upload')){
            require_once(ABSPATH.'wp-admin/includes/admin.php');
            require_once(ABSPATH.'wp-admin/includes/file.php');
          }
          $name = $_FILES['wp-user-avatar-file']['name'];
          $file = wp_handle_upload($_FILES['wp-user-avatar-file'], array('test_form' => false));
          $type = $file['type'];
          // Allow only JPG, GIF, PNG
          if($file['error'] || !preg_match('/(jpe?g|gif|png)$/i', $type)){
            if($file['error']){
              wp_die($file['error']);
            } else {
              wp_die(__('Sorry, this file type is not permitted for security reasons.'));
            }
          }
          // Break out file info
          $name_parts = pathinfo($name);
          $name = trim(substr($name, 0, -(1 + strlen($name_parts['extension']))));
          $url = $file['url'];
          $file = $file['file'];
          $title = $name;
          // Use image exif/iptc data for title if possible
          if($image_meta = @wp_read_image_metadata($file)){
            if(trim($image_meta['title']) && !is_numeric(sanitize_title($image_meta['title']))){
              $title = $image_meta['title'];
            }
          }
          // Construct the attachment array
          $attachment = array(
            'guid'           => $url,
            'post_mime_type' => $type,
            'post_title'     => $title
          );
          // This should never be set as it would then overwrite an existing attachment
          if(isset($attachment['ID'])){
            unset($attachment['ID']);
          }
          // Save the attachment metadata
          $attachment_id = wp_insert_attachment($attachment, $file);
          if(!is_wp_error($attachment_id)){
            require_once(ABSPATH.'wp-admin/includes/image.php');
            wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file));
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %d", '_wp_attachment_wp_user_avatar', $user_id));
            add_post_meta($attachment_id, '_wp_attachment_wp_user_avatar', $user_id);
            update_user_meta($user_id, $wpdb->get_blog_prefix($blog_id).'user_avatar', $attachment_id);
          }
        }
      }
    }

    // Add button to attach image for WP 3.4 and older
    function wpua_add_attachment_field_to_edit($fields, $post){
      $image = wp_get_attachment_image_src($post->ID, "medium");
      $button = '<button type="button" class="button" id="set-wp-user-avatar-image" name="set-wp-user-avatar-image" onclick="setWPUserAvatar(\''.$post->ID.'\', \''.$image[0].'\')">Set WP User Avatar</button>';
      $fields['wp-user-avatar'] = array(
        'label' => __('WP User Avatar'),
        'input' => 'html',
        'html' => $button
      );
      return $fields;
    }

    // Add settings link on plugin page
    function wpua_plugin_settings_links($links, $file){
      if(basename($file) == basename(plugin_basename(__FILE__))){
        $settings_link = '<a href="'.add_query_arg(array('page' => 'wp-user-avatar'), admin_url('options-general.php')).'">'.__('Settings').'</a>';
        $links = array_merge($links, array($settings_link));
      }
      return $links;
    }

    // Add column to Users table
    function wpua_add_column($columns){
      return $columns + array('wp-user-avatar' => __('WP User Avatar'));
    }

    // Show thumbnail in Users table
    function wpua_show_column($value, $column_name, $user_id){
      global $wpdb, $blog_id;
      $wpua = get_user_meta($user_id, $wpdb->get_blog_prefix($blog_id).'user_avatar', true);
      $wpua_image = wp_get_attachment_image($wpua, array(32,32));
      if($column_name == 'wp-user-avatar'){
        return $wpua_image;
      }
    }

    // Media uploader
    function wpua_media_upload_scripts(){
      if(function_exists('wp_enqueue_media')){
        wp_enqueue_script('admin-bar');
        wp_enqueue_media();
      } else {
        wp_enqueue_script('media-upload');
        wp_enqueue_script('thickbox');
        wp_enqueue_style('thickbox');
      }
      wp_enqueue_script('jquery');
      wp_enqueue_script('jquery-ui-slider');
      wp_enqueue_script('wp-user-avatar', WPUA_URLPATH.'js/wp-user-avatar.js', "", WPUA_VERSION);
      wp_enqueue_style('wp-user-avatar-jqueryui', WPUA_URLPATH.'css/jquery.ui.slider.css', "", null);
      wp_enqueue_style('wp-user-avatar', WPUA_URLPATH.'css/wp-user-avatar.css', "", WPUA_VERSION);
    }
  }

  // Uploader scripts
  function wpua_js($section, $avatar_thumb){ ?>
    <script type="text/javascript">
      jQuery(function(){
        <?php if(current_user_can('upload_files')) : ?>
          <?php if(function_exists('wp_enqueue_media')) : // Backbone uploader for WP 3.5+ ?>
            openMediaUploader("<?php echo $section; ?>");
          <?php else : // Fall back to Thickbox uploader ?>
            openThickboxUploader("<?php echo $section; ?>", "<?php echo get_admin_url(); ?>media-upload.php?post_id=0&type=image&tab=library&TB_iframe=1");
          <?php endif; ?>
        <?php endif; ?>
        removeWPUserAvatar("<?php echo htmlspecialchars_decode($avatar_thumb); ?>");
      });
    </script>
  <?php
  }

  // Returns true if user has Gravatar-hosted image
  function wpua_has_gravatar($id_or_email, $has_gravatar=false, $user="", $email=""){
    global $ssl;
    if(!is_object($id_or_email) && !empty($id_or_email)){
      // Find user by ID or e-mail address
      $user = is_numeric($id_or_email) ? get_user_by('id', $id_or_email) : get_user_by('email', $id_or_email);
      // Get registered user e-mail address
      $email = !empty($user) ? $user->user_email : "";
    }
    // Check if Gravatar image returns 200 (OK) or 404 (Not Found)
    if(!empty($email)){
      $hash = md5(strtolower(trim($email)));
      $gravatar = 'http'.$ssl.'://www.gravatar.com/avatar/'.$hash.'?d=404';
      $headers = @get_headers($gravatar);
      $has_gravatar = !preg_match("|200|", $headers[0]) ? false : true;
    }
    return $has_gravatar;
  }

  // Returns true if user has wp_user_avatar
  function has_wp_user_avatar($id_or_email="", $has_wpua=false, $user="", $user_id=""){
    global $wpdb, $blog_id;
    if(!is_object($id_or_email) && !empty($id_or_email)){
      // Find user by ID or e-mail address
      $user = is_numeric($id_or_email) ? get_user_by('id', $id_or_email) : get_user_by('email', $id_or_email);
      // Get registered user ID
      $user_id = !empty($user) ? $user->ID : "";
    }
    $wpua = get_user_meta($user_id, $wpdb->get_blog_prefix($blog_id).'user_avatar', true);
    $has_wpua = !empty($wpua) ? true : false;
    return $has_wpua;
  }

  // Replace get_avatar only in get_wp_user_avatar
  function wpua_get_avatar_filter($avatar, $id_or_email, $size="", $default="", $alt=""){
    global $post, $comment, $avatar_default, $wpua_avatar_default, $mustache_original, $mustache_medium, $mustache_thumbnail, $mustache_avatar, $mustache_admin;
    // User has WPUA
    if(is_object($id_or_email)){
      if(!empty($comment->comment_author_email)){
        $avatar = get_wp_user_avatar($comment->comment_author_email, $size, $default, $alt);
      } else {
        $avatar = get_wp_user_avatar('unknown@gravatar.com', $size, $default, $alt);
      }
    } else {
      if(has_wp_user_avatar($id_or_email)){
        $avatar = get_wp_user_avatar($id_or_email, $size, $default, $alt);
      // User has Gravatar
      } elseif(wpua_has_gravatar($id_or_email)){
        $avatar = $avatar;
      // User doesn't have WPUA or Gravatar and Default Avatar is wp_user_avatar, show custom Default Avatar
      } elseif($avatar_default == 'wp_user_avatar'){
        // Show custom Default Avatar
        if(!empty($wpua_avatar_default)){
          // Get image
          $wpua_avatar_default_image = wp_get_attachment_image_src($wpua_avatar_default, array($size,$size));
          // Image src
          $default = $wpua_avatar_default_image[0];
          // Add dimensions if numeric size
          $dimensions = ' width="'.$wpua_avatar_default_image[1].'" height="'.$wpua_avatar_default_image[2].'"';
          $defaultcss = "";
        } else {
          // Get mustache image based on numeric size comparison
          if($size > get_option('medium_size_w')){
            $default = $mustache_original;
          } elseif($size <= get_option('medium_size_w') && $size > get_option('thumbnail_size_w')){
            $default = $mustache_medium;
          } elseif($size <= get_option('thumbnail_size_w') && $size > 96){
            $default = $mustache_thumbnail;
          } elseif($size <= 96 && $size > 32){
            $default = $mustache_avatar;
          } elseif($size <= 32){
            $default = $mustache_admin;
          }
          // Add dimensions if numeric size
          $dimensions = ' width="'.$size.'" height="'.$size.'"';
          $defaultcss = ' avatar-default';
        }
        // Construct the img tag
        $avatar = "<img src='".$default."'".$dimensions." alt='".$alt."' class='wp-user-avatar wp-user-avatar-".$size." avatar avatar-".$size." photo'".$defaultcss." />";
      }
    }
    return $avatar;
  }
  add_filter('get_avatar', 'wpua_get_avatar_filter', 10, 6);

  // Get original avatar, for when user removes wp_user_avatar
  function wpua_get_avatar_original($id_or_email, $size="", $default="", $alt=""){
    global $avatar_default, $wpua_avatar_default, $mustache_avatar, $pagenow;
    // Remove get_avatar filter
    if(is_admin()){
      remove_filter('get_avatar', 'wpua_get_avatar_filter');
    }
    // User doesn't Gravatar and Default Avatar is wp_user_avatar, show custom Default Avatar
    if(!wpua_has_gravatar($id_or_email) && $avatar_default == 'wp_user_avatar'){
      // Show custom Default Avatar
      if(!empty($wpua_avatar_default)){
        $wpua_avatar_default_image = wp_get_attachment_image_src($wpua_avatar_default, array($size,$size));
        $default = $wpua_avatar_default_image[0];
      } else {
        $default = $mustache_avatar;
      }
    } else {
      // Get image from Gravatar, whether it's the user's image or default image
      $wpua_image = get_avatar($id_or_email, $size);
      // Takes the img tag, extracts the src
      $output = preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $wpua_image, $matches, PREG_SET_ORDER);
      $default = $matches [0] [1];
    }
    return $default;
  }

  // Find WPUA, show get_avatar if empty
  function get_wp_user_avatar($id_or_email="", $size='96', $align="", $alt=""){
    global $post, $comment, $avatar_default, $wpdb, $blog_id;
    // Checks if comment
    if(is_object($id_or_email)){
      // Checks if comment author is registered user by user ID
      if($comment->user_id != '0'){
        $id_or_email = $comment->user_id;
        $user = get_user_by('id', $id_or_email);
      // Checks that comment author isn't anonymous
      } elseif(!empty($comment->comment_author_email)){
        // Checks if comment author is registered user by e-mail address
        $user = get_user_by('email', $comment->comment_author_email);
        // Get registered user info from profile, otherwise e-mail address should be value
        $id_or_email = !empty($user) ? $user->ID : $comment->comment_author_email;
      }
      $alt = $comment->comment_author;
    } else {
      if(!empty($id_or_email)){
        // Find user by ID or e-mail address
        $user = is_numeric($id_or_email) ? get_user_by('id', $id_or_email) : get_user_by('email', $id_or_email);
      } else {
        // Find author's name if id_or_email is empty
        $author_name = get_query_var('author_name');
        if(is_author()){
          // On author page, get user by page slug
          $user = get_user_by('slug', $author_name);
        } else {
          // On post, get user by author meta
          $user_id = get_the_author_meta('ID');
          $user = get_user_by('id', $user_id);
        }
      }
      // Set user's ID and name
      if(!empty($user)){
        $id_or_email = $user->ID;
        $alt = $user->display_name;
      }
    }
    // Checks if user has WPUA
    $wpua_meta = !empty($id_or_email) ? get_the_author_meta($wpdb->get_blog_prefix($blog_id).'user_avatar', $id_or_email) : "";
    // Add alignment class
    $alignclass = !empty($align) ? ' align'.$align : "";
    // User has WPUA, bypass get_avatar
    if(!empty($wpua_meta)){
      // Numeric size use size array
      $get_size = is_numeric($size) ? array($size,$size) : $size;
      // Get image src
      $wpua_image = wp_get_attachment_image_src($wpua_meta, $get_size);
      // Add dimensions to img only if numeric size was specified
      $dimensions = is_numeric($size) ? ' width="'.$wpua_image[1].'" height="'.$wpua_image[2].'"' : "";
      // Construct the img tag
      $avatar = '<img src="'.$wpua_image[0].'"'.$dimensions.' alt="'.$alt.'" class="wp-user-avatar wp-user-avatar-'.$size.$alignclass.' avatar avatar avatar-'.$size.' photo" />';
    } else {
      // Get numeric sizes for non-numeric sizes based on media options
      if($size == 'original' || $size == 'large' || $size == 'medium' || $size == 'thumbnail'){
        $get_size = ($size == 'original') ? get_option('large_size_w') : get_option($size.'_size_w');
      } else {
        // Numeric sizes leave as-is
        $get_size = $size;
      }
      // User with no WPUA uses get_avatar
      $avatar = get_avatar($id_or_email, $get_size, $default="", $alt="");
      // Remove width and height for non-numeric sizes
      if(!is_numeric($size)){
        $avatar = preg_replace("/(width|height)=\'\d*\'\s/", "", $avatar);
        $avatar = preg_replace('/(width|height)=\"\d*\"\s/', "", $avatar);
        $avatar = str_replace('wp-user-avatar wp-user-avatar-'.$get_size.' ', "", $avatar);
        $avatar = str_replace("class='", "class='wp-user-avatar wp-user-avatar-".$size.$alignclass." ", $avatar);
      }
    }
    return $avatar;
  }

  // Return just the image src
  function get_wp_user_avatar_src($id_or_email, $size="", $align=""){
    $wpua_image_src = "";
    // Gets the avatar img tag
    $wpua_image = get_wp_user_avatar($id_or_email, $size, $align);
    // Takes the img tag, extracts the src
    if(!empty($wpua_image)){
      $output = preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $wpua_image, $matches, PREG_SET_ORDER);
      $wpua_image_src = $matches [0] [1];
    }
    return $wpua_image_src;
  }

  // Shortcode
  function wpua_shortcode($atts, $content){
    global $wpdb, $blog_id;
    // Set shortcode attributes
    extract(shortcode_atts(array('user' => "", 'size' => '96', 'align' => "", 'link' => "", 'target' => ""), $atts));
    // Find user by ID, login, slug, or e-mail address
    if(!empty($user)){
      $user = is_numeric($user) ? get_user_by('id', $user) : get_user_by('login', $user);
      $user = empty($user) ? get_user_by('slug', $user) : $user;
      $user = empty($user) ? get_user_by('email', $user) : $user;
    }
    // Get user ID
    $id_or_email = !empty($user) ? $user->ID : "";
    // Check if link is set
    if(!empty($link)){
      // CSS class is same as link type, except for URL
      $link_class = $link;
      // Open in new window
      $target_link = !empty($target) ? ' target="'.$target.'"' : "";
      if($link == 'file'){
        // Get image src
        $image_link = get_wp_user_avatar_src($id_or_email, 'original', $align);
      } elseif($link == 'attachment'){
        // Get attachment URL
        $image_link = get_attachment_link(get_the_author_meta($wpdb->get_blog_prefix($blog_id).'user_avatar', $id_or_email));
      } else {
        // URL
        $image_link = $link;
        $link_class = 'custom';
      }
      // Wrap the avatar inside the link
      $avatar = '<a href="'.$image_link.'" class="wp-user-avatar-link wp-user-avatar-'.$link_class.'"'.$target_link.'>'.get_wp_user_avatar($id_or_email, $size, $align).'</a>';
    } else {
      // Get WPUA as normal
      $avatar = get_wp_user_avatar($id_or_email, $size, $align);
    }
    return $avatar;
  }
  add_shortcode('avatar', 'wpua_shortcode');

  // Add default avatar
  function wpua_add_default_avatar($avatar_list=null){
    global $avatar_default, $wpua_avatar_default, $mustache_medium, $mustache_admin;
    // Remove get_avatar filter
    remove_filter('get_avatar', 'wpua_get_avatar_filter');
    // Set avatar_list variable
    $avatar_list = "";
    // Set avatar defaults
    $avatar_defaults = array(
      'mystery' => __('Mystery Man'),
      'blank' => __('Blank'),
      'gravatar_default' => __('Gravatar Logo'),
      'identicon' => __('Identicon (Generated)'),
      'wavatar' => __('Wavatar (Generated)'),
      'monsterid' => __('MonsterID (Generated)'),
      'retro' => __('Retro (Generated)')
    );
    // No Default Avatar, set to Mystery Man
    if(empty($avatar_default)){
      $avatar_default = 'mystery';
    }
    // Take avatar_defaults and get examples for unknown@gravatar.com
    foreach($avatar_defaults as $default_key => $default_name){
      $avatar = get_avatar('unknown@gravatar.com', 32, $default_key);
      $selected = ($avatar_default == $default_key) ? 'checked="checked" ' : "";
      $avatar_list .= "\n\t<label><input type='radio' name='avatar_default' id='avatar_{$default_key}' value='".esc_attr($default_key)."' {$selected}/> ";
      $avatar_list .= preg_replace("/src='(.+?)'/", "src='\$1&amp;forcedefault=1'", $avatar);
      $avatar_list .= ' '.$default_name.'</label>';
      $avatar_list .= '<br />';
    }
    // Show remove link if custom Default Avatar is set
    if(!empty($wpua_avatar_default)){
      $avatar_thumb_src = wp_get_attachment_image_src($wpua_avatar_default, array(32,32));
      $avatar_thumb = $avatar_thumb_src[0];
      $hide_remove = "";
    } else {
      $avatar_thumb = $mustache_admin;
      $hide_remove = ' class="hide-me"';
    }
    // Default Avatar is wp_user_avatar, check the radio button next to it
    $selected_avatar = ($avatar_default == 'wp_user_avatar') ? ' checked="checked" ' : "";
    // Wrap WPUA in div
    $avatar_thumb_img = '<div id="wp-user-avatar-preview"><img src="'.$avatar_thumb.'" width="32" /></div>';
    // Add WPUA to list
    $wpua_list = "\n\t<label><input type='radio' name='avatar_default' id='wp_user_avatar_radio' value='wp_user_avatar'$selected_avatar /> ";
    $wpua_list .= preg_replace("/src='(.+?)'/", "src='\$1'", $avatar_thumb_img);
    $wpua_list .= ' '.__('WP User Avatar').'</label>';
    $wpua_list .= '<p id="edit-wp-user-avatar"><button type="button" class="button" id="add-wp-user-avatar" name="add-wp-user-avatar">'.__('Edit WP User Avatar').'</button>';
    $wpua_list .= '<a href="#" id="remove-wp-user-avatar"'.$hide_remove.'>'.__('Remove').'</a></p>';
    $wpua_list .= '<input type="hidden" id="wp-user-avatar" name="avatar_default_wp_user_avatar" value="'.$wpua_avatar_default.'">';
    $wpua_list .= '<p id="wp-user-avatar-message">'.__('Press "Save Changes" to save your changes.').'</p>';
    $wpua_list .= wpua_js('Default Avatar', $mustache_admin);
    return $wpua_list.$avatar_list;
  }
  add_filter('default_avatar_select', 'wpua_add_default_avatar', 10);

  // Add default avatar_default to whitelist
  function wpua_whitelist_options($whitelist_options){
    $whitelist_options['discussion'][] = 'avatar_default_wp_user_avatar';
    return $whitelist_options;
  }
  add_filter('whitelist_options', 'wpua_whitelist_options', 10);

  // Add media state
  function wpua_add_media_state($media_states){
    global $post, $wpua_avatar_default;
    $is_wpua = get_post_custom_values('_wp_attachment_wp_user_avatar', $post->ID);
    if(!empty($is_wpua)){
      $media_states[] = __('Avatar');
    }
    if(!empty($wpua_avatar_default) && ($wpua_avatar_default == $post->ID)){
      $media_states[] = __('Default Avatar');
    }
    return apply_filters('wpua_add_media_state', $media_states);
  }
  add_filter('display_media_states', 'wpua_add_media_state', 10, 1);

  // Check if image is used as WPUA
  function wpua_image($attachment_id, $user_id, $wpua_image=false){
    global $wpdb;
    $wpua = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s AND meta_value != %d", $attachment_id, '_wp_attachment_wp_user_avatar', $user_id));
    if(!empty($wpua)){
      $wpua_image = true;
    }
    return $wpua_image;
  }

  // Check who owns image
  function wpua_author($attachment_id, $user_id, $wpua_author=false){
    $attachment = get_post($attachment_id);
    if(!empty($attachment) && $attachment->post_author == $user_id){
      $wpua_author = true;
    }
    return $wpua_author;
  }

  // Admin page
  function wpua_options_page(){
    global $wpua_allow_upload, $upload_size_limit_with_units, $wpua_upload_size_limit, $wpua_upload_size_limit_with_units;
    // Give subscribers edit_posts capability
    if(isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true' && empty($wpua_allow_upload)){
      wpua_subscriber_remove_cap();
    }
    $hide_size = ($wpua_allow_upload != 1) ? ' class="hide-me"' : "";
    
  ?>
    <div class="wrap">
      <?php screen_icon(); ?>
      <h2><?php _e('WP User Avatar'); ?></h2>
      <form method="post" action="options.php">
        <?php settings_fields('wpua-settings-group'); ?>
        <?php do_settings_fields('wpua-settings-group', ""); ?>
        <table class="form-table">
          <tr valign="top">
            <th scope="row"><?php _e('WP User Avatar Settings') ?></th>
            <td>
              <fieldset>
                <legend class="screen-reader-text"><span><?php _e('WP User Avatar Settings'); ?></span></legend>
                <label for="wp_user_avatar_tinymce" class="wpua_label">
                  <input name="wp_user_avatar_tinymce" type="checkbox" id="wp_user_avatar_tinymce" value="1" <?php checked('1', get_option('wp_user_avatar_tinymce')); ?> />
                  <?php _e('Add avatar button to Visual Editor'); ?>
                </label>
                <label for="wp_user_avatar_allow_upload" class="wpua_label">
                  <input name="wp_user_avatar_allow_upload" type="checkbox" id="wp_user_avatar_allow_upload" value="1" <?php checked('1', get_option('wp_user_avatar_allow_upload')); ?> />
                  <?php _e('Allow Contributors &amp; Subscribers to upload avatars'); ?>
                </label>
                <label for="wp_user_avatar_disable_gravatar" class="wpua_label">
                  <input name="wp_user_avatar_disable_gravatar" type="checkbox" id="wp_user_avatar_disable_gravatar" value="1" <?php checked('1', get_option('wp_user_avatar_disable_gravatar')); ?> />
                  <?php _e('Disable Gravatar &mdash; Use only local avatars'); ?>
                </label>
              </fieldset>
            </td>
          </tr>
          <tr id="wp-size-upload-limit-settings" valign="top"<?php echo $hide_size; ?>>
            <th scope="row"><label for="wp_user_avatar_upload_size_limit" class="wpua_label">Upload Size Limit (only for Contributors &amp; Subscribers)</label></th>
            <td>
              <input name="wp_user_avatar_upload_size_limit" type="text" id="wp_user_avatar_upload_size_limit" value="<?php echo $wpua_upload_size_limit; ?>" class="regular-text" />
              <span id="wp-readable-size">(<?php echo $wpua_upload_size_limit_with_units; ?>)</span>
              <span id="wp-readable-size-error"><?php _e('Upload size limit cannot be larger than server limit.'); ?></span>
              <div id="wp-user-avatar-slider"></div>
              <script type="text/javascript">
                jQuery(function(){
                  // Show size info only if allow uploads is checked
                  jQuery('#wp_user_avatar_allow_upload').change(function(){
                    jQuery('#wp-size-upload-limit-settings').toggle(jQuery('#wp_user_avatar_allow_upload').is(':checked'));
                  });
                  // Add size slider
                  jQuery('#wp-user-avatar-slider').slider({
                    value: <?php echo $wpua_upload_size_limit; ?>,
                    min: 0,
                    max: <?php echo wp_max_upload_size(); ?>,
                    step: 8,
                    slide: function(event, ui){
                      jQuery('#wp_user_avatar_upload_size_limit').val(ui.value);
                      jQuery('#wp-readable-size').html('(' + Math.floor(ui.value / 1024) + 'KB)');
                      jQuery('#wp-readable-size-error').hide();
                    }
                  });
                  // Update readable size on keyup
                  jQuery('#wp_user_avatar_upload_size_limit').keyup(function(){
                    var wpua_upload_size_limit = jQuery(this).val();
                    wpua_upload_size_limit = wpua_upload_size_limit.replace(/[^0-9]/g, '');
                    jQuery(this).val(wpua_upload_size_limit);
                    jQuery('#wp-readable-size').html('(' + Math.floor(wpua_upload_size_limit / 1024) + 'KB)');
                    jQuery('#wp-readable-size-error').toggle(wpua_upload_size_limit > <?php echo wp_max_upload_size(); ?>);
                  });
                  jQuery('#wp_user_avatar_upload_size_limit').val(jQuery('#wp-user-avatar-slider').slider('value'));
                });
              </script>
              <span class="description"><?php _e('Your current server limit: '.wp_max_upload_size().' bytes ('.$upload_size_limit_with_units.')'); ?></span>
            </td>
          </tr>
        </table>
        <h3 class="title"><?php _e('Avatars'); ?></h3>
        <p><?php _e('An avatar is an image that follows you from weblog to weblog appearing beside your name when you comment on avatar enabled sites. Here you can enable the display of avatars for people who comment on your site.'); ?></p>
        <table class="form-table">
          <tr valign="top">
          <th scope="row"><?php _e('Avatar Display'); ?></th>
          <td>
            <fieldset>
              <legend class="screen-reader-text"><span><?php _e('Avatar Display'); ?></span></legend>
              <label for="show_avatars">
              <input type="checkbox" id="show_avatars" name="show_avatars" value="1" <?php checked('1', get_option('show_avatars')); ?> />
              <?php _e('Show Avatars'); ?>
              </label>
            </fieldset>
            </td>
          </tr>
          <tr valign="top">
            <th scope="row"><?php _e('Maximum Rating'); ?></th>
            <td>
              <fieldset>
                <legend class="screen-reader-text"><span><?php _e('Maximum Rating'); ?></span></legend>
                <?php
                  $ratings = array(
                    'G' => __('G &#8212; Suitable for all audiences'),
                    'PG' => __('PG &#8212; Possibly offensive, usually for audiences 13 and above'),
                    'R' => __('R &#8212; Intended for adult audiences above 17'),
                    'X' => __('X &#8212; Even more mature than above')
                  );
                  foreach ($ratings as $key => $rating) :
                    $selected = (get_option('avatar_rating') == $key) ? 'checked="checked"' : "";
                    echo "\n\t<label><input type='radio' name='avatar_rating' value='" . esc_attr($key) . "' $selected/> $rating</label><br />";
                  endforeach;
                ?>
              </fieldset>
            </td>
          </tr>
          <tr valign="top">
            <th scope="row"><?php _e('Default Avatar') ?></th>
            <td class="defaultavatarpicker">
              <fieldset>
                <legend class="screen-reader-text"><span><?php _e('Default Avatar'); ?></span></legend>
                <?php _e('For users without a custom avatar of their own, you can either display a generic logo or a generated one based on their e-mail address.'); ?><br />
                <?php echo wpua_add_default_avatar(); ?>
              </fieldset>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  // Whitelist settings
  function wpua_admin_settings(){
    register_setting('wpua-settings-group', 'wp_user_avatar_tinymce');
    register_setting('wpua-settings-group', 'wp_user_avatar_allow_upload');
    register_setting('wpua-settings-group', 'wp_user_avatar_disable_gravatar');
    register_setting('wpua-settings-group', 'wp_user_avatar_upload_size_limit');
    register_setting('wpua-settings-group', 'show_avatars');
    register_setting('wpua-settings-group', 'avatar_rating');
    register_setting('wpua-settings-group', 'avatar_default');
    register_setting('wpua-settings-group', 'avatar_default_wp_user_avatar');
  }

  // Add options page and settings
  function wpua_admin(){
    add_options_page('WP User Avatar Plugin Settings', 'WP User Avatar', 'manage_options', 'wp-user-avatar', 'wpua_options_page');
    add_action('admin_init', 'wpua_admin_settings');
  }

  // Initialize WPUA after other plugins are loaded
  function wpua_load(){
    global $wpua_instance;
    $wpua_instance = new wp_user_avatar();
  }
  add_action('plugins_loaded', 'wpua_load');
}
?>
