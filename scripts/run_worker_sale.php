<?php

require_once dirname(__FILE__, 2) . '/includes/utils.php';
require_once dirname(__FILE__, 2) . '/includes/data-validator.php';
require_once dirname(__FILE__, 2) . '/includes/worker_sale_v2.php';
require_once dirname(__FILE__, 2) . '/includes/worker_translate_cn.php';

jiwu_process_sale_tasks();
