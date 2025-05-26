<?php

class JiwuDeepSeekTranslator
{
    private string $api_key;
    private string $api_url;
    private int $retry_times;
    private int $retry_interval;

    public function __construct(string $api_key = '', string $api_url = '', int $retry_times = 5, int $retry_interval = 5)
    {
        if (!$api_key && defined('DEEPSEEK_API_KEY')) {
            $api_key = DEEPSEEK_API_KEY;
        }
        if (!$api_url && defined('DEEPSEEK_API_URL')) {
            $api_url = DEEPSEEK_API_URL;
        }

        if (!$api_key || !$api_url) {
            error_log('[JIWU] DeepSeek API KEY 或 URL 未定义');
            return;
        }

        $this->api_key = $api_key;
        $this->api_url = $api_url;
        $this->retry_times = $retry_times;
        $this->retry_interval = $retry_interval;
    }

    public function translateToChinese(string $text): string
    {
        $messages = [
            ['role' => 'system', 'content' => '你是一名专业的翻译助手。'],
            ['role' => 'user', 'content' => "请将以下英文内容翻译为简体中文：\n\n" . $text]
        ];

        $data = [
            'model' => 'deepseek-chat',
            'messages' => $messages,
            'stream' => false
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body' => json_encode($data),
            'timeout' => 300
        ];

        for ($i = 0; $i < $this->retry_times; $i++) {
            $response = wp_remote_post($this->api_url, $args);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                break;
            }
            sleep($this->retry_interval);
        }

        if (is_wp_error($response)) {
            error_log('[JIWU AI Translator] DeepSeek 请求失败：' . $response->get_error_message());
            return '';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['choices'][0]['message']['content'])) {
            return trim($body['choices'][0]['message']['content']);
        }

        error_log('[JIWU AI Translator] DeepSeek 返回数据异常：' . wp_remote_retrieve_body($response));
        return '';
    }

    public function translatePostWithWPML(int $post_id, string $target_lang = 'zh-hans')
    {
        if (!defined('ICL_SITEPRESS_VERSION') || !has_filter('wpml_element_trid')) {
            error_log('[JIWU] WPML not initialized or filters unavailable');
            return false;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash') {
            error_log("[JIWU] Post $post_id 不存在或被删除");
            return false;
        }

        do_action('wpml_admin_make_post_duplicates', $post_id);
        sleep(1);

        $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_property');
        $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_property');

        if (!isset($translations[$target_lang])) {
            error_log("[JIWU] 翻译组中未找到目标语言 {$target_lang}");
            return false;
        }

        $translated_post_id = $translations[$target_lang]->element_id;

        do_action('wpml_switch_language', $target_lang);
        delete_post_meta($translated_post_id, '_icl_lang_duplicate_of');

        $translated_title = $post->post_title;
        $translated_content = $this->translateToChinese($post->post_content);

        wp_update_post([
            'ID' => $translated_post_id,
            'post_title' => $translated_title,
            'post_content' => $translated_content,
            'post_status' => 'publish',
        ]);

        $meta_data = get_post_meta($post_id);
        foreach ($meta_data as $meta_key => $meta_values) {
            if (strpos($meta_key, '_icl_') === 0 || $meta_key === '_wpml_media_has_media') {
                continue;
            }
            foreach ($meta_values as $meta_value) {
                add_post_meta($translated_post_id, $meta_key, maybe_unserialize($meta_value));
            }
        }

        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            set_post_thumbnail($translated_post_id, $thumb_id);
        }

        error_log("[JIWU] 翻译完成: 原文 $post_id => 中文 $translated_post_id");
        return $translated_post_id;
    }

    public static function translateProperty(int $post_id): int|WP_Error|false
    {
        $translator = new self();
        return $translator->translatePostWithWPML($post_id, 'zh-hans');
    }
}

// 调用示例：
// $translated_id = JiwuDeepSeekTranslator::translateProperty($post_id);

