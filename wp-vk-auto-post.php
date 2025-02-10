<?php
/*
Plugin Name: WP VK Auto Post
Plugin URI: https://gitlab.alekseysemenov.ru/els777/wp-vk-auto-post
Description: Автоматическая публикация постов в группу VK.
Version: 1.0
Author: Aleksey Semyonov
Author URI: https://alekseysemenov.ru
License: MIT
Text Domain: wp-vk-auto-post
*/

if (!defined('ABSPATH')) {
    exit;
}

// Добавляем страницу настроек
function wp_vk_auto_post_menu() {
    add_options_page('WP VK Auto Post', 'WP VK Auto Post', 'manage_options', 'wp-vk-auto-post', 'wp_vk_auto_post_settings_page');
}
add_action('admin_menu', 'wp_vk_auto_post_menu');

// Форма настроек плагина
function wp_vk_auto_post_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['submit'])) {
        update_option('wp_vk_access_token', sanitize_text_field($_POST['wp_vk_access_token']));
        update_option('wp_vk_group_id', sanitize_text_field($_POST['wp_vk_group_id']));

        $selected_categories = isset($_POST['wp_vk_post_categories']) ? 
            array_map('intval', (array)$_POST['wp_vk_post_categories']) : [];
        update_option('wp_vk_post_categories', $selected_categories);

        echo '<div class="updated"><p>Настройки сохранены.</p></div>';
    }

    $access_token = get_option('wp_vk_access_token', '');
    $group_id = get_option('wp_vk_group_id', '');
    $selected_categories = get_option('wp_vk_post_categories', []);
    $categories = get_categories(['hide_empty' => false]);
    ?>
    <div class="wrap">
        <h1>Настройки WP VK Auto Post</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th>VK Access Token:</th>
                    <td><input type="text" name="wp_vk_access_token" value="<?php echo esc_attr($access_token); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>ID группы VK:</th>
                    <td><input type="text" name="wp_vk_group_id" value="<?php echo esc_attr($group_id); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Категории для публикации:</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_vk_all_categories" <?php checked(empty($selected_categories)); ?>>
                            Публиковать все категории
                        </label>
                        <select name="wp_vk_post_categories[]" multiple style="height: 150px;" <?php echo empty($selected_categories) ? 'disabled' : ''; ?>>
                            <?php foreach ($categories as $category) { ?>
                                <option value="<?php echo esc_attr($category->term_id); ?>" <?php selected(in_array($category->term_id, $selected_categories)); ?>>
                                    <?php echo esc_html($category->name); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <p class="description">Выберите несколько категорий, держа клавишу Ctrl или Shift.</p>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="submit" value="Сохранить настройки" class="button button-primary"></p>
        </form>
    </div>
    <script>
        jQuery(document).ready(function($) {
            $('input[name="wp_vk_all_categories"]').change(function() {
                $('select[name="wp_vk_post_categories[]"]').prop('disabled', this.checked);
            });
        });
    </script>
    <?php
}

// Функция загрузки изображения в ВК
function wp_vk_upload_image($image_url, $group_id, $access_token) {
    error_log('Uploading image to VK for URL: ' . $image_url);

    $api_url = "https://api.vk.com/method/photos.getWallUploadServer?group_id=" . str_replace('-', '', $group_id) . "&access_token=$access_token&v=5.131";
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        error_log('VK API Error (getWallUploadServer): ' . $response->get_error_message());
        return false;
    }

    $response_data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($response_data['error'])) {
        error_log('VK API Error: ' . $response_data['error']['error_msg']);
        return false;
    }

    $temp_file = download_url($image_url);
    if (is_wp_error($temp_file)) {
        error_log('Ошибка загрузки изображения: ' . $temp_file->get_error_message());
        return false;
    }

    $upload_url = $response_data['response']['upload_url'];
    $file_args = [
        'body' => [
            'file1' => new CURLFile($temp_file, mime_content_type($temp_file), basename($temp_file))
        ],
        'timeout' => 30
    ];

    $upload_response = wp_remote_post($upload_url, $file_args);
    unlink($temp_file);

    if (is_wp_error($upload_response)) {
        error_log('VK Upload Error: ' . $upload_response->get_error_message());
        return false;
    }

    $upload_data = json_decode(wp_remote_retrieve_body($upload_response), true);

    $save_url = "https://api.vk.com/method/photos.saveWallPhoto?group_id=" . str_replace('-', '', $group_id) 
        . "&photo={$upload_data['photo']}"
        . "&server={$upload_data['server']}"
        . "&hash={$upload_data['hash']}"
        . "&access_token=$access_token&v=5.131";

    $save_response = wp_remote_get($save_url);

    if (is_wp_error($save_response)) {
        error_log('VK Save Error: ' . $save_response->get_error_message());
        return false;
    }

    $save_data = json_decode(wp_remote_retrieve_body($save_response), true);

    if (isset($save_data['error'])) {
        error_log('VK API Error (saveWallPhoto): ' . $save_data['error']['error_msg']);
        return false;
    }

    return "photo{$save_data['response'][0]['owner_id']}_{$save_data['response'][0]['id']}";
}

