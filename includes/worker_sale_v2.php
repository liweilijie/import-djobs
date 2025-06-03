<?php

function jiwu_process_sale_tasks() {

    error_log('start jiwu process sale task v2.');
    // 设置无限执行时间
    set_time_limit(0);

    // 引入 WordPress 环境
    require_once dirname(__FILE__, 5) . '/wp-load.php';

    // 连接 Redis
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    // 设置 Redis 读取超时时间为无限
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

    // 定义队列名称
    $queue = 'jiwu_sale_task_queue';

    // 获取全局 $wpdb 对象
    global $wpdb;
    $table = $wpdb->prefix . 'sale_listings';

    while (true) {
        // 从队列中阻塞获取任务，超时时间为 10 秒
        $task = $redis->brPop([$queue], 30);

        if ($task) {
            // 获取任务数据
            $data = json_decode($task[1], true);
            // $data = arrayToObject($data); 在转化之后会存在null值判断有风险的情况，所以安全的做法还是用数组最可靠
            $id = $data["id"];

            error_log('process:' . $data["url"]);
            // 处理任务
            $importer = new SalePropertyImporter();
            $rst = $importer->process($data);

            // 将对应记录的 status 设置为 2（已处理）
            if ($rst) {
                error_log($data["url"] . ' process success');
                $wpdb->update($table, ['status' => 2], ['id' => $id]);
            } else {
                error_log($data["url"] . ' process error');
                $wpdb->update($table, ['status' => 3], ['id' => $id]);
            }

            // 你可以在这里添加更多的业务逻辑，如发送通知、更新其他表等
        } else {
            // 如果在超时时间内没有获取到任务，可以选择继续等待或退出循环
            // 这里选择继续等待
            // 无任务，休眠1秒
            sleep(1);
            continue;
        }
    }
}

/**
 * 处理房产列表导入的类
 *
 * 根据给定的列表数据($listing)，导入或更新房产文章。
 *
 * - 只有一个公共方法 `process($listing)` 作为入口。
 * - 各功能步骤(字段验证、文章查找、文章创建/更新、分类、元数据、图片、经纪人机构等)在私有方法中实现。
 * - 使用全局变量($wpdb, EXMAGE_WP_IMAGE_LINKS)，不通过构造器注入。
 * - 如果任意步骤出现异常(WP_Error 或 Exception)，则自动删除已创建文章并返回 false。
 * - 创建的新文章初始状态为 draft，全部完成后再发布。
 * - 使用 error_log() 调用，用于记录日志。
 */
class SalePropertyImporter
{

