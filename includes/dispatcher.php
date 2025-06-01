<?php

function jiwu_dispatch_tasks() {

    error_log('start jiwu dispatch task.');

    // 设置无限执行时间
    set_time_limit(0);

    // 引入 WordPress 环境
    require_once dirname(__FILE__, 5) . '/wp-load.php';

    // 连接 Redis
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    // 定义队列名称
    $queues = [
        'rent_listings' => 'jiwu_rent_task_queue',
        'sale_listings' => 'jiwu_sale_task_queue',
    ];

    // 获取全局 $wpdb 对象
    global $wpdb;

    while (true) {
        foreach ($queues as $table => $queue) {
            $full_table = $wpdb->prefix . $table;

            // 查询最多 500 条未处理的记录
            $rows = $wpdb->get_results("SELECT * FROM $full_table WHERE status = 0 LIMIT 500");

            foreach ($rows as $row) {
                // 标记为 processing
                $wpdb->update($full_table, [
                    'status'    => 1, // 1 = processing
                    'locked_by' => uniqid('worker_', true),
                    'lock_time' => current_time('mysql')
                ], [ 'id' => $row->id ]);

                // 推入对应的 Redis 队列
                error_log('rPush:' . $row->url);
                $redis->rPush($queue, json_encode($row));
            }
        }

        // 等待 5 秒后继续检查
        sleep(5);
    }
}
