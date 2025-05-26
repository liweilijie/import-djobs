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

