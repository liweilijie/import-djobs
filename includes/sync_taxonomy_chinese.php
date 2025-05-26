<?php


/**
 * 将英文房源的 taxonomy 分类信息复制到对应的中文房源文章。
 *
 * 逻辑示意 SQL：
 * 1. 从 wp_term_relationships, wp_term_taxonomy 查出英文文章的所有 term_id；
 *    SELECT term_taxonomy_id FROM wp_term_relationships WHERE object_id = {英文post_id};
 * 2. 将 term_id 对应的 taxonomy 类型与中文文章建立关系：
 *    INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES ({中文post_id}, ...);
 *
 * 涉及表：
 * - wp_posts：文章信息（post_type = 'property'）
 * - wp_icl_translations：用于中英文文章之间的 trid 翻译组匹配
 * - wp_term_relationships：文章和术语关联表
 * - wp_term_taxonomy：术语和 taxonomy 类型的关系
 */
function copy_taxonomy_terms($source_post_id, $target_post_id, $taxonomies = [])
{
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($source_post_id, $taxonomy);
        if (!is_wp_error($terms) && !empty($terms)) {
            $term_ids = wp_list_pluck($terms, 'term_id');
            wp_set_object_terms($target_post_id, $term_ids, $taxonomy);
        }
    }
}

function sync_taxonomy_chinese()
{

// 用于命令行：php sync_taxonomy_chinese.php
// 请确保此脚本位于 WordPress 环境中，并能包含 wp-load.php

    require_once dirname(__FILE__, 5) . '/wp-load.php'; // 根据实际路径调整

// 要同步的分类法
    $taxonomies_to_copy = [
        'property_status',
        'property_type',
        'property_feature',
        'property_country',
        'property_state',
        'property_city',
        'property_area',
    ];

    // 分页处理，以免posts太多占用内存，记得手动释放内存
    $per_page = 1000;
    $page = 1;
    $total = 0;

    do {
        $en_properties = get_posts([
            'post_type'        => 'property',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'      => 'publish',
            'lang'             => 'en',
            'suppress_filters' => false,
        ]);

        if (empty($en_properties)) break;

        foreach ($en_properties as $en_post) {
            // 获取英文房源的翻译 trid
            $trid = apply_filters('wpml_element_trid', null, $en_post->ID, 'post_property');

            // 获取翻译列表
            $translations = apply_filters('wpml_get_element_translations', null, $trid);

            if (!isset($translations['zh-hans'])) {
                // 没有中文翻译，跳过
                continue;
            }

            $total += 1;
            $zh_post_id = $translations['zh-hans']->element_id;

            // 获取所有关联的 taxonomy
            $taxonomies = get_object_taxonomies('property');

            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_object_terms($en_post->ID, $taxonomy, ['fields' => 'ids']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    // 复制分类到中文版本
                    wp_set_object_terms($zh_post_id, $terms, $taxonomy);
                }
            }

            error_log("同步 taxonomy 到中文房源成功: EN {$en_post->ID} -> ZH {$zh_post_id}");
        }

        echo ("已处理第 {$page} 页，共 {$per_page} 条");
        error_log("已处理第 {$page} 页，共 {$per_page} 条");
        $page++;

        gc_collect_cycles(); // 手动释放内存
    } while (true);

    error_log("共处理中文房源文章：" . $total);
}

// 285420
// 309003
