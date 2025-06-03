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
            [
                'role' => 'system',
                'content' => implode("\n", [
                    '你是一名专业的中英翻译助手。',
                    '请严格遵循以下翻译准则：',
                    '1. 仅返回最终译文，不要包含任何解释、注释或多语言对照；',
                    '2. 禁用任何“注：”、“说明：”等提示性文字；',
                    '3. 保持译文与原文的字数风格尽量匹配；',
                    '4. 术语使用专业，尤其针对房地产领域；',
                    '5. 保证翻译流畅自然，适合网站展示；',
                    '6. 使用符合现代中文网页标准的表达方式，避免晦涩或生硬的句式；',
                ])
            ],
            [
                'role' => 'user',
                'content' => "请将以下英文内容翻译为简体中文：\n\n" . $text
            ]
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
        // 避免自动保存和修订版触发翻译
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
            error_log('autosave defined and return.');
            return false;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            error_log('is post revision and return.');
            return false;
        }

        // 获取当前文章语言，确保只针对英文（默认）文章进行翻译
        $lang_details = apply_filters('wpml_post_language_details', NULL, $post_id);
        if ( empty($lang_details) || !isset($lang_details['language_code']) ) {
            error_log('lang details error.');
            return false;
        }
        $current_lang = $lang_details['language_code'];
        // 仅在英文版本保存时才执行，避免中文版本也触发
        if ( $current_lang !== 'en' ) {
            error_log('不是英文版本不处理。' . $current_lang . ' id ' . $post_id);
            return false;
        }

        if (!defined('ICL_SITEPRESS_VERSION') || !has_filter('wpml_element_trid')) {
            error_log('[JIWU] WPML not initialized or filters unavailable');
            return false;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'property' || $post->post_status === 'trash') {
            error_log("[JIWU] Post $post_id 不存在或者类型不对或被删除");
            return false;
        }

        // 获取该文章所在的翻译组（trid）
        $trid = apply_filters('wpml_element_trid', NULL, $post_id, 'post_property');
        if ( empty($trid) ) {
            error_log("WPML: 无法获取文章 $post_id 的翻译组 trid");
            return false;
        }
        // 获取该组内所有语言的文章翻译信息
        $translations = apply_filters('wpml_get_element_translations', NULL, $trid, 'post_property');

        // 如果没有中文翻译，使用 WPML 提供的 hook 创建所有语言的副本
        if ( empty($translations['zh-hans']->element_id) ) {
            do_action('wpml_make_post_duplicates', $post_id, [
                'from_lang' => 'en',
                'force'     => false
            ]);
            // 重新获取翻译信息
            $translations = apply_filters('wpml_get_element_translations', NULL, $trid, 'post_property');
        }

        // 获取中文翻译的文章 ID
        if ( isset($translations[$target_lang]) && !empty($translations['zh-hans']->element_id) ) {
            $translated_post_id = intval($translations[$target_lang]->element_id);
        } else {
            error_log("WPML: 文章 $post_id 的中文翻译未找到");
            return false;
        }

        // ❗必须先切换语言上下文
        do_action('wpml_switch_language', $target_lang);

        // ❗必须先解除绑定
        delete_post_meta($translated_post_id, '_icl_lang_duplicate_of');


        $original_content = $post->post_content;
        try {
            $translated_content = $this->translateToChinese( $original_content );
        } catch ( Exception $e ) {
            error_log("翻译文章 $post_id 内容出错：" . $e->getMessage());
            return false;
        }

        // 更新中文版本：内容、标题（保留英文）及状态为发布
        static $is_updating = false;
        if ( $is_updating ) {
            // 避免递归调用自身
            return;
        }
        $is_updating = true;
        $update_post = array(
            'ID'           => $translated_post_id,
            'post_content' => $translated_content,
            'post_title'   => $post->post_title,
            'post_status'  => 'publish',
        );
        wp_update_post( $update_post );
        $is_updating = false;

        // ✅ Step: 复制字段
        $this->safe_copy_post_meta($post_id, $translated_post_id);

        // 复制特色图片（缩略图）
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id ) {
            set_post_thumbnail( $translated_post_id, $thumbnail_id );
        } else {
            error_log('cannot update' . $translated_post_id .'  post thumbnail');
        }

        error_log("[JIWU] 翻译完成: 原文 $post_id => 中文 $translated_post_id");

        $this->jiwu_fix_term_counts();

        return $translated_post_id;
    }

    private function jiwu_fix_term_counts(): void {
        $taxonomies = ['property_city', 'property_status', 'property_type', 'property_feature', 'property_state', 'property_country'];

        foreach (['en', 'zh-hans'] as $lang) {
            do_action('wpml_switch_language', $lang);
            foreach ($taxonomies as $taxonomy) {
                $term_ids = get_terms([
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'fields'     => 'ids',
                    'lang'       => $lang,
                ]);

                // foreach ($term_ids as $tid) {
                //    $term = get_term($tid, $taxonomy);
                //    error_log("[FIX][$lang] $taxonomy => {$term->name} ({$term->slug}) [ID: $tid]");
                //}

                if (!empty($term_ids)) {
                    wp_update_term_count_now($term_ids, $taxonomy);
                }
            }
        }
    }


    /**
     * 安全复制 post_meta 数据（支持多值字段，如 fave_property_images）
     *
     * @param int $source_post_id 源文章 ID（英文）
     * @param int $target_post_id 目标文章 ID（中文）
     * @param array $exclude_keys 可选，排除字段
     */
    public function safe_copy_post_meta(int $source_post_id, int $target_post_id, array $exclude_keys = []) {
        $default_exclude_prefixes = ['_icl_'];
        $default_exclude_keys = ['_wpml_media_has_media', '_edit_lock', '_edit_last'];

        // 多值字段（使用 add_post_meta）
        $multi_value_keys = [
            'fave_property_images',
            'fave_agents',
            'floor_plans',
            'houzez_views_by_date',
        ];

        $all_meta = get_post_meta($source_post_id);

        foreach ($all_meta as $meta_key => $meta_values) {
            // 跳过 WPML 或显式排除字段
            foreach ($default_exclude_prefixes as $prefix) {
                if (strpos($meta_key, $prefix) === 0) {
                    continue 2;
                }
            }
            if (in_array($meta_key, array_merge($default_exclude_keys, $exclude_keys), true)) {
                continue;
            }

            // 清空目标已有该字段（防止旧值/重复）
            delete_post_meta($target_post_id, $meta_key);

            if (in_array($meta_key, $multi_value_keys, true)) {
                foreach (array_unique($meta_values) as $value) {
                    add_post_meta($target_post_id, $meta_key, maybe_unserialize($value));
                }
            } else {
                // 只保留一个值（最新的）
                $last_value = maybe_unserialize(end($meta_values));
                update_post_meta($target_post_id, $meta_key, $last_value);
            }
        }
    }

    public static function translateProperty(int $post_id): int|WP_Error|false
    {
        $translator = new self();
        return $translator->translatePostWithWPML($post_id, 'zh-hans');
    }
}

// 调用示例：
// $translated_id = JiwuDeepSeekTranslator::translateProperty($post_id);

