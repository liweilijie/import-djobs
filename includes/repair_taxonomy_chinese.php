<?php

/*
 * 功能增强：
 * 查找英文 term 是否已绑定到中文房源；
 *  如果是，先用 wp_remove_object_terms() 移除英文 term；
 *  然后查找英文 term 的中文翻译 term；
 *  仅将中文 term 设置到中文房源（即彻底清除语言混用问题）；
 */
function repair_taxonomy_chinese()
{
    require_once dirname(__FILE__, 5) . '/wp-load.php';

    $per_page = 1000;
    $page = 1;
    $total = 0;

    do {
        $en_properties = get_posts([
            'post_type'        => 'property',
            'posts_per_page'   => $per_page,
            'paged'            => $page,
            'post_status'      => 'publish',
            'lang'             => 'en',
            'suppress_filters' => false,
        ]);

        if (empty($en_properties)) break;

        foreach ($en_properties as $en_post) {
            $trid = apply_filters('wpml_element_trid', null, $en_post->ID, 'post_property');
            $translations = apply_filters('wpml_get_element_translations', null, $trid);

            if (!isset($translations['zh-hans'])) continue;

            $zh_post_id = $translations['zh-hans']->element_id;
            $taxonomies = array_filter(
                get_object_taxonomies('property'),
                function ($taxonomy) {
                    return !in_array($taxonomy, [
                        'translation_priority',
                        '_wpml_media_duplicate',
                        '_wpml_word_count',
                    ]);
                }
            );

            foreach ($taxonomies as $taxonomy) {
                wp_set_object_terms($zh_post_id, [], $taxonomy); // 清空所有旧绑定

                $terms = wp_get_object_terms($en_post->ID, $taxonomy);

                if (!empty($terms) && !is_wp_error($terms)) {
                    $zh_term_ids = [];

                    foreach ($terms as $term) {
                        $trid = apply_filters('wpml_element_trid', null, $term->term_id, "tax_{$taxonomy}");
                        $translations_term = apply_filters('wpml_get_element_translations', null, $trid);

                        if (isset($translations_term['zh-hans'])) {
                            $zh_term_ids[] = (int)$translations_term['zh-hans']->term_id;
                        } else {
                            error_log("❌ 缺少中文翻译：Term {$term->term_id} ({$term->name}) in taxonomy {$taxonomy}");
                        }
                    }

                    if (!empty($zh_term_ids)) {
                        wp_set_object_terms($zh_post_id, $zh_term_ids, $taxonomy);
                        error_log("✅ 已修复 ZH post {$zh_post_id} taxonomy {$taxonomy}");
                    } else {
                        error_log("⚠️ 无法为 ZH post {$zh_post_id} 设置任何 term for taxonomy {$taxonomy}");
                    }
                }
            }
            $total++;
        }

        echo "已处理第 {$page} 页，共 {$per_page} 条\n";
        error_log("已处理第 {$page} 页，共 {$per_page} 条");
        $page++;

        gc_collect_cycles();
    } while (true);

    error_log("🎉 同步完成！共处理中文房源文章：{$total}");
}


function jiwu_import_property_types()
{
    // 设置无限执行时间
    set_time_limit(0);

    // 引入 WordPress 环境
    require_once dirname(__FILE__, 5) . '/wp-load.php';

    // 定义 property_type 映射表
    $property_types = [
        'rural'     => 'Rural',
        'land'      => 'Land',
        'house'     => 'House',
        'apartment' => 'Apartment',
        'townhouse' => 'Townhouse',
        'other'     => 'Other',
        'unit'      => 'Unit',
        'cropping'  => 'Cropping',
        'villa'     => 'Villa',
        'studio'    => 'Studio',
        'farming'   => 'Farming',
        'living'    => 'Retirement Living',
    ];

    foreach ($property_types as $slug => $name) {
        // 检查是否已存在
        if (!term_exists($name, 'property_type')) {
            wp_insert_term($name, 'property_type', [
                'slug' => $slug,
            ]);
        }
    }

}