// Функция форматирования тегов для VK
function wp_vk_format_tags($tags) {
    if (!$tags) return '';
    $formatted_tags = array_map(function($tag) {
        $tag_name = str_replace(' ', '_', mb_strtolower($tag->name, 'UTF-8'));
        $tag_name = preg_replace('/[^a-zа-я0-9_]/u', '', $tag_name);
        return '#' . $tag_name;
    }, $tags);

    $formatted_tags = array_filter($formatted_tags, function($tag) {
        return strlen($tag) > 3;
    });

    return implode(' ', $formatted_tags);
}

// Функция публикации поста в VK
function wp_vk_auto_post_publish($new_status, $old_status, $post) {
    // Логирование для отладки
    error_log('Transition post status: ' . $post->ID . ' - New status: ' . $new_status . ', Old status: ' . $old_status);

    // Проверяем, что это действительно публикация поста (статус стал "publish")
    if ($new_status !== 'publish' || $old_status === 'publish' || $post->post_type !== 'post') {
        error_log('Skipping post: Post is not being published or is not a standard post.');
        return;
    }

    // Получаем настройки плагина
    $access_token = get_option('wp_vk_access_token');
    $group_id = '-' . get_option('wp_vk_group_id');
    $selected_categories = get_option('wp_vk_post_categories', []);

    if (!$access_token || !$group_id) {
        error_log('VK Auto Post: Missing access token or group ID');
        return;
    }

    // Проверяем категории поста
    $post_categories = wp_get_post_categories($post->ID);
    if (!empty($selected_categories) && !array_intersect($post_categories, $selected_categories)) {
        error_log('Skipping post: Post categories do not match selected categories.');
        return;
    }

    // Обработка контента
    $raw_content = $post->post_content;

    // Разделение по тегу <!--more-->
    if (strpos($raw_content, '<!--more-->') !== false) {
        $content_parts = explode('<!--more-->', $raw_content, 2);
        $raw_content = $content_parts[0];
    }

    // Применение фильтров WordPress
    $processed_content = apply_filters('the_content', $raw_content);

    // Форматирование контента
    $content = str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $processed_content);
    $content = preg_replace('/<p[^>]*>/', '', $content);
    $content = wp_strip_all_tags($content);
    $content = str_replace('&nbsp;', ' ', $content);
    $content = preg_replace('/[^\S\n]+/', ' ', $content);
    $content = preg_replace('/^ +/m', '', $content);
    $content = preg_replace('/ +$/m', '', $content);
    $content = preg_replace('/\n{3,}/', "\n\n", $content);

    // Формирование сообщения
    $title = get_the_title($post->ID);
    $shortlink = wp_get_shortlink($post->ID);
    $tags_text = wp_vk_format_tags(get_the_tags($post->ID));

    $message = "{$title}\n\n{$content}\n\n{$shortlink}\n{$tags_text}";
    $message = mb_substr($message, 0, 9000, 'UTF-8');

    // Отправка в VK
    $params = [
        'owner_id' => $group_id,
        'message' => $message,
        'access_token' => $access_token,
        'v' => '5.131'
    ];

    // Добавление изображения
    $image_url = get_the_post_thumbnail_url($post->ID, 'full');
    $attachments = [];
    if ($image_url) {
        $attachment = wp_vk_upload_image($image_url, $group_id, $access_token);
        if ($attachment) {
            $attachments[] = $attachment;
        }
    }

    if (!empty($attachments)) {
        $params['attachments'] = implode(',', $attachments);
    }

    $response = wp_remote_post('https://api.vk.com/method/wall.post', ['body' => $params]);

    if (is_wp_error($response)) {
        error_log('VK Post Error: ' . $response->get_error_message());
        return;
    }

    $response_data = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($response_data['error'])) {
        error_log('VK API Error (wall.post): ' . $response_data['error']['error_msg']);
    } else {
        error_log('Post successfully published to VK for post ID: ' . $post->ID);
    }
}

// Используем хук transition_post_status вместо publish_post
add_action('transition_post_status', 'wp_vk_auto_post_publish', 10, 3);