    private string $imported_ref_key = '_imported_sale_ref_jiwu';
    private string $table_name;
    private ?string $display_address = null;
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sale_listings';
    }


    /**
     * 处理整个导入流程的主方法
     */
    public function process(array $listing): bool|int
    {
        try {
            // 验证输入字段
            $this->validate_listing($listing);

            // 检查是否已有对应的文章
            $existing_post_id = $this->find_existing_property_post($listing);
            if ($existing_post_id) {
                // 如果文章已存在，更新其内容 (保持草稿状态)
                $post_id = $existing_post_id;
                $this->update_post($post_id, $listing);
            } else {
                // 如果文章不存在，创建新的文章 (初始为草稿)
                $post_id = $this->create_post($listing);
            }

            $this->update_property_basic_meta($post_id, $listing);
            $this->assign_property_status_term($post_id, $listing);
            $this->update_property_features($post_id, $listing);
            $this->update_property_location($post_id, $listing);
            $this->assign_property_type($post_id, $listing);
            $this->handle_property_images($post_id, $listing);
            $this->handle_floor_plans($post_id, $listing);
            $this->handle_agency_and_agents($post_id, $listing);
            $this->handle_update_price_guide_pdf($post_id, $listing);

            // 全部流程成功后，将文章状态更新为发布
            wp_update_post([
                'ID' => $post_id,
                'post_status' => 'publish'
            ]);
            error_log("房产列表 (ID: $post_id) 处理完成并已发布");

            $this->jiwu_fix_term_counts();

            $translated_id = JiwuDeepSeekTranslator::translateProperty($post_id);

            error_log('success process title:' . $this->display_address . ' en post id:' . $post_id) . ' cn post id:' . $translated_id;

            return $post_id;
        } catch (\Exception $e) {
            // 捕获任何异常，删除可能已创建的文章，返回 false
            error_log("处理房产列表时出错: " . $e->getMessage());
            if (!empty($post_id)) {
                $this->delete_post($post_id);
            }
            return false;
        }
    }

    private function validate_listing(array $listing): void
    {
        if (empty($listing['unique_id'])) {
            error_log("房产列表验证失败: 缺少 'id' 字段");
            throw new \Exception("缺少房产 ID");
        }
        if (empty($listing['postcode'])) {
            error_log("房产列表验证失败: 缺少 'title' 字段 (ID: {$listing['id']})");
            throw new \Exception("缺少房产标题");
        }

        if (empty($listing['listing_type'])) {
            throw new \Exception("房源缺少 listing_type 字段");
        }

        $this->display_address = $this->get_display_address($listing);
        if (empty($this->display_address)) {
            error_log("display_address empty.");
            throw new \Exception("地址为空");
        }
        // 可根据需求添加更多字段验证
    }

    private function find_existing_property_post(array $listing): bool|int
    {
        $args = [
            'post_type' => 'property',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => $this->imported_ref_key,
                    'value' => $listing['unique_id'],
                    'compare' => '='
                ]
            ]
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            wp_reset_postdata();
            return $post_id;
        }

        wp_reset_postdata();
        return false;
    }

    private function create_post(array $listing): WP_Error|int
    {
        global $wpdb;

        $post_data = [
            'post_type' => 'property',
            'post_status' => 'draft',
            'post_title' => wp_strip_all_tags($this->display_address),
            'post_excerpt' => $listing['title'] ?? '',
            'post_content' => $listing['description'] ?? '',
            'comment_status' => 'closed',
        ];

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            error_log("创建房产文章失败: " . $post_id->get_error_message());
            throw new \Exception("创建文章失败");
        }
        // 保存外部房产 ID
        update_post_meta($post_id, $this->imported_ref_key, $listing['unique_id']);
        $wpdb->update($this->table_name, ['status' => 1, 'post_id' => $post_id], ['id' => $listing['id']]);
        error_log("创建新的房产文章 (en.ID: $post_id)");
        return $post_id;
    }

    /**
     * 更新已有的房产文章内容（仅更新必要字段，保持草稿状态）
     */
    private function update_post(int $post_id, array $listing): void
    {
        global $wpdb;

        $update_data = [
            'ID' => $post_id,
            'post_title' => wp_strip_all_tags($this->display_address),
            'post_excerpt' => $listing['title'] ?? '',
            'post_content' => $listing['description'] ?? '',
            // 保持 post_status 不变 (仍为 draft)
        ];
        $updated = wp_update_post($update_data, true);
        if (is_wp_error($updated)) {
            error_log("更新房产文章(ID: $post_id)失败: " . $updated->get_error_message());
            throw new \Exception("更新文章失败");
        }

        $wpdb->update($this->table_name, ['status' => 1, 'post_id' => $post_id], ['id' => $listing['id']]);
        error_log("更新房产文章 (ID: $post_id)");
    }

    private function get_display_address(array $listing): string {
        $parts = [];

        if (!empty($listing['street']))   $parts[] = trim($listing['street']);
        if (!empty($listing['suburb']))   $parts[] = trim($listing['suburb']);
        if (!empty($listing['state']))    $parts[] = strtoupper(trim($listing['state']));
        if (!empty($listing['postcode'])) $parts[] = trim($listing['postcode']);

        return implode(', ', $parts);
    }

    /**
     * 删除指定文章 (包括所有附件) 的内部方法
     *
     * @param int $post_id
     */
    private function delete_post(int $post_id): void
    {
        // 强制删除文章
        if (wp_delete_post($post_id, true)) {
            error_log("删除已创建的文章 (ID: $post_id)");
        } else {
            error_log("删除文章失败 (ID: $post_id)");
        }
    }

    private function update_property_basic_meta(int $post_id, array $listing): void
    {
        /*
         * 在 Houzez 主题中，显示房产价格时，通常使用以下两个 meta key：
         *  fave_property_price：用于存储房产的实际价格数值。
         *  fave_property_price_postfix：用于存储价格后缀，如“/month”等。
         *  如果您希望在没有具体价格的情况下显示“Contact Agent”这样的文本，您可以将 fave_property_price 设置为 0 或留空，并将 fave_property_price_postfix 设置为 'Contact Agent'。
         *  以下是一个示例代码片段，展示如何在 WordPress 中使用 update_post_meta 函数设置这些字段：
         *  update_post_meta($post_id, 'fave_property_price', '');
         *  update_post_meta($post_id, 'fave_property_price_postfix', 'Contact Agent');
         *  这样设置后，Houzez 主题将在前端显示“Contact Agent”而不是具体的价格。
         */
        if (!empty($listing['lower_price']) && !empty($listing['upper_price'])) {
            update_post_meta($post_id, 'fave_property_price', $listing['lower_price']);
            $postfix = ' - $' . number_format($listing['upper_price']);
            update_post_meta($post_id, 'fave_property_price_postfix', $postfix);
        } elseif (!empty($listing['lower_price'])) {
            update_post_meta($post_id, 'fave_property_price', $listing['lower_price']);
            update_post_meta($post_id, 'fave_property_price_postfix', '');
        } elseif (!empty($listing['upper_price'])) {
            update_post_meta($post_id, 'fave_property_price', $listing['upper_price']);
            update_post_meta($post_id, 'fave_property_price_postfix', '');
        } elseif (!empty($listing['price_text'])) {
            update_post_meta($post_id, 'fave_property_price', '');
            update_post_meta($post_id, 'fave_property_price_postfix', $listing['price_text']);
        }

        // 房间配置
        update_post_meta($post_id, 'fave_property_bedrooms', $listing['bedrooms'] ?? '');
        update_post_meta($post_id, 'fave_property_bathrooms', $listing['bathrooms'] ?? '');
        update_post_meta($post_id, 'fave_property_garage', $listing['car_spaces'] ?? '');

        // 外部 ID
        update_post_meta($post_id, 'fave_property_id', $listing['unique_id']);

        // 面积
        if (!empty($listing['land_size'])) {
            update_post_meta($post_id, 'fave_property_size', $listing['land_size']);
            update_post_meta($post_id, 'fave_property_size_prefix', 'm²');
        } else {
            update_post_meta($post_id, 'fave_property_size', '');
            update_post_meta($post_id, 'fave_property_size_prefix', '');
        }
    }

    private function assign_property_status_term(int $post_id, array $listing): void
    {
        if (empty($listing['listing_type'])) {
            throw new \Exception("房源缺少 listing_type 字段");
        }

        $status = strtolower(trim($listing['listing_type']));
        $term_name = match ($status) {
            'sale'      => 'For Sale',
            'rental'    => 'For Rent',
            'sold'      => 'Sold',
            'withdrawn' => 'Withdrawn',
            'offmarket' => 'Off Market',
            default     => null,
        };

        if (is_null($term_name)) {
            error_log("未知的房源状态：{$status}");
            throw new \Exception("无法识别的 listing_type: {$status}");
        }

        // 检查术语是否已存在
        $term = get_term_by('name', $term_name, 'property_status');
        if (!$term) {
            $result = wp_insert_term($term_name, 'property_status');
            if (is_wp_error($result)) {
                error_log("无法创建 property_status 术语 '{$term_name}'：" . $result->get_error_message());
                throw new \Exception("创建术语失败: {$term_name}");
            }
            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }

        // 绑定术语到文章
        $set_result = wp_set_object_terms($post_id, (int)$term_id, 'property_status');
        if (is_wp_error($set_result)) {
            throw new \Exception("绑定 property_status 失败：" . $set_result->get_error_message());
        }
    }

    private function assign_property_type(int $post_id, array $listing): void
    {
        $property_type = $listing['property_type'] ?? '';
        $taxonomy = 'property_type';

        if (empty($property_type)) {
            error_log('❌ 未提供 property_type，跳过设置。');
            return;
        }

        // 查找已有的术语（根据 slug）
        $term = get_term_by('slug', $property_type, $taxonomy);

        if (!$term) {
            error_log("ℹ️ Property Type '{$property_type}' 不存在，尝试创建");

            // 构造一个人类可读的名称
            $name = ucfirst(str_replace('-', ' ', $property_type));
            $result = wp_insert_term($name, $taxonomy, ['slug' => $property_type]);

            if (is_wp_error($result)) {
                error_log("❌ 无法创建 property_type '{$property_type}'：{$result->get_error_message()}");
                return;
            }

            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }

        // 绑定 term 到文章
        $set_result = wp_set_object_terms($post_id, intval($term_id), $taxonomy);
        if (is_wp_error($set_result)) {
            error_log("❌ 绑定 property_type 分类失败：" . $set_result->get_error_message());
        } else {
            error_log("✅ 成功绑定 property_type '{$property_type}' 到文章 {$post_id}");
        }
    }


    private function update_property_features(int $post_id, array $listing): void
    {
        $taxonomy = 'property_feature';
        $features = json_decode($listing['features'] ?? '[]', true);
        $additional = [];
        $term_ids = [];

        if (!empty($features) && is_array($features)) {
            foreach ($features as $feature_key => $feature_value) {
                if (empty($feature_value)) {
                    continue; // 跳过空值或 false
                }

                // 格式化：airConditioning -> Air Conditioning
                $title = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $feature_key));
                $value = (string)$feature_value;

                $additional[] = [
                    'fave_additional_feature_title' => $title,
                    'fave_additional_feature_value' => $value,
                ];

                // 特征名作为 term 名
                $term_name = ucwords(str_replace('_', ' ', $feature_key));
                error_log('features term name: ' . $term_name);
                $term = get_term_by('name', $term_name, $taxonomy);

                if (!$term) {
                    $result = wp_insert_term($term_name, $taxonomy);
                    if (is_wp_error($result)) {
                        error_log("无法创建术语 '{$term_name}': " . $result->get_error_message());
                        continue;
                    }
                    $term_id = $result['term_id'];
                } else {
                    $term_id = $term->term_id;
                }

                $term_ids[] = (int)$term_id;
            }

            if (!empty($term_ids)) {
                // 追加方式保留原有分类
                wp_set_object_terms($post_id, $term_ids, $taxonomy, true);
            }
        }

        // 设置 additional_features 元字段
        if (!empty($additional)) {
            update_post_meta($post_id, 'additional_features', $additional);
            update_post_meta($post_id, 'fave_additional_features_enable', 'enable');
        } else {
            update_post_meta($post_id, 'fave_additional_features_enable', 'disable');
        }
    }

    private function update_property_location(int $post_id, array $listing): void
    {
        $state    = isset($listing['state']) ? strtoupper(trim($listing['state'])) : '';
        $country  = isset($listing['country']) ? strtoupper(trim($listing['country'])) : 'Australia';
        $suburb   = isset($listing['suburb']) ? trim($listing['suburb']) : '';
        $street   = isset($listing['street']) ? trim($listing['street']) : '';
        $postcode = isset($listing['postcode']) ? trim($listing['postcode']) : '';
        $area     = isset($listing['area']) ? trim($listing['area']) : '';
        // $city = !empty($listing['city'])
          //  ? trim($listing['city'])
           // : $this->resolve_city_by_postcode($listing['postcode'] ?? '');
        $city = null;

        if (!empty($listing['postcode'])) {
            $city = $this->resolve_city_by_postcode(trim($listing['postcode']));

            if ($city) {
                error_log("[JIWU] 根据邮编 {$listing['postcode']} 解析出的城市名称为：{$city}");
            } else {
                error_log("[JIWU] 无法根据邮编 {$listing['postcode']} 找到城市名称");
            }
        }


        // 定义 taxonomy 与值的映射关系
        $taxonomy_map = [
            'property_state'   => $state,
            'property_city'    => $city,
            'property_country' => $country,
            'property_area'    => $area,
        ];

        foreach ($taxonomy_map as $taxonomy => $value) {
            if (!empty($value)) {
                $term = term_exists($value, $taxonomy);
                if (!$term) {
                    $term = wp_insert_term($value, $taxonomy);
                }
                if (!is_wp_error($term)) {
                    wp_set_object_terms($post_id, (int)$term['term_id'], $taxonomy);
                } else {
                    error_log("无法设置 taxonomy {$taxonomy} 的术语 {$value}：" . $term->get_error_message());
                }
            }
        }

        // 更新 meta 字段（基础地址信息）
        update_post_meta($post_id, 'fave_property_city', $city);
        update_post_meta($post_id, 'fave_property_state', $state);
        update_post_meta($post_id, 'fave_property_zip', $postcode);
        update_post_meta($post_id, 'fave_property_area', $area);
        update_post_meta($post_id, 'fave_property_country', $country);

        update_post_meta($post_id, 'fave_property_map', 1);
        update_post_meta($post_id, 'fave_property_map_street_view', 'show');
        update_post_meta($post_id, 'fave_property_map_address', $this->display_address);
        update_post_meta($post_id, 'fave_property_address', $this->display_address); // fallback 兼容原来的 street-only 设置

        // Featured 状态默认关闭
        update_post_meta($post_id, 'fave_featured', '');

        // 经纬度坐标（如果有）
        $latitude  = $listing['latitude'] ?? '';
        $longitude = $listing['longitude'] ?? '';

        if (!empty($latitude) && !empty($longitude)) {
            update_post_meta($post_id, 'fave_property_location', "{$latitude},{$longitude},14");
        } else {
            error_log("房源缺少坐标信息，未设置 fave_property_location");
            $coords = $this->geocode_address($this->display_address);
            if ($coords) {
                update_post_meta($post_id, 'fave_property_location', "{$coords['lat']},{$coords['lng']},14");
                error_log("get geocode latitude & longitude" . print_r($coords, true));
            }
        }
    }

    /**
     * 通过 postcode 反查已导入的 property_city term 名称（suburb/city）
     *
     * @param string $postcode
     * @return string|null 返回匹配到的城市名，未找到返回 null
     */
    private function resolve_city_by_postcode(string $postcode): ?string
    {
        global $wpdb;

        // 查询 term_id
        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta}
         WHERE meta_key = 'fave_city_postcode' AND meta_value = %s
         LIMIT 1",
            $postcode
        ));

        if (!$term_id) {
            return null;
        }

        // 查询 term 名称
        $term = get_term($term_id, 'property_city');
        return ($term && !is_wp_error($term)) ? $term->name : null;
    }

    private function geocode_address(string $address): ?array
    {
        if (!defined('JIWU_GOOGLE_GEOCODE_API_KEY')) {
            error_log('❌ 地理编码 API KEY 未定义 (JIWU_GOOGLE_GEOCODE_API_KEY)');
            return null;
        }

        $api_key = JIWU_GOOGLE_GEOCODE_API_KEY;
        $encoded_address = urlencode($address);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$encoded_address}&key={$api_key}";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            error_log("地理编码请求失败：" . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['status']) && $data['status'] === 'OK') {
            $location = $data['results'][0]['geometry']['location'];
            return [
                'lat' => $location['lat'],
                'lng' => $location['lng']
            ];
        } else {
            error_log("地理编码失败：{$address}");
            return null;
        }
    }

    /**
     * 处理房源图片，集成 EXMAGE 插件上传并设置为特色图和图库
     *
     * @param array $listing
     * @param int $post_id
     * @return void
     */
    private function handle_property_images(int $post_id, array $listing): void
    {
        if (empty($listing['images'])) {
            error_log("📷 图片字段为空，跳过图像处理。");
            return;
        }

        $images = json_decode($listing['images'], true);
        if (!is_array($images) || empty($images)) {
            error_log("📷 图片解析失败或格式错误：" . print_r($listing['images'], true));
            return;
        }

        $media_ids = [];

        foreach ($images as $img_url) {
            if (empty($img_url) || !filter_var($img_url, FILTER_VALIDATE_URL)) {
                error_log("⚠️ 无效图片 URL：" . print_r($img_url, true));
                continue;
            }

            $image_id = 0;
            $result = EXMAGE_WP_IMAGE_LINKS::add_image($img_url, $image_id, $post_id);

            if (
                isset($result['status']) &&
                (
                    ($result['status'] === 'success' && isset($result['id'])) ||
                    ($result['status'] === 'error' && $result['message'] === 'Image exists' && isset($result['id']))
                )
            ) {
                $media_ids[] = $result['id'];
            } else {
                error_log("❌ EXMAGE 添加图片失败：" . ($result['message'] ?? '未知错误'));
            }
        }

        // 设置特色图像和图库
        if (!empty($media_ids)) {
            set_post_thumbnail($post_id, $media_ids[0]);

            // 先清除旧的 image meta
            delete_post_meta($post_id, 'fave_property_images');

            foreach ($media_ids as $mid) {
                add_post_meta($post_id, 'fave_property_images', $mid);
            }

            error_log("✅ 已处理图片数量：" . count($media_ids));
        } else {
            error_log("⚠️ 没有可用图片添加到房源中。");
        }
    }

    private function handle_floor_plans(int $post_id, array $listing): void
    {
        if (empty($listing['floor_plan'])) {
            update_post_meta($post_id, 'fave_floor_plans_enable', 'disable');
            error_log("📐 没有 floor_plan 字段内容，已禁用户型图显示。");
            return;
        }

        $floorplan_urls = json_decode($listing['floor_plan'], true);

        if (!is_array($floorplan_urls) || empty($floorplan_urls)) {
            update_post_meta($post_id, 'fave_floor_plans_enable', 'disable');
            error_log("📐 floor_plan 解码失败或为空：" . print_r($listing['floor_plan'], true));
            return;
        }

        $floorplans = [];

        foreach ($floorplan_urls as $plan_url) {
            if (filter_var($plan_url, FILTER_VALIDATE_URL)) {
                $floorplans[] = [
                    'fave_plan_title' => __('Floorplan', 'jiwu-import'),
                    'fave_plan_image' => $plan_url,
                ];
            } else {
                error_log("❌ 无效的户型图 URL: " . $plan_url);
            }
        }

        if (!empty($floorplans)) {
            update_post_meta($post_id, 'floor_plans', $floorplans);
            update_post_meta($post_id, 'fave_floor_plans_enable', 'enable');
            error_log("✅ 成功写入户型图信息，共 " . count($floorplans) . " 张。");
        } else {
            update_post_meta($post_id, 'fave_floor_plans_enable', 'disable');
            error_log("⚠️ 没有有效的户型图可写入，已禁用。");
        }
    }

    private function handle_agency_and_agents(int $post_id, array $listing): void {
        $agency = json_decode($listing['agency']);   // 返回 stdClass 对象
        $agents = json_decode($listing['agents']);

        // 1. 确保 agents 数据存在
        if (empty($agents) || !is_array($agents) || count($agents) === 0) {
            error_log("Property {$post_id}: No agents to process.");
            return;
        }

        $agency_id = 0;
        $agency_name = null;
        // 2. 处理 Agency
        if (!empty($agency) && !empty($agency->name)) {
            $agency_name = sanitize_text_field($agency->name);
            // 尝试通过标题查找已存在的 agency
            $existing = get_page_by_title($agency_name, OBJECT, 'houzez_agency');
            if ($existing && !is_wp_error($existing)) {
                $agency_id = $existing->ID;
                error_log("Agency found: '{$agency_name}' (ID {$agency_id}).");
            } else {
                // 不存在则创建新 agency
                $new_agency = array(
                    'post_title'  => $agency_name,
                    'post_status' => 'publish',
                    'post_type'   => 'houzez_agency'
                );
                $agency_id = wp_insert_post($new_agency);
                if (is_wp_error($agency_id) || !$agency_id) {
                    error_log("Failed to create agency '{$agency_name}' for property {$post_id}.");
                    $agency_id = 0;
                } else {
                    error_log("Created new agency '{$agency_name}' (ID {$agency_id}).");
                    if (!empty($agency->address)) {
                        update_post_meta($agency_id, 'fave_agency_address', sanitize_text_field($agency->address));
                    }

                    if (!empty($agency->agency_url) && class_exists('EXMAGE_WP_IMAGE_LINKS')) {
                        $agency_logo_url = esc_url_raw($agency->agency_url);
                        $image_id = 0;

                        $result = EXMAGE_WP_IMAGE_LINKS::add_image($agency_logo_url, $image_id, $agency_id);
                        if ($this->is_exmage_success($result)) {
                            $attachment_id = $result['id'];

                            // 设置特色图像
                            set_post_thumbnail($agency_id, $attachment_id);

                            // 强制设置 post_parent，不论图片是否重复上传
                            wp_update_post([
                                'ID' => $attachment_id,
                                'post_parent' => $agency_id,
                            ]);

                            error_log("✅ 设置 agency logo {$agency_logo_url} 到 {$agency_name} (ID {$agency_id})");
                        } else {
                            error_log("❌ EXMAGE 添加 agency logo 失败: " . ($result['message'] ?? '未知错误'));
                        }
                    }

                }
            }
        }

        // 3. 处理 agents 数组
        $added_agent_ids = array();
        foreach ($agents as $agent_data) {
            $agent_name  = !empty($agent_data->name)  ? sanitize_text_field($agent_data->name)  : '';
            $agent_phone = !empty($agent_data->phone) ? sanitize_text_field($agent_data->phone) : '';
            if (empty($agent_name)) {
                error_log("Skipping agent with empty name in property {$post_id}.");
                continue;
            }
            $agent_id = 0;
            // 3a. 先通过电话查找
            if (!empty($agent_phone)) {
                $args = array(
                    'post_type'   => 'houzez_agent',
                    'post_status' => 'publish',
                    'meta_query'  => array(
                        'relation' => 'OR',
                        array('key' => 'fave_agent_office_num', 'value' => $agent_phone),
                        array('key' => 'fave_agent_mobile',     'value' => $agent_phone)
                    ),
                    'posts_per_page' => 1,
                    'fields' => 'ids'
                );
                $found = get_posts($args);
                if (!empty($found)) {
                    $agent_id = $found[0];
                }
            }
            // 3b. 电话查找失败时，通过名字查找
            if (!$agent_id) {
                $existing_agent = get_page_by_title($agent_name, OBJECT, 'houzez_agent');
                if ($existing_agent && !is_wp_error($existing_agent)) {
                    $agent_id = $existing_agent->ID;
                }
            }
            // 3c. 若不存在，则创建 agent
            if (!$agent_id) {
                $new_agent = array(
                    'post_title'  => $agent_name,
                    'post_status' => 'publish',
                    'post_type'   => 'houzez_agent'
                );
                $agent_id = wp_insert_post($new_agent);
                if (is_wp_error($agent_id) || !$agent_id) {
                    error_log("Failed to create agent '{$agent_name}' for property {$post_id}.");
                    continue;
                }
                error_log("Created agent '{$agent_name}' (ID {$agent_id}).");
                // 添加代理人电话元数据
                if (!empty($agent_phone)) {
                    update_post_meta($agent_id, 'fave_agent_office_num', $agent_phone);
                }
                // 若有移动电话可使用 add_post_meta($agent_id, 'fave_agent_mobile', $mobile_phone);
                // 其它元字段（如邮箱等）也可在此处添加
            } else {
                error_log("Agent '{$agent_name}' already exists (ID {$agent_id}).");
            }

            // 4. 关联 Agency 与 Property
            if ($agency_id) {
                update_post_meta($agent_id, 'fave_agent_agencies', $agency_id);
                update_post_meta($agent_id, 'fave_agent_position', 'Company Agent');
            }

            if ($agency_id && !empty($agency_name)) {
                update_post_meta($agent_id, 'fave_agent_company', $agency_name);
            }

            $added_agent_ids[] = $agent_id;

            // 5. 处理代理人头像（使用 EXMAGE）
            if (!empty($agent_data->photo_url) && class_exists('EXMAGE_WP_IMAGE_LINKS')) {
                $image_url = esc_url_raw($agent_data->photo_url);
                $image_id = 0;
                $result = EXMAGE_WP_IMAGE_LINKS::add_image($image_url, $image_id, $agent_id);

                if ($this->is_exmage_success($result)) {
                    $attachment_id = $result['id'];

                    // 将图片设置为代理人的特色图像
                    set_post_thumbnail($agent_id, $attachment_id);

                    // 始终强制设置 post_parent，确保 thumbnail 能正确渲染
                    wp_update_post([
                        'ID' => $attachment_id,
                        'post_parent' => $agent_id,
                    ]);

                    error_log("Set photo for agent ID {$agent_id} from URL '{$image_url}'.");
                } else {
                    error_log("Failed to set photo for agent ID {$agent_id} from URL '{$image_url}': " . ($result['message'] ?? 'unknown error'));
                }
            }
        }

        // 6. 设置代理人显示选项
        if (!empty($added_agent_ids)) {
            delete_post_meta($post_id, 'fave_agents');

            foreach ($added_agent_ids as $agent_id) {
                add_post_meta($post_id, 'fave_agents', $agent_id);
            }

            update_post_meta($post_id, 'fave_agent_display_option', 'agent_info');
        }
    }

    private function is_exmage_success(array $result): bool {
        return isset($result['id']) && (
                (isset($result['status']) && $result['status'] === 'success') ||
                (isset($result['status']) && $result['status'] === 'error' && $result['message'] === 'Image exists')
            );
    }

    private function handle_update_price_guide_pdf(int $post_id, array $listing): void
    {
        if (!function_exists('update_field')) {
            error_log('[JIWU] ACF 插件未启用，update_field 函数不存在');
            return;
        }

        if (empty($listing['statement_pdf'])) {
            error_log('[JIWU] statement_pdf 字段为空');
            return;
        }

        // JSON 解码
        $json_string = $listing['statement_pdf'];
        $decoded_urls = json_decode($json_string, true);

        if (!is_array($decoded_urls) || empty($decoded_urls)) {
            error_log('[JIWU] statement_pdf JSON 解码失败或不是有效数组: ' . $json_string);
            return;
        }

        $first_url = $decoded_urls[0] ?? '';

        if (empty($first_url) || !filter_var($first_url, FILTER_VALIDATE_URL)) {
            error_log('[JIWU] statement_pdf 中第一个 URL 无效: ' . $first_url);
            return;
        }

        // 写入 ACF 自定义字段
        update_field('price_guide_pdf', esc_url_raw($first_url), $post_id);
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
}
