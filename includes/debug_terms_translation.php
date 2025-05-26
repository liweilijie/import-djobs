<?php

// 用法: php debug_term_translation.php 29 property_status zh-hans

require_once dirname(__FILE__, 5) . '/wp-load.php';

if (php_sapi_name() !== 'cli') {
    exit("❌ 必须通过命令行运行此脚本\n");
}

array_shift($argv); // 移除文件名

if (count($argv) < 3) {
    exit("⚠️ 用法: php debug_term_translation.php <term_id> <taxonomy> <target_lang>\n例如: php debug_term_translation.php 29 property_status zh-hans\n");
}

list($term_id, $taxonomy, $target_lang) = $argv;
$term_id = intval($term_id);
$element_type = 'tax_' . $taxonomy;

global $wpdb;

echo "🧪 正在测试 term_id = {$term_id}, taxonomy = {$taxonomy}, language = {$target_lang}\n";

// 查找 trid
$trid_row = $wpdb->get_row($wpdb->prepare(
    "SELECT trid FROM wp_icl_translations 
     WHERE element_id = %d AND element_type = %s",
    $term_id,
    $element_type
));

if (!$trid_row) {
    exit("❌ 未找到该 term 的 WPML trid\n");
}

$trid = $trid_row->trid;
echo "✅ 获取到 trid: {$trid}\n";

// 验证数据库能否直接查到目标语言
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT element_id, language_code FROM wp_icl_translations 
     WHERE trid = %d AND element_type = %s",
    $trid,
    $element_type
));

echo "📋 同组翻译记录：\n";
foreach ($results as $r) {
    echo " - term_id: {$r->element_id}, language: {$r->language_code}\n";
}

// 重点测试 get_var 行为
$translated_term_id = $wpdb->get_var($wpdb->prepare(
    "SELECT element_id FROM wp_icl_translations 
     WHERE trid = %d AND language_code = %s AND element_type = %s",
    $trid,
    $target_lang,
    $element_type
));

if ($translated_term_id) {
    echo "✅ 成功查到中文 term_id: {$translated_term_id}\n";

    $term_obj = get_term($translated_term_id, $taxonomy);
    if ($term_obj && !is_wp_error($term_obj)) {
        echo "📘 term 名称: {$term_obj->name}\n";
    } else {
        echo "⚠️ term 对象获取失败\n";
    }
} else {
    echo "❌ 查询失败：未获取到中文 term_id（可能被缓存/过滤）\n";
}

