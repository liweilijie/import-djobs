<?php
/**
 * Plugin Name: Jiwu Distributed Jobs
 * Description: 使用 Redis 队列和多进程处理 rent_listings 和 listings 表中的数据。
 * Version: 1.0
 * Author: Wei Li
 */

// 防止直接访问插件文件
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 引入调度器和工作进程函数
require_once plugin_dir_path( __FILE__ ) . 'includes/dispatcher.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/worker_rent.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/worker_listing.php';

// 插件激活时的操作
function jiwu_djobs_activate() {
    // 在此处添加插件激活时的初始化代码，例如创建数据库表、设置默认选项等
}
register_activation_hook( __FILE__, 'jiwu_djobs_activate' );

// 插件停用时的操作
function jiwu_djobs_deactivate() {
    // 在此处添加插件停用时的清理代码，例如删除定时任务、清理缓存等
}
register_deactivation_hook( __FILE__, 'jiwu_djobs_deactivate' );

// 示例：在插件加载后启动任务调度器
//add_action( 'plugins_loaded', 'jiwu_dispatch_tasks' );
