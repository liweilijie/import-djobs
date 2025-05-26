<?php

if (!defined('DEEPSEEK_API_KEY')) {
    error_log('[JIWU] DeepSeek API KEY 未定义');
    return;
}

$api_key = DEEPSEEK_API_KEY;

if (!defined('DEEPSEEK_API_URL')) {
    error_log('[JIWU] DeepSeek API URL 未定义');
    return;
}

$api_key = DEEPSEEK_API_URL;


function deepseek_translate_to_chinese($text): string
{
    // 准备API请求参数
    $messages = array(
        array('role' => 'system', 'content' => '你是一名专业的翻译助手。'),
        array('role' => 'user', 'content' => '请将以下英文内容翻译为简体中文：' . "\n\n" . $text)
    );
    $data = array(
        'model' => 'deepseek-chat',
        'messages' => $messages,
        'stream' => false
    );
    $args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . DEEPSEEK_API_KEY
        ),
        'body' => json_encode($data),
        'timeout' => 300
    );

    // 重试机制：尝试请求2次
    for ($i = 0; $i < 5; $i++) {
        $response = wp_remote_post(DEEPSEEK_API_URL, $args);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
            break;
        }
        sleep(5); // 等待后重试
    }
    if (is_wp_error($response)) {
        error_log('[JIWU AI Translator] DeepSeek 请求失败：' . $response->get_error_message());
        return ''; // 或者返回原文等
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['choices'][0]['message']['content'])) {
        return trim($body['choices'][0]['message']['content']);
    } else {
        error_log('[JIWU AI Translator] DeepSeek 返回数据异常：' . wp_remote_retrieve_body($response));
        return '';
    }
}

function jiwu_translate_with_wpml_and_deepseek($post_id, $target_lang = 'zh-hans') {
    if (!defined('ICL_SITEPRESS_VERSION') || !has_filter('wpml_element_trid')) {
        error_log('[JIWU] WPML not initialized or filters unavailable');
        return false;
    }

    $post = get_post($post_id);
    if (!$post || $post->post_status === 'trash') {
        error_log("[JIWU] Post $post_id 不存在或被删除");
        return false;
    }

    // 1. WPML 复制翻译版本
    do_action('wpml_admin_make_post_duplicates', $post_id);
    sleep(1); // 留出构建时间

    // 2. 获取翻译版本 ID
    $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_property');
    $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_property');

    if (!isset($translations[$target_lang])) {
        error_log("[JIWU] 翻译组中未找到目标语言 {$target_lang}");
        return false;
    }

//    error_log('$trid:' . $trid);
//    error_log(print_r($translations, true));

    $translated_post_id = $translations[$target_lang]->element_id;

//    error_log($translated_post_id);

    // ✅ 在此处插入语言切换
    do_action('wpml_switch_language', $target_lang);
    // 3. 解锁同步，允许修改内容
    delete_post_meta($translated_post_id, '_icl_lang_duplicate_of');

    // 4. 使用 DeepSeek 翻译内容
//    $translated_title   = deepseek_translate_to_chinese($post->post_title);
    $translated_title   = $post->post_title;
    $translated_content = deepseek_translate_to_chinese($post->post_content);

    wp_update_post([
        'ID'           => $translated_post_id,
        'post_title'   => $translated_title,
        'post_content' => $translated_content,
        'post_status'  => 'publish',
    ]);

    // 5. 同步 postmeta
    $meta_data = get_post_meta($post_id);
    foreach ($meta_data as $meta_key => $meta_values) {
        // 跳过 WPML 内部字段
        if (strpos($meta_key, '_icl_') === 0 || $meta_key === '_wpml_media_has_media') {
            continue;
        }
        foreach ($meta_values as $meta_value) {
//            error_log('add post meta ' . $meta_key . ' ' . $meta_value);
            add_post_meta($translated_post_id, $meta_key, maybe_unserialize($meta_value));
        }
    }

    // 6. 复制特色图片
    $thumb_id = get_post_thumbnail_id($post_id);
    if ($thumb_id) {
        set_post_thumbnail($translated_post_id, $thumb_id);
//        error_log('set thumbnail: ' . $thumb_id);
    }

    error_log("[JIWU] 翻译完成: 原文 $post_id => 中文 $translated_post_id");
    return $translated_post_id;
}


function translate_property_chinese($source_post_id): int|WP_Error
{
    $new_post_id = jiwu_translate_with_wpml_and_deepseek($source_post_id, 'zh-hans');
    return $new_post_id;
}

