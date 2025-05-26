//    // 获取源文章所有自定义字段（post meta）数据
//    $meta_data = get_post_meta($source_post_id);
//    // 遍历每个 meta 键并复制到新文章
//    foreach ($meta_data as $meta_key => $meta_values) {
//        // 跳过 WPML 内部使用的字段，避免破坏关联关系
//        if (strpos($meta_key, '_icl_') === 0 || $meta_key === '_wpml_media_has_media') {
//            continue;
//        }
//        // 遍历该字段的所有值（支持重复字段的情况）
//        foreach ($meta_values as $meta_value) {
//            $new_meta_value = $meta_value;
//            // 如果值是数组或对象（序列化数据解包后），则不翻译，仅直接复制
//            if (is_array($meta_value) || is_object($meta_value)) {
//                // 直接使用 $meta_value，因为 WP 在 add_post_meta 时会序列化数组/对象
//            } else {
//                // 检查该 meta key 是否在需翻译映射表中且标记为 true
//                if (!empty($translate_meta_map[$meta_key]) && $translate_meta_map[$meta_key] === true) {
//                    // 尝试翻译该字段文本内容（避免翻译纯数字等非文本内容）
//                    $text_to_translate = trim($meta_value);
//                    if ($text_to_translate !== '' && !is_numeric($text_to_translate)) {
//                    }
//                }
//            }
//            // 将（翻译后的）meta值添加到新文章
//            error_log($new_post_id. ': meta_key:' . $meta_key . ' => new_meta_value:' . $new_meta_value);
//            add_post_meta($new_post_id, $meta_key, $new_meta_value);
//        }
//    }
//
//    copy_taxonomy_terms($source_post_id, $new_post_id, [
//        'property_status',
//        'property_type',
//        'property_feature',
//        'property_country',
//        'property_state',
//        'property_city',
//        'property_area',
//    ]);


///**
// * 使用 DeepSeek API 将指定文章翻译为目标语言（默认中文）并创建翻译文章。
// *
// * @param int $source_post_id 源文章（英文）的ID。
// * @param string $target_lang 目标语言代码（如 'zh' 表示中文）。
// * @param array $translate_meta_map 指定需要翻译的自定义字段键名映射表，值为 true 表示翻译该字段内容。
// *
// * @return int|WP_Error 返回新创建的翻译文章ID，或WP_Error对象表示失败。
// */
//function translate_property_chinese($source_post_id): WP_Error|int
//{
//    $target_lang = 'zh-hans';
//    $translate_meta_map = array();
////    global $sitepress;  // WPML 全局对象，用于获取语言信息等（如果需要）。
//
//    error_log('source_post_id:' . $source_post_id);
//
//    // 获取源文章对象并进行基本检查
//    $source_post = get_post($source_post_id);
//    if (!$source_post || $source_post->post_status === 'trash') {
//        return new WP_Error('invalid_post', '源文章不存在或已被删除');
//    }
//
//    // 确定源文章语言代码（若 WPML 可用）
//    $source_lang = 'en';  // 默认源语言英语，可根据需要调整
//    $lang_info = ensure_wpml_language_registered($source_post_id, $source_post->post_type, 'en');
//    $original_trid = $lang_info->trid ?? null;
//
//    if (empty($original_trid)) {
//        error_log('cannot get original_trid:' . $source_post_id);
//        return new WP_Error('cannot_get_trid', '无法获取原文的 trid');
//    }
//
//    error_log('original_trid:' . $original_trid);
//
//    // 调用 DeepSeek API 翻译标题和内容
//    $original_title = $source_post->post_title;
//    $original_content = $source_post->post_content;
//    $translated_title = $original_title;
//    $translated_content = $original_content;
//    $translated_content = deepseek_translate_to_chinese($original_content);
//
//    // 准备新翻译文章的数据数组
//    $new_post_data = array(
//        'post_title' => $translated_title,
//        'post_content' => $translated_content,
//        'post_type' => $source_post->post_type,     // 与源文章相同的文章类型
//        'post_status' => $source_post->post_status,   // 复制源文章状态（如已发布）
//        'post_author' => $source_post->post_author,   // 复制作者
//        'post_parent' => 0,                           // 默认无父级，如果有父页面可根据需要设置
//        'menu_order' => $source_post->menu_order,    // 复制菜单顺序（用于层级或菜单排序）
//    );
//    // 插入新文章以创建中文翻译文章
//    $new_post_id = wp_insert_post($new_post_data);
//    if (is_wp_error($new_post_id) || $new_post_id == 0) {
//        error_log('insert failed and source_post_id:' . $source_post_id);
//        return new WP_Error('insert_failed', '插入翻译文章失败');
//    }
//
//    // 如果源文章有特色图片(Featured Image)，同步设置到新文章
//    $thumbnail_id = get_post_thumbnail_id($source_post_id);
//    if ($thumbnail_id) {
//        set_post_thumbnail($new_post_id, $thumbnail_id);
//    }
//
//    // 通过 WPML API 将新文章与源文章关联为翻译
//    if (!empty($original_trid)) {
//        do_action('wpml_set_element_language_details', array(
//            'element_id' => $new_post_id,
//            'element_type' => apply_filters('wpml_element_type', $source_post->post_type),
//            'trid' => $original_trid,
//            'language_code' => $target_lang,    // 新文章语言（中文）
//            'source_language_code' => $source_lang,    // 源文章语言（英文）
//        ));
//    }
//
//    $lang_info = apply_filters('wpml_element_language_details', null, [
//        'element_id' => $new_post_id,
//        'element_type' => apply_filters('wpml_element_type', $source_post->post_type)
//    ]);
//    error_log("📄 WPML language of new_post {$new_post_id} is: " . ($lang_info->language_code ?? 'UNKNOWN'));
//
//
//    // 强制刷新 WPML term translation（⚠️ undocumented，但有效）
//    do_action( 'wpml_reload_terms_cache' );
//
//
//    $taxonomies = [
//        'property_status',
//        'property_type',
//        'property_feature',
//        'property_country',
//        'property_state',
//        'property_city',
//        'property_area',
//    ];
//
//    foreach ($taxonomies as $taxonomy) {
//        $terms = wp_get_object_terms($source_post_id, $taxonomy);
//
//        $translated_term_ids = [];
//
//        foreach ($terms as $term) {
//            $translated_term_id = apply_filters('wpml_object_id', $term->term_id, $taxonomy, false, 'zh-hans');
//
//            error_log("term: {$term->name}, original: {$term->term_id}, translated: {$translated_term_id}");
//
//
//            if (!$translated_term_id) {
//                error_log("❌ term `{$term->name}` ({$term->term_id}) in {$taxonomy} has no zh-hans translation.");
//                continue;
//            }
//
//            if ($translated_term_id == $term->term_id) {
//                error_log("⚠️ term `{$term->name}` seems untranslated (same term_id) in {$taxonomy}");
//                continue;
//            }
//
//            $translated_term_ids[] = (int)$translated_term_id;
//        }
//
//        if (!empty($translated_term_ids)) {
//            // 先清除旧 term（确保不会混入英文 term）
//            wp_set_object_terms($new_post_id, [], $taxonomy);
//            // 设置中文 term
//            wp_set_object_terms($new_post_id, $translated_term_ids, $taxonomy);
//        }
//    }
//
//
//    // 返回新创建的中文文章ID
//    error_log('success to translate new_post_id:' . $new_post_id);
//    return $new_post_id;
//}


