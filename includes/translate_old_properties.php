<?php


function cron_translate_old_properties()
{
// 循环脚本：每 10 分钟检查一次未翻译的英文房源，并调用翻译函数

    require_once dirname(__FILE__, 5) . '/wp-load.php';

// 防止超时
    set_time_limit(0);

    while (true) {
        global $wpdb;

        error_log("[JIWU Translate Cron] 执行中...");

        // 获取所有 30 分钟前发布的英文房源（未翻译）
        $results = $wpdb->get_results("
        SELECT p.ID
        FROM {$wpdb->posts} p
        JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
        WHERE p.post_type = 'property'
          AND p.post_status = 'publish'
          AND t.language_code = 'en'
          AND p.post_date < (NOW() - INTERVAL 30 MINUTE)
    ");

        foreach ($results as $row) {
            $post_id = $row->ID;

            // 查询该房源是否已有中文翻译
            $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_property');
            $translations = apply_filters('wpml_get_element_translations', null, $trid);

            if (!isset($translations['zh-hans'])) {
                error_log("[JIWU Translate Cron] 翻译房源 ID: $post_id");

                // 执行翻译
                $rst = JiwuDeepSeekTranslator::translateProperty($post_id);

                if (is_wp_error($rst)) {
                    error_log("[JIWU Translate Cron] 失败: " . $rst->get_error_message());
                } else {
                    error_log("[JIWU Translate Cron] 成功翻译 post_id=$post_id -> $rst zh-hans");
                }

                sleep(60*10); // 每个翻译任务稍作间隔，避免 API 或服务器负载
            }
        }

        error_log("[JIWU Translate Cron] 等待 10 分钟...");
        sleep(600); // 等待 10 分钟
    }


}
