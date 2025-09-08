<?php
/**
 * Plugin Name: PS Local Avatars
 * Description: Local avatars with Gravatar fallback. Subscriber-safe uploads, per-site dimension caps, square crops, small serve for comments, role controls, settings, shortcode, and REST API.
 * Version: 1.3.6
 * Requires at least: 5.6
 * Tested up to: 6.8.2
 * Requires PHP: 7.4
 * Author: Paul + ChatGPT
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ps-local-avatars
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('PS_Local_Avatars')) {

class PS_Local_Avatars {

    const META_AVATAR_ID = '_psla_avatar_id';
    const META_SOURCE    = '_psla_avatar_source'; // 'uploaded' | 'gravatar'
    const OPT_KEY        = 'psla_options';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Ensure profile forms accept file uploads
        add_action('user_edit_form_tag', function(){ echo ' enctype="multipart/form-data"'; });
        add_action('user_new_form_tag', function(){ echo ' enctype="multipart/form-data"'; });

        // Image sizes (read options early to allow configurable small size)
        add_action('init', function(){
            $opts = $this->get_options();
            add_image_size('psla_avatar', 512, 512, true);
            $small = max(32, (int)$opts['small_square_px']);
            add_image_size('psla_avatar_small', $small, $small, true);
        });

        // Profile UI
        add_action('show_user_profile',  [$this, 'render_profile_ui']);
        add_action('edit_user_profile',  [$this, 'render_profile_ui']);
        add_action('personal_options_update', [$this, 'save_profile_ui']);
        add_action('edit_user_profile_update', [$this, 'save_profile_ui']);

        // Admin assets for media modal (checked against role restrictions)
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Avatar override
        add_filter('pre_get_avatar_data', [$this, 'filter_pre_get_avatar_data'], 10, 2);
        add_filter('get_avatar', [$this, 'maybe_inject_alt'], 10, 6);

        // Settings
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Shortcode + block
        add_shortcode('psla_avatar', [$this, 'shortcode_psla_avatar']);
        add_action('init', [$this, 'register_block']);

        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Ensure small derivative can be generated on demand for legacy attachments
        add_filter('intermediate_image_sizes_advanced', [$this, 'maybe_add_sizes_during_meta'], 10, 2);
		
		// Ensure URL-based paths (Admin Bar / profile) use the local avatar
        add_filter('get_avatar_url', [$this, 'psla_get_avatar_url'], 10, 3);

        // In admin (and when Admin Bar is visible), force avatars "on" so CP can't short-circuit
        add_filter('option_show_avatars', [$this, 'psla_force_admin_avatars']);

    }

    /** Options */
    private function get_options() {
        $defaults = [
            'default_behavior'       => 'prefer_uploaded', // or 'prefer_gravatar'
            'max_upload_kb'          => 2048, // 2MB
            'max_width_px'           => 1024,
            'max_height_px'          => 1024,
            'serve_small_in_comments'=> true,
            'small_square_px'        => 256,
            'disallow_media_roles'   => [], // role slugs
        ];
        $opts = get_option(self::OPT_KEY, []);
        if (!empty($opts['disallow_media_roles']) && is_string($opts['disallow_media_roles'])) {
            $maybe = maybe_unserialize($opts['disallow_media_roles']);
            if (is_array($maybe)) $opts['disallow_media_roles'] = $maybe;
        }
        return wp_parse_args($opts, $defaults);
    }

    /** Allow dynamic sizes during metadata generation */
    public function maybe_add_sizes_during_meta($sizes, $metadata) {
        $sizes['psla_avatar'] = ['width'=>512, 'height'=>512, 'crop'=>1];
        $opts = $this->get_options();
        $small = max(32, (int)$opts['small_square_px']);
        $sizes['psla_avatar_small'] = ['width'=>$small, 'height'=>$small, 'crop'=>1];
        return $sizes;
    }

    /** Admin assets only when allowed */
    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['profile.php', 'user-edit.php'], true)) return;

        $target_user = get_current_user_id();
        if ($hook === 'user-edit.php' && isset($_GET['user_id'])) {
            $target_user = (int) $_GET['user_id'];
        }
        $allow_media = $this->allow_media_button_for_user($target_user);

        // Always enqueue our script for preview/remove behavior (all roles)
        wp_enqueue_script(
            'psla-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            '1.3.6',
            true
        );
        // Pass constraints for optional client-side checks
        $opts = $this->get_options();
        wp_localize_script('psla-admin', 'PSLA', [
            'maxKB' => (int) $opts['max_upload_kb'],
            'maxW'  => (int) $opts['max_width_px'],
            'maxH'  => (int) $opts['max_height_px'],
            'mimes' => ['image/jpeg','image/png','image/gif','image/webp']
        ]);

        // Only load the Media Library if the button is allowed
        if ($allow_media) {
            wp_enqueue_media();
        }
    }

    /** Should the Media Library button be shown for this target user's role(s)? */
    private function allow_media_button_for_user($target_user_id) {
        $opts = $this->get_options();
        $disallowed = (array) $opts['disallow_media_roles'];
        $u = get_user_by('id', $target_user_id);
        if (!$u) return false;
        if (!current_user_can('upload_files')) return false;
        $roles = (array) $u->roles;
        foreach ($roles as $r) {
            if (in_array($r, $disallowed, true)) return false;
        }
        return true;
    }

    /** Profile UI */
    public function render_profile_ui($user) {
        if (!$user instanceof WP_User) $user = get_user_by('id', (int)$user);
        if (!$user) return;

        $opts      = $this->get_options();
        $avatar_id = (int) get_user_meta($user->ID, self::META_AVATAR_ID, true);
        $source    = get_user_meta($user->ID, self::META_SOURCE, true);
        if (!$source) {
            $source = $avatar_id ? ($opts['default_behavior'] === 'prefer_gravatar' ? 'gravatar' : 'uploaded') : 'gravatar';
        }

        $size = 128;
        $preview_url = $this->get_avatar_preview_url($user->ID, $avatar_id, $size);
        $gravatar_url = get_avatar_url($user->ID, ['size' => $size]);
        $uploaded_url = '';
        if ($avatar_id) { $tmp = $this->image_downsize_with_regen($avatar_id, 'psla_avatar'); if ($tmp) { $uploaded_url = $tmp[0]; } }
        $max_kb = (int) $opts['max_upload_kb'];
        $max_w  = (int) $opts['max_width_px'];
        $max_h  = (int) $opts['max_height_px'];
        $allow_media_btn = $this->allow_media_button_for_user($user->ID);
        ?>
        <h2><?php esc_html_e('Avatar', 'ps-local-avatars'); ?></h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th><label><?php esc_html_e('Current Avatar', 'ps-local-avatars'); ?></label></th>
                    <td>
                        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                            <img id="psla-avatar-preview" src="<?php echo esc_url($preview_url); ?>" data-default-src="<?php echo esc_url($preview_url); ?>" data-gravatar-src="<?php echo esc_url($gravatar_url); ?>" data-uploaded-src="<?php echo esc_url($uploaded_url); ?>"
                                 alt="<?php echo esc_attr($user->display_name); ?>" width="<?php echo (int)$size; ?>" height="<?php echo (int)$size; ?>"
                                 style="border-radius:50%; box-shadow:0 0 0 1px #ccd0d4; background:#fff;" />
                            <div style="min-width:320px;">
                                <?php if ($allow_media_btn): ?>
                                    <button type="button" class="button psla-upload-btn"><?php esc_html_e('Choose from Media Library / Upload', 'ps-local-avatars'); ?></button>
                                    <span style="opacity:.7; margin-left:.5em;"><?php esc_html_e('or upload from device below', 'ps-local-avatars'); ?></span>
                                <?php endif; ?>
                                <p style="margin:.6em 0 0;">
                                    <label class="button button-secondary">
                                        <?php esc_html_e('Upload from your device', 'ps-local-avatars'); ?>
                                        <input type="file" name="psla_avatar_file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                    </label>
                                    <button type="button" class="button button-secondary psla-remove-btn"><?php esc_html_e('Remove', 'ps-local-avatars'); ?></button>
                                </p>
                                <input type="hidden" name="psla_avatar_id" id="psla-avatar-id" value="<?php echo esc_attr($avatar_id ?: ''); ?>" />
                                <?php wp_nonce_field('psla_save_avatar', 'psla_nonce'); ?>
								<p class="psla-note" style="margin-top:.5em; padding:.4em .6em; border-left:3px solid #72aee6; background:#f6fbff;">
                                <strong>Heads up:</strong> In WordPress, the Gravatar preview may not change when you click <strong>“Remove”</strong> until you press <em>Update User</em>. That’s normal — your Gravatar and profile
                                 picture will update after saving. In ClassicPress you might see the preview dim immediately to let you know it's going to be removed upon clicking "Update User".
                                </p>
                                <p class="psla-note">
                                    <?php
                                    /* translators: 1: max KB, 2: max width px, 3: max height px */
                                    $message = sprintf(
                                        esc_html__( 'Square images look best. Max file size: %1$d KB. Max dimensions: %2$dx%3$d px (oversized uploads are downscaled). Allowed types: JPG, PNG, GIF, WEBP.', 'ps-local-avatars' ),
                                        (int) $max_kb,
                                        (int) $max_w,
                                        (int) $max_h
                                    );
                                    $reminder = esc_html__( 'Don\'t forget to click "Update User" to save your Gravatar.', 'ps-local-avatars' );
                                    echo esc_html( $message ) . '<br><strong>' . $reminder . '</strong>';
                                    ?>
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Avatar Source', 'ps-local-avatars'); ?></label></th>
                    <td>
                        <label><input type="radio" name="psla_avatar_source" value="uploaded" <?php checked($source, 'uploaded'); ?> />
                            <?php esc_html_e('Use my uploaded image (above)', 'ps-local-avatars'); ?></label><br/>
                        <label><input type="radio" name="psla_avatar_source" value="gravatar" <?php checked($source, 'gravatar'); ?> />
                            <?php esc_html_e('Use Gravatar', 'ps-local-avatars'); ?></label>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /** Save handler with downscale */
    public function save_profile_ui($user_id) {
        if (!isset($_POST['psla_nonce']) || !wp_verify_nonce($_POST['psla_nonce'], 'psla_save_avatar')) return;
        if (!current_user_can('edit_user', $user_id)) return;

        $opts = $this->get_options();
        $max_bytes = max(1, (int)$opts['max_upload_kb']) * 1024;
        $max_w = max(64, (int)$opts['max_width_px']);
        $max_h = max(64, (int)$opts['max_height_px']);
        $allowed_mimes = [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'gif'      => 'image/gif',
            'webp'     => 'image/webp',
        ];

        if (!empty($_FILES['psla_avatar_file']['name'])) {
            $file = $_FILES['psla_avatar_file'];
            if ($file['size'] > $max_bytes) {
                add_action('admin_notices', function(){
                    echo '<div class="notice notice-error"><p>'.esc_html__('Avatar upload failed: file too large.', 'ps-local-avatars').'</p></div>';
                });
            } else {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                add_filter('upload_mimes', function($mimes) use ($allowed_mimes){ return $allowed_mimes; }, 99);
                $uploaded = wp_handle_upload($file, ['test_form'=>false, 'mimes'=>$allowed_mimes]);
                if (!isset($uploaded['error']) && isset($uploaded['file'])) {
                    $editor = wp_get_image_editor($uploaded['file']);
                    if (!is_wp_error($editor)) {
                        $size = $editor->get_size();
                        $w = isset($size['width']) ? (int)$size['width'] : 0;
                        $h = isset($size['height']) ? (int)$size['height'] : 0;
                        if ($w > $max_w || $h > $max_h) {
                            $editor->resize($max_w, $max_h, false);
                            $editor->save($uploaded['file']);
                        }
                    }
                    $attachment = [
                        'post_mime_type' => $uploaded['type'],
                        'post_title'     => sanitize_text_field( wp_basename($uploaded['file']) ),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                        'post_author'    => $user_id,
                    ];
                    $attach_id = wp_insert_attachment($attachment, $uploaded['file']);
                    if ($attach_id && !is_wp_error($attach_id)) {
                        $meta = wp_generate_attachment_metadata($attach_id, $uploaded['file']);
                        wp_update_attachment_metadata($attach_id, $meta);
                        update_user_meta($user_id, self::META_AVATAR_ID, (int)$attach_id);
                        update_user_meta($user_id, self::META_SOURCE, 'uploaded');
                    }
                } else {
                    add_action('admin_notices', function() use ($uploaded){
                        echo '<div class="notice notice-error"><p>'.esc_html__('Avatar upload failed:', 'ps-local-avatars').' '.esc_html($uploaded['error'] ?? 'Unknown error').'</p></div>';
                    });
                }
            }
        } else {
            $avatar_id = isset($_POST['psla_avatar_id']) ? absint($_POST['psla_avatar_id']) : 0;
            if ($avatar_id) {
                update_user_meta($user_id, self::META_AVATAR_ID, $avatar_id);
            } else {
                delete_user_meta($user_id, self::META_AVATAR_ID);
            }
        }

        $source = isset($_POST['psla_avatar_source']) && $_POST['psla_avatar_source'] === 'gravatar' ? 'gravatar' : 'uploaded';
        update_user_meta($user_id, self::META_SOURCE, $source);
    }

    /** Return preview URL using square size */
    private function get_avatar_preview_url($user_id, $avatar_id, $size) {
        if ($avatar_id) {
            $img = $this->image_downsize_with_regen($avatar_id, 'psla_avatar');
            if ($img) return $img[0];
        }
        return get_avatar_url($user_id, ['size' => $size]);
    }

    /** Helper: downsize with on-demand regeneration for our sizes */
    private function image_downsize_with_regen($attachment_id, $size_name) {
        $img = image_downsize($attachment_id, $size_name);
        if ($img && !is_wp_error($img)) return $img;

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return false;

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) return false;

        $dims = [
            'psla_avatar'       => ['w'=>512, 'h'=>512, 'crop'=>true],
            'psla_avatar_small' => ['w'=>max(32,(int)$this->get_options()['small_square_px']), 'h'=>max(32,(int)$this->get_options()['small_square_px']), 'crop'=>true],
        ];
        if (!isset($dims[$size_name])) return false;

        $editor->resize($dims[$size_name]['w'], $dims[$size_name]['h'], $dims[$size_name]['crop']);
        $resized = $editor->save();
        if (is_wp_error($resized)) return false;

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) $meta = [];
        if (!isset($meta['sizes'])) $meta['sizes'] = [];
        $meta['sizes'][$size_name] = [
            'file'      => wp_basename($resized['path']),
            'width'     => $resized['width'],
            'height'    => $resized['height'],
            'mime-type' => $resized['mime-type'],
        ];
        wp_update_attachment_metadata($attachment_id, $meta);

        $uploads = wp_get_upload_dir();
        $url = trailingslashit($uploads['baseurl']) . _wp_relative_upload_path($resized['path']);
        return [$url, $resized['width'], $resized['height'], true];
    }

    /** Avatar override with small-serve in comments */
    public function filter_pre_get_avatar_data($args, $id_or_email) {
        $user = $this->resolve_user($id_or_email);
        if (!$user) return $args;

        $avatar_id = (int) get_user_meta($user->ID, self::META_AVATAR_ID, true);
        if (!$avatar_id) return $args;
$size = isset($args['size']) ? (int)$args['size'] : 96;
        $use_small = false;
        if (!is_admin() && $opts['serve_small_in_comments'] && $this->is_comment_context($id_or_email)) {
            $use_small = true;
        }
        $img = $this->image_downsize_with_regen($avatar_id, $use_small ? 'psla_avatar_small' : 'psla_avatar');
        if (!$img) {
            $img = image_downsize($avatar_id, [$size, $size]);
            if (!$img || is_wp_error($img)) return $args;
        }

        $args['url']          = $img[0];
        $args['width']        = $size;
        $args['height']       = $size;
        $args['found_avatar'] = true;
        if (empty($args['alt'])) $args['alt'] = $user->display_name;

        if (!isset($args['class']) || !is_array($args['class'])) $args['class'] = [];
        if (!in_array('psla-avatar', $args['class'], true)) $args['class'][] = 'psla-avatar';
        if ($use_small && !in_array('psla-small', $args['class'], true)) $args['class'][] = 'psla-small';

        return $args;
    }

    private function is_comment_context($id_or_email) {
        if ($id_or_email instanceof WP_Comment) return true;
        if (is_object($id_or_email) && isset($id_or_email->comment_ID)) return true;
        return false;
    }

    /** Ensure alt exists on legacy code paths */
    public function maybe_inject_alt($avatar, $id_or_email, $size, $default, $alt, $args) {
        if (!empty($alt)) return $avatar;
        $user = $this->resolve_user($id_or_email);
        if (!$user) return $avatar;

        $source = get_user_meta($user->ID, self::META_SOURCE, true);
        $avatar_id = (int) get_user_meta($user->ID, self::META_AVATAR_ID, true);
        if ($source === 'uploaded' && $avatar_id) {
            $alt_text = esc_attr($user->display_name);
            if (strpos($avatar, 'alt=') === false) {
                $avatar = preg_replace('/<img\s/', '<img alt="' . $alt_text . '" ', $avatar, 1);
            }
        }
        return $avatar;
    }

    /** Resolve a user from mixed get_avatar arg */
    private function resolve_user($id_or_email) {
        $user = false;
        if (is_numeric($id_or_email)) {
            $user = get_user_by('id', (int) $id_or_email);
        } elseif (is_string($id_or_email)) {
            if (strpos($id_or_email, '@') !== false) $user = get_user_by('email', $id_or_email);
            else $user = get_user_by('login', $id_or_email);
        } elseif (is_object($id_or_email)) {
            if (isset($id_or_email->user_id)) {
                $user = get_user_by('id', (int) $id_or_email->user_id);
            } elseif ($id_or_email instanceof WP_User) {
                $user = $id_or_email;
            } elseif ($id_or_email instanceof WP_Post && $id_or_email->post_author) {
                $user = get_user_by('id', (int) $id_or_email->post_author);
            } elseif ($id_or_email instanceof WP_Comment && $id_or_email->user_id) {
                $user = get_user_by('id', (int) $id_or_email->user_id);
            }
        }
        return $user ?: false;
    }

    /* ===================== Settings Page ===================== */

    public function add_settings_page() {
        add_options_page(
            __('PS Local Avatars', 'ps-local-avatars'),
            __('PS Local Avatars', 'ps-local-avatars'),
            'manage_options',
            'psla-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('psla_settings', self::OPT_KEY, ['sanitize_callback' => [$this, 'sanitize_options']]);

        add_settings_section('psla_general', __('General', 'ps-local-avatars'), function(){
            echo '<p>'.esc_html__('Configure defaults, performance, and role behavior.', 'ps-local-avatars').'</p>';
        }, 'psla-settings');

        add_settings_field('psla_default_behavior', __('Default behavior', 'ps-local-avatars'), function(){
            $opts = $this->get_options();
            ?>
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[default_behavior]" value="prefer_uploaded" <?php checked($opts['default_behavior'], 'prefer_uploaded'); ?>>
                <?php esc_html_e('Prefer uploaded image when available', 'ps-local-avatars'); ?></label><br>
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[default_behavior]" value="prefer_gravatar" <?php checked($opts['default_behavior'], 'prefer_gravatar'); ?>>
                <?php esc_html_e('Prefer Gravatar unless user explicitly chooses uploaded', 'ps-local-avatars'); ?></label>
            <?php
        }, 'psla-settings', 'psla_general');

        add_settings_field('psla_max_upload_kb', __('Max file size (KB)', 'ps-local-avatars'), function(){
            $opts = $this->get_options();
            ?>
            <input type="number" min="50" step="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[max_upload_kb]" value="<?php echo esc_attr((int)$opts['max_upload_kb']); ?>">
            <?php
        }, 'psla-settings', 'psla_general');

        add_settings_field('psla_max_dims', __('Max dimensions (px)', 'ps-local-avatars'), function(){
            $opts = $this->get_options();
            ?>
            <input type="number" min="64" step="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[max_width_px]" value="<?php echo esc_attr((int)$opts['max_width_px']); ?>" style="width:90px;"> x
            <input type="number" min="64" step="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[max_height_px]" value="<?php echo esc_attr((int)$opts['max_height_px']); ?>" style="width:90px;">
            <p class="description"><?php esc_html_e('Oversized uploads are downscaled before saving.', 'ps-local-avatars'); ?></p>
            <?php
        }, 'psla-settings', 'psla_general');

        add_settings_field('psla_small_serve', __('Small serve (comments)', 'ps-local-avatars'), function(){
            $opts = $this->get_options();
            ?>
            <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[serve_small_in_comments]" value="1" <?php checked(!empty($opts['serve_small_in_comments'])); ?>>
                <?php esc_html_e('Serve a smaller square avatar in comment lists to reduce bandwidth', 'ps-local-avatars'); ?></label><br>
            <label><?php esc_html_e('Small size (px):', 'ps-local-avatars'); ?>
                <input type="number" min="32" step="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[small_square_px]" value="<?php echo esc_attr((int)$opts['small_square_px']); ?>" style="width:100px;">
            </label>
            <p class="description"><?php esc_html_e('New uploads generate this size automatically; older attachments are generated on demand.', 'ps-local-avatars'); ?></p>
            <?php
        }, 'psla-settings', 'psla_general');

        add_settings_field('psla_roles', __('Disallow Media Library for roles', 'ps-local-avatars'), function(){
            $opts = $this->get_options();
            $selected = (array) $opts['disallow_media_roles'];
            $roles = get_editable_roles();
            echo '<select name="'.esc_attr(self::OPT_KEY).'[disallow_media_roles][]" multiple size="6" style="min-width:240px;">';
            foreach ($roles as $slug => $role) {
                printf('<option value="%s" %s>%s</option>',
                    esc_attr($slug),
                    selected(in_array($slug, $selected, true), true, false),
                    esc_html($role['name'].' ('.$slug.')')
                );
            }
            echo '</select>';
            echo '<p class="description">'.esc_html__('Users in these roles will not see the Media Library picker on their Profile; they can still upload from device.', 'ps-local-avatars').'</p>';
        }, 'psla-settings', 'psla_general');
    }

    public function sanitize_options($input) {
        $out = $this->get_options();
        if (isset($input['default_behavior']) && in_array($input['default_behavior'], ['prefer_uploaded','prefer_gravatar'], true)) {
            $out['default_behavior'] = $input['default_behavior'];
        }
        if (isset($input['max_upload_kb'])) {
            $kb = (int)$input['max_upload_kb'];
            $out['max_upload_kb'] = max(50, min($kb, 10240));
        }
        if (isset($input['max_width_px'])) $out['max_width_px'] = max(64, min((int)$input['max_width_px'], 4096));
        if (isset($input['max_height_px'])) $out['max_height_px'] = max(64, min((int)$input['max_height_px'], 4096));
        $out['serve_small_in_comments'] = !empty($input['serve_small_in_comments']);
        if (isset($input['small_square_px'])) $out['small_square_px'] = max(32, min((int)$input['small_square_px'], 1024));
        if (isset($input['disallow_media_roles'])) {
            $arr = $input['disallow_media_roles'];
            if (!is_array($arr)) $arr = [$arr];
            $out['disallow_media_roles'] = array_values(array_unique(array_map('sanitize_key', $arr)));
        } else {
            $out['disallow_media_roles'] = [];
        }
        return $out;
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('PS Local Avatars', 'ps-local-avatars'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('psla_settings');
                do_settings_sections('psla-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* ===================== Shortcode & Block ===================== */

    public function shortcode_psla_avatar($atts) {
        $atts = shortcode_atts([
            'user'  => '',
            'size'  => 96,
            'class' => '',
            'alt'   => '',
        ], $atts, 'psla_avatar');

        $user_arg = $atts['user'] !== '' ? $atts['user'] : get_current_user_id();
        $size = max(16, (int)$atts['size']);
        $class = trim((string)$atts['class']);
        $alt = (string)$atts['alt'];

        $args = ['class' => array_filter(['psla-shortcode-avatar', $class])];
        if ($alt !== '') $args['alt'] = $alt;

        return get_avatar($user_arg, $size, '', $alt, $args);
    }

    public function register_block() {
        if (!function_exists('register_block_type')) return;
        register_block_type('psla/avatar', [
            'render_callback' => [$this, 'render_block_avatar'],
            'attributes'      => [
                'user'      => ['type' => 'string', 'default' => ''],
                'size'      => ['type' => 'number', 'default' => 96],
                'className' => ['type' => 'string', 'default' => ''],
                'alt'       => ['type' => 'string', 'default' => ''],
            ]
        ]);
    }

    public function render_block_avatar($attributes, $content = '') {
        $atts = wp_parse_args($attributes, ['user'=>'', 'size'=>96, 'className'=>'', 'alt'=>'']);
        $user_arg = $atts['user'] !== '' ? $atts['user'] : get_current_user_id();
        $size = max(16, (int)$atts['size']);
        $class = trim((string)$atts['className']);
        $alt = (string)$atts['alt'];

        $args = ['class' => array_filter(['psla-block-avatar', $class])];
        if ($alt !== '') $args['alt'] = $alt;

        return get_avatar($user_arg, $size, '', $alt, $args);
    }

    /* ===================== REST API ===================== */

    public function register_rest_routes() {
        $ns = 'psla/v1';

        register_rest_route($ns, '/avatar', [
            'methods'  => WP_REST_Server::CREATABLE,
            'args'     => ['user_id'=>['type'=>'integer','required'=>false]],
            'permission_callback' => function($request){
                $user_id = (int) ($request['user_id'] ?? 0);
                if ($user_id && $user_id !== get_current_user_id()) {
                    return current_user_can('edit_user', $user_id);
                }
                return is_user_logged_in();
            },
            'callback' => [$this, 'rest_upload_avatar'],
        ]);
        register_rest_route($ns, '/avatar/from-media', [
            'methods'  => WP_REST_Server::CREATABLE,
            'args'     => ['user_id'=>['type'=>'integer','required'=>false], 'attachment_id'=>['type'=>'integer','required'=>true]],
            'permission_callback' => function($request){
                $user_id = (int) ($request['user_id'] ?? 0);
                if ($user_id && $user_id !== get_current_user_id()) {
                    return current_user_can('edit_user', $user_id);
                }
                return is_user_logged_in();
            },
            'callback' => [$this, 'rest_set_avatar_from_media'],
        ]);
        register_rest_route($ns, '/avatar', [
            'methods'  => WP_REST_Server::DELETABLE,
            'args'     => ['user_id'=>['type'=>'integer','required'=>false]],
            'permission_callback' => function($request){
                $user_id = (int) ($request['user_id'] ?? 0);
                if ($user_id && $user_id !== get_current_user_id()) {
                    return current_user_can('edit_user', $user_id);
                }
                return is_user_logged_in();
            },
            'callback' => [$this, 'rest_remove_avatar'],
        ]);
        register_rest_route($ns, '/avatar/source', [
            'methods'  => WP_REST_Server::CREATABLE,
            'args'     => ['user_id'=>['type'=>'integer','required'=>false], 'source'=>['type'=>'string','enum'=>['uploaded','gravatar'],'required'=>true]],
            'permission_callback' => function($request){
                $user_id = (int) ($request['user_id'] ?? 0);
                if ($user_id && $user_id !== get_current_user_id()) {
                    return current_user_can('edit_user', $user_id);
                }
                return is_user_logged_in();
            },
            'callback' => [$this, 'rest_set_source'],
        ]);
    }

    public function rest_upload_avatar(WP_REST_Request $request) {
        $current = get_current_user_id();
        $user_id = (int) ($request['user_id'] ?: $current);
        if (!$user_id) return new WP_Error('psla_no_user', __('No user.', 'ps-local-avatars'), ['status'=>400]);

        $opts = $this->get_options();
        $max_bytes = max(1, (int)$opts['max_upload_kb']) * 1024;
        $max_w = max(64, (int)$opts['max_width_px']);
        $max_h = max(64, (int)$opts['max_height_px']);
        $allowed_mimes = ['jpg|jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];

        if (empty($_FILES['file']['name'])) return new WP_Error('psla_no_file', __('No file uploaded.', 'ps-local-avatars'), ['status'=>400]);
        $file = $_FILES['file'];
        if ($file['size'] > $max_bytes) return new WP_Error('psla_file_too_large', __('File too large.', 'ps-local-avatars'), ['status'=>400]);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        add_filter('upload_mimes', function($m){ return $allowed_mimes; }, 99);
        $uploaded = wp_handle_upload($file, ['test_form'=>false, 'mimes'=>$allowed_mimes]);
        if (isset($uploaded['error'])) return new WP_Error('psla_upload_error', $uploaded['error'], ['status'=>400]);

        $editor = wp_get_image_editor($uploaded['file']);
        if (!is_wp_error($editor)) {
            $size = $editor->get_size();
            $w = isset($size['width']) ? (int)$size['width'] : 0;
            $h = isset($size['height']) ? (int)$size['height'] : 0;
            if ($w > $max_w || $h > $max_h) {
                $editor->resize($max_w, $max_h, false);
                $editor->save($uploaded['file']);
            }
        }

        $attachment = ['post_mime_type'=>$uploaded['type'],'post_title'=>sanitize_text_field(wp_basename($uploaded['file'])),'post_content'=>'','post_status'=>'inherit','post_author'=>$user_id];
        $att_id = wp_insert_attachment($attachment, $uploaded['file']);
        if (!$att_id || is_wp_error($att_id)) return new WP_Error('psla_attach_error', __('Could not create attachment.', 'ps-local-avatars'), ['status'=>500]);
        $meta = wp_generate_attachment_metadata($att_id, $uploaded['file']);
        wp_update_attachment_metadata($att_id, $meta);

        update_user_meta($user_id, self::META_AVATAR_ID, (int)$att_id);
        update_user_meta($user_id, self::META_SOURCE, 'uploaded');
        $url = get_avatar_url($user_id, ['size'=>128]);
        return new WP_REST_Response(['success'=>true, 'attachment_id'=>(int)$att_id, 'avatar'=>$url], 200);
    }

    public function rest_set_avatar_from_media(WP_REST_Request $request) {
        $current = get_current_user_id();
        $user_id = (int) ($request['user_id'] ?: $current);
        $att_id  = (int) $request['attachment_id'];
        if (!$user_id || !$att_id) return new WP_Error('psla_bad_request', __('Missing user or attachment.', 'ps-local-avatars'), ['status'=>400]);
        if (get_post_type($att_id) !== 'attachment') return new WP_Error('psla_not_attachment', __('Invalid attachment.', 'ps-local-avatars'), ['status'=>400]);

        update_user_meta($user_id, self::META_AVATAR_ID, $att_id);
        update_user_meta($user_id, self::META_SOURCE, 'uploaded');
        $url = get_avatar_url($user_id, ['size'=>128]);
        return new WP_REST_Response(['success'=>true, 'attachment_id'=>$att_id, 'avatar'=>$url], 200);
    }

    public function rest_remove_avatar(WP_REST_Request $request) {
        $current = get_current_user_id();
        $user_id = (int) ($request['user_id'] ?: $current);
        if (!$user_id) return new WP_Error('psla_no_user', __('No user.', 'ps-local-avatars'), ['status'=>400]);

        delete_user_meta($user_id, self::META_AVATAR_ID);
        update_user_meta($user_id, self::META_SOURCE, 'gravatar');
        $url = get_avatar_url($user_id, ['size'=>128]);
        return new WP_REST_Response(['success'=>true, 'avatar'=>$url], 200);
    }

    public function rest_set_source(WP_REST_Request $request) {
        $current = get_current_user_id();
        $user_id = (int) ($request['user_id'] ?: $current);
        $source  = (string) $request['source'];
        if (!$user_id || !in_array($source, ['uploaded','gravatar'], true)) return new WP_Error('psla_bad_request', __('Bad request.', 'ps-local-avatars'), ['status'=>400]);

        update_user_meta($user_id, self::META_SOURCE, $source);
        $url = get_avatar_url($user_id, ['size'=>128]);
        return new WP_REST_Response(['success'=>true, 'source'=>$source, 'avatar'=>$url], 200);
    }
	
	// Return a local avatar URL on all code paths (covers ClassicPress Admin Bar/profile)
    public function psla_get_avatar_url($url, $id_or_email, $args) {
        $user = $this->resolve_user($id_or_email);
        if (!$user) return $url;

       $avatar_id = (int) get_user_meta($user->ID, self::META_AVATAR_ID, true);
       if (!$avatar_id) return $url;

       $size = isset($args['size']) ? (int) $args['size'] : 96;

    // Prefer our named sizes; fall back gracefully
       $opts = $this->get_options();
       $use_small = (!is_admin() && !empty($opts['serve_small_in_comments']) && $size <= 96 && $this->is_comment_context($id_or_email));

       $img = $this->image_downsize_with_regen($avatar_id, $use_small ? 'psla_avatar_small' : 'psla_avatar');
       if (!$img) {
        $img = image_downsize($avatar_id, [$size, $size]);
    }
       if (is_array($img) && !empty($img[0])) return $img[0];

       $full = wp_get_attachment_image_src($avatar_id, 'full');
       return ($full && !empty($full[0])) ? $full[0] : $url;
}

// Keep avatars visible in admin pages and when the Admin Bar shows (ClassicPress safeguard)
public function psla_force_admin_avatars($value) {
    if (is_admin()) return 1;
    if (function_exists('is_admin_bar_showing') && is_admin_bar_showing()) return 1;
    return $value;
}

}

PS_Local_Avatars::instance();

}