function jiwu_bind_tranlated_terms_immediately($source_post_id, $translated_post_id)
{
global $wpdb;

    $taxonomies = [
        'property_status',
        'property_type',
        'property_feature',
        'property_country',
        'property_state',
        'property_city',
        'property_area',
    ];

    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($source_post_id, $taxonomy);
        $translated_term_ids = [];

        foreach ($terms as $term) {
            // 直接查找 term 的 trid
            $trid_row = $wpdb->get_row($wpdb->prepare(
                "SELECT trid FROM wp_icl_translations 
                     WHERE element_id = %d AND element_type = %s",
                $term->term_id,
                'tax_' . $taxonomy
            ));

            if (!$trid_row || !$trid_row->trid) {
                error_log("❌ term {$term->term_id} ({$term->name}) 无 WPML trid");
                continue;
            }

            // 获取该 trid 下的 zh-hans term
            $translated_term_id = $wpdb->get_var($wpdb->prepare(
                "SELECT element_id FROM wp_icl_translations
                     WHERE trid = %d AND language_code = 'zh-hans' AND element_type = %s",
                $trid_row->trid,
                'tax_' . $taxonomy
            ));

            if (!$translated_term_id) {
                error_log("⚠️ term {$term->name} 无中文翻译");
                continue;
            }

            $translated_term_ids[] = (int)$translated_term_id;
        }

        if (!empty($translated_term_ids)) {
            wp_set_object_terms($translated_post_id, [], $taxonomy);
            wp_set_object_terms($translated_post_id, $translated_term_ids, $taxonomy);
            error_log("✅ {$taxonomy} 成功设置 translated terms: " . implode(',', $translated_term_ids));
        } else {
            error_log("⚠️ {$taxonomy} 未找到任何中文 term");
        }
    }

}

