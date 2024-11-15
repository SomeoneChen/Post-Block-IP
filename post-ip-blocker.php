<?php
/*
Plugin Name: Post IP Blocker
Plugin URI: https://github.com/SomeoneChen/Post-Block-IP
Description: Block specific articles based on IP addresses and display restriction messages or redirect users to the homepage.
Version: 1.0
Author: ChatGPT
Author URI: https://example.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: post-ip-blocker
*/

// 在文章编辑页面添加选项
function pib_add_meta_box() {
    add_meta_box('pib_meta_box', esc_html__('IP Blocking Settings', 'post-ip-blocker'), 'pib_meta_box_callback', 'post', 'side');
}
add_action('add_meta_boxes', 'pib_add_meta_box');

function pib_meta_box_callback($post) {
    $is_blocked = get_post_meta($post->ID, '_pib_is_blocked', true);
    echo '<label><input type="checkbox" name="pib_is_blocked" value="1" ' . checked($is_blocked, 1, false) . '> ' . esc_html__('Enable IP Blocking', 'post-ip-blocker') . '</label>';
}

function pib_save_post($post_id) {
    // 验证 nonce，防止 CSRF
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    if (isset($_POST['pib_is_blocked_nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['pib_is_blocked_nonce']));
        if (!wp_verify_nonce($nonce, 'pib_save_post')) return;
    } else {
        return;
    }

    if (array_key_exists('pib_is_blocked', $_POST)) {
        update_post_meta($post_id, '_pib_is_blocked', 1);
    } else {
        delete_post_meta($post_id, '_pib_is_blocked');
    }
}
add_action('save_post', 'pib_save_post');

// 添加 IP 屏蔽设置页面
function pib_add_admin_menu() {
    add_options_page(esc_html__('IP Blocker Settings', 'post-ip-blocker'), 'IP Blocker', 'manage_options', 'post-ip-blocker', 'pib_options_page');
}
add_action('admin_menu', 'pib_add_admin_menu');

function pib_settings_init() {
    register_setting('pib_options', 'pib_blocked_ips'); // 注册设置字段
    add_settings_section('pib_section', esc_html__('IP Blocking Settings', 'post-ip-blocker'), null, 'post-ip-blocker');
    add_settings_field('pib_blocked_ips', esc_html__('Blocked IP Ranges', 'post-ip-blocker'), 'pib_blocked_ips_render', 'post-ip-blocker', 'pib_section');
}
add_action('admin_init', 'pib_settings_init');

function pib_blocked_ips_render() {
    $blocked_ips = get_option('pib_blocked_ips', '');
    echo '<textarea name="pib_blocked_ips" rows="5" cols="50">' . esc_textarea($blocked_ips) . '</textarea>';
    echo '<p>' . esc_html__('Enter each IP range on a new line. CIDR notation and wildcards (*) are supported. Example: 192.168.1.* or 192.168.1.0/24', 'post-ip-blocker') . '</p>';
}

function pib_options_page() {
    ?>
    <form action="options.php" method="post">
        <h2><?php esc_html_e('IP Blocker Settings', 'post-ip-blocker'); ?></h2>
        <?php
        settings_fields('pib_options');
        do_settings_sections('post-ip-blocker');
        submit_button();
        ?>
    </form>
    <?php
}

// 检查用户 IP 是否在屏蔽的 IP 段中
function pib_is_ip_blocked($user_ip, $blocked_ips) {
    $blocked_ips_array = explode("\n", $blocked_ips);
    foreach ($blocked_ips_array as $blocked_ip) {
        $blocked_ip = trim($blocked_ip);
        if (empty($blocked_ip)) continue;

        if (strpos($blocked_ip, '*') !== false) {
            $pattern = str_replace('*', '[0-9]+', $blocked_ip);
            if (preg_match('/^' . str_replace('.', '\.', $pattern) . '$/', $user_ip)) {
                return true;
            }
        } elseif (strpos($blocked_ip, '/') !== false) {
            if (pib_ip_in_cidr($user_ip, $blocked_ip)) {
                return true;
            }
        } else {
            if ($user_ip === $blocked_ip) {
                return true;
            }
        }
    }
    return false;
}

function pib_ip_in_cidr($ip, $cidr) {
    list($subnet, $mask) = explode('/', $cidr);
    return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) == ip2long($subnet);
}

function pib_exclude_blocked_posts($query) {
    if ($query->is_home() && $query->is_main_query()) {
        $user_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $blocked_ips = get_option('pib_blocked_ips', '');
        if (pib_is_ip_blocked($user_ip, $blocked_ips)) {
            // 使用 WP_Query 查询所有启用了屏蔽的文章ID
            $blocked_posts_query = new WP_Query(array(
                'post_type' => 'post',
                'meta_query' => array(
                    array(
                        'key' => '_pib_is_blocked',
                        'value' => '1',
                        'compare' => '='
                    )
                ),
                'fields' => 'ids',
                'posts_per_page' => -1 // 获取所有符合条件的文章
            ));

            $blocked_posts = $blocked_posts_query->posts;
            if (!empty($blocked_posts)) {
                $query->set('post__not_in', $blocked_posts);
            }
        }
    }
}
add_action('pre_get_posts', 'pib_exclude_blocked_posts');

// 在单篇文章页替换标题和内容，并隐藏评论区并跳转
function pib_modify_single_post() {
    if (is_single()) {
        global $post;
        $user_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $blocked_ips = get_option('pib_blocked_ips', '');
        $is_blocked = get_post_meta($post->ID, '_pib_is_blocked', true);

        if ($is_blocked && pib_is_ip_blocked($user_ip, $blocked_ips)) {
            // 替换标题和内容，隐藏评论区
            add_filter('the_title', 'pib_blocked_title', 10, 2);
            add_filter('the_content', 'pib_blocked_content');
            add_filter('comments_open', '__return_false');

            // 设置 3 秒后跳转
            echo '<script>setTimeout(function() { window.location.href = "' . esc_url(home_url()) . '"; }, 3000);</script>';
        }
    }
}
add_action('template_redirect', 'pib_modify_single_post');

// 替换屏蔽文章的标题
function pib_blocked_title($title, $post_id) {
    if (is_single($post_id)) {
        $user_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $blocked_ips = get_option('pib_blocked_ips', '');
        $is_blocked = get_post_meta($post_id, '_pib_is_blocked', true);
        if ($is_blocked && pib_is_ip_blocked($user_ip, $blocked_ips)) {
            return esc_html__('Content Blocked', 'post-ip-blocker');
        }
    }
    return $title;
}

// 替换屏蔽文章的内容
function pib_blocked_content($content) {
    $user_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $blocked_ips = get_option('pib_blocked_ips', '');
    if (pib_is_ip_blocked($user_ip, $blocked_ips) && get_post_meta(get_the_ID(), '_pib_is_blocked', true)) {
        return esc_html__('Your IP range has been blocked. Please try changing your IP and refresh. Redirecting to the homepage in 3 seconds...', 'post-ip-blocker');
    }
    return $content;
}