function jiwu_import_cleaned_suburb_csv($csv_path) {
    // 设置无限执行时间
    set_time_limit(0);

    // 引入 WordPress 环境
    require_once dirname(__FILE__, 5) . '/wp-load.php';

    if (!file_exists($csv_path)) {
        echo "CSV 文件不存在：$csv_path\n";
        return;
    }

    $handle = fopen($csv_path, 'r');
    if (!$handle) {
        echo "无法读取 CSV 文件。\n";
        return;
    }

    $row = 0;
    while (($data = fgetcsv($handle)) !== false) {
        if ($row === 0) {
            $row++; // 跳过表头
            continue;
        }

        list($postcode_raw, $suburb_raw, $state_raw, $country_raw) = $data;

        // === 数据清洗 ===
        $postcode = trim($postcode_raw);
        $suburb = ucwords(strtolower(trim($suburb_raw)));
        $state = strtoupper(trim($state_raw));
        $country = strtoupper(trim($country_raw));

        // === 合法性验证 ===
        if (!preg_match('/^\d{4}$/', $postcode)) {
            echo "❌ 非法 postcode：{$postcode} 跳过\n";
            continue;
        }
        if (!in_array($state, ['NSW', 'VIC', 'QLD', 'WA', 'SA', 'TAS', 'NT', 'ACT'])) {
            echo "❌ 非法 state：{$state} 跳过\n";
            continue;
        }
        if ($country !== 'AU') {
            echo "❌ 非法国家：{$country} 跳过\n";
            continue;
        }

        error_log($postcode . ' ' . $suburb . ' ' . $state . ' ' . $country);
        $country = 'Australia';

        // === 插入到 WP ===
        // 插入国家
        $country_term = term_exists('Australia', 'property_country') ?: wp_insert_term('Australia', 'property_country');
        $country_id = is_array($country_term) ? $country_term['term_id'] : $country_term;

        // 插入州
        $state_term = term_exists($state, 'property_state') ?: wp_insert_term($state, 'property_state');
        $state_id = is_array($state_term) ? $state_term['term_id'] : $state_term;
        update_term_meta($state_id, 'fave_state_country', $country_id);

        // 插入城市（suburb）
        $city_term = term_exists($suburb, 'property_city') ?: wp_insert_term($suburb, 'property_city');
        $city_id = is_array($city_term) ? $city_term['term_id'] : $city_term;
        update_term_meta($city_id, 'fave_city_state', $state_id);
        update_term_meta($city_id, 'fave_city_country', $country_id);
        update_term_meta($city_id, 'fave_city_postcode', $postcode);

        echo "✅ 导入 suburb：{$suburb} ({$postcode})\n";
        $row++;
    }

    fclose($handle);
    echo "🎉 完成，处理了 {$row} 行数据。\n";
}

function jiwu_duplicate_property_cities_to_zh_hans() {
    // 设置无限执行时间
    set_time_limit(0);

    // 引入 WordPress 环境
    require_once dirname(__FILE__, 5) . '/wp-load.php';


    if (!function_exists('wpml_get_element_trid')) {
        error_log('[JIWU] WPML functions not available');
        return;
    }

    $taxonomy = 'property_city';

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'lang'       => 'en', // 只查英文术语
    ]);

    foreach ($terms as $term) {
        error_log('process term:' . print_r($term, true));

        // 检查是否已有 zh-hans 的翻译
        $trid = apply_filters('wpml_element_trid', null, [
            'element_id'   => $term->term_id,
            'element_type' => 'tax_' . $taxonomy,
        ]);

        error_log('$trid: ' . $trid);

        $translated_term_id = apply_filters('wpml_object_id', $term->term_id, $taxonomy, false, 'zh-hans');

        if ($translated_term_id && $translated_term_id != $term->term_id) {
            error_log("[JIWU] {$term->name} 已有 zh-hans 翻译，跳过");
            continue;
        }

        // 构造 slug 和名称
        $translated_slug = $term->slug . '-zh-hans';

        error_log('create term:' . $term->name . ' taxonomy:' . $taxonomy . ' slug:' . $translated_slug);

        // 创建翻译术语
        $result = wp_insert_term($term->name, $taxonomy, [
            'slug' => $translated_slug,
        ]);

        if (is_wp_error($result)) {
            error_log("[JIWU] 创建 {$term->name} 翻译失败：" . $result->get_error_message());
            continue;
        }

        $new_term_id = $result['term_id'];

        // 设置语言绑定
        do_action('wpml_set_element_language_details', [
            'element_id'    => $new_term_id,
            'element_type'  => 'tax_' . $taxonomy,
            'trid'          => $trid,
            'language_code' => 'zh-hans',
            'source_language_code' => 'en',
        ]);

        error_log("[JIWU] 创建 zh-hans 翻译成功: {$term->name} => {$translated_slug}");
    }
}