function jiwu_bind_translated_terms_later($source_post_id, $translated_post_id): void {
add_action('wp_loaded', function () use ($source_post_id, $translated_post_id) {
$taxonomies = [
'property_status',
'property_type',
'property_feature',
'property_country',
'property_state',
'property_city',
'property_area',
];

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($source_post_id, $taxonomy);
            $translated_term_ids = [];

            foreach ($terms as $term) {
                $translated_id = apply_filters('wpml_object_id', $term->term_id, $taxonomy, false, 'zh-hans');

                if (!$translated_id || $translated_id == $term->term_id) {
                    error_log("⚠️ 未绑定翻译或失败：term {$term->name} ({$term->term_id}) in {$taxonomy}");
                    continue;
                }

                $translated_term_ids[] = (int) $translated_id;
            }

            if (!empty($translated_term_ids)) {
                wp_set_object_terms($translated_post_id, [], $taxonomy); // 清空原绑定
                wp_set_object_terms($translated_post_id, $translated_term_ids, $taxonomy);
                error_log("✅ {$taxonomy} 已设置翻译 term 到 post {$translated_post_id}");
            }
        }
    });
}



function ensure_wpml_language_registered($post_id, $post_type, $lang_code = 'en') {
$element_type = apply_filters('wpml_element_type', $post_type);
$lang_details = apply_filters('wpml_element_language_details', null, [
'element_id' => $post_id,
'element_type' => $element_type
]);

    if (empty($lang_details)) {
        do_action('wpml_set_element_language_details', [
            'element_id'           => $post_id,
            'element_type'         => $element_type,
            'trid'                 => false,
            'language_code'        => $lang_code,
            'source_language_code' => null
        ]);

        $lang_details = apply_filters('wpml_element_language_details', null, [
            'element_id'   => $post_id,
            'element_type' => $element_type
        ]);
    }

    return $lang_details;
}

function copy_taxonomy_terms($source_post_id, $target_post_id, $taxonomies = []) {
foreach ($taxonomies as $taxonomy) {
$terms = wp_get_object_terms($source_post_id, $taxonomy);
if (!is_wp_error($terms) && !empty($terms)) {
$term_ids = wp_list_pluck($terms, 'term_id');
wp_set_object_terms($target_post_id, $term_ids, $taxonomy);
}
}
}

function jiwu_create_translated_post($source_post_id): int|WP_Error {
$target_lang = 'zh-hans';
$source_lang = 'en';

    $source_post = get_post($source_post_id);
    if (!$source_post || $source_post->post_status === 'trash') {
        return new WP_Error('invalid_post', '源文章不存在或已删除');
    }

    $lang_info = apply_filters('wpml_element_language_details', null, [
        'element_id'   => $source_post_id,
        'element_type' => apply_filters('wpml_element_type', $source_post->post_type),
    ]);

    $original_trid = $lang_info->trid ?? null;
    if (!$original_trid) {
        return new WP_Error('missing_trid', '无法获取源文章的 WPML trid');
    }

    // 翻译内容（标题可保留英文）
    $translated_content = deepseek_translate_to_chinese($source_post->post_content);

    if (empty($translated_content)) {
       return new WP_Error('translated_content', '翻译失败');
    }

    $new_post_id = wp_insert_post([
        'post_title'    => $source_post->post_title,
        'post_content'  => $translated_content,
        'post_type'     => $source_post->post_type,
        'post_status'   => $source_post->post_status,
        'post_author'   => $source_post->post_author,
        'post_parent'   => 0,
        'menu_order'    => $source_post->menu_order,
    ]);

    if (is_wp_error($new_post_id)) {
        return $new_post_id;
    }

    error_log('cn_post_id:' . $new_post_id . '  en_post_id:' . $source_post_id);

    jiwu_copy_post_meta($source_post_id, $new_post_id, [
        '_edit_lock',
        '_edit_last',
        '_wpml_media_duplicate',
        '_thumbnail_id',
    ]);

    // 设置语言绑定
    do_action('wpml_set_element_language_details', [
        'element_id'             => $new_post_id,
        'element_type'           => apply_filters('wpml_element_type', $source_post->post_type),
        'trid'                   => $original_trid,
        'language_code'          => $target_lang,
        'source_language_code'   => $source_lang,
    ]);

    // 设置特色图片
    $thumb_id = get_post_thumbnail_id($source_post_id);
    if ($thumb_id) {
        set_post_thumbnail($new_post_id, $thumb_id);
    }

    return $new_post_id;
}

function jiwu_copy_post_meta($source_post_id, $target_post_id, $exclude_keys = []) {
$meta_data = get_post_meta($source_post_id);

    foreach ($meta_data as $meta_key => $meta_values) {
        // 排除 WPML 或其它系统字段
        if (
            strpos($meta_key, '_icl_') === 0 ||
            $meta_key === '_wpml_media_has_media' ||
            in_array($meta_key, $exclude_keys)
        ) {
            continue;
        }

        foreach ($meta_values as $meta_value) {
            add_post_meta($target_post_id, $meta_key, maybe_unserialize($meta_value));
        }
    }

    error_log("✅ 成功复制 meta：from {$source_post_id} → to {$target_post_id}");
}

