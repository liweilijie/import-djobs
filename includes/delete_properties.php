<?php

// CLI 调用方式：php delete_properties.php 309086 309087 ...

require_once dirname(__FILE__, 5) . '/wp-load.php';

if (php_sapi_name() !== 'cli') {
    exit("❌ 只能通过 CLI 运行\n");
}

array_shift($argv); // 移除脚本文件名本身
$post_ids = array_map('intval', $argv);

if (empty($post_ids)) {
    exit("⚠️ 请提供要删除的 post_id，例如：php delete_properties.php 309086 309087\n");
}

$results = jiwu_delete_post_and_related_data_batch($post_ids);

// 输出结果
foreach ($results as $post_id => $status) {
    echo "Post {$post_id}: {$status}\n";
}

// ============================================================
// ✅ 支持 WPML 的批量彻底删除函数
// ============================================================
function jiwu_delete_post_and_related_data_batch(array $post_ids): array
{
    global $wpdb;

    $results = [];

    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            error_log("❌ Post ID {$post_id} 不存在");
            $results[$post_id] = 'not_found';
            continue;
        }

        // 获取当前 post 所属的 trid（WPML 语言组 ID）
        $trid_row = $wpdb->get_row($wpdb->prepare(
            "SELECT trid FROM wp_icl_translations WHERE element_id = %d AND element_type = %s",
            $post_id,
            'post_' . $post->post_type
        ));

        if ($trid_row && $trid_row->trid) {
            // 获取该 trid 下的所有语言版本
            $translations = $wpdb->get_col($wpdb->prepare(
                "SELECT element_id FROM wp_icl_translations WHERE trid = %d AND element_type = %s",
                $trid_row->trid,
                'post_' . $post->post_type
            ));

            foreach ($translations as $translated_id) {
                jiwu_delete_single_post_full($translated_id);
                $results[$translated_id] = 'deleted_trid';
            }
        } else {
            // 单独删除当前 post
            jiwu_delete_single_post_full($post_id);
            $results[$post_id] = 'deleted';
        }
    }

    return $results;
}

// ============================================================
// ✅ 删除单个 post（包括 meta、term、更新 wp_listings）
// ============================================================
function jiwu_delete_single_post_full($post_id)
{
    global $wpdb;

    $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id]);
    $wpdb->delete($wpdb->term_relationships, ['object_id' => $post_id]);
    $wpdb->delete($wpdb->posts, ['ID' => $post_id]);

    // 更新 wp_listings 表中 status = 0
    $wpdb->update('wp_listings', ['status' => 0], ['post_id' => $post_id]);

    // 可选：也删除 icl_translations 记录（不影响 WPML，WPML 会自动清理 orphan 数据）
    // $wpdb->delete('wp_icl_translations', ['element_id' => $post_id]);
}

