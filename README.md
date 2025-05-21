# distribution jobs for importing properties

This is a distribution jobs. When my spider scrapy lots of data to import properties,

we must quickly process every listing. That was too slow in my old version when I used WpCron function in WordPress frameworks.

so I design this distribution framework and asynchronous jobs. And it is very fast by multiple processess.

And it's depend on mysql and redis.

## framework:

distribution framework.

![djobs](djobs.png)

## functions:

- dispatcher waiting for fetching data from mysql.
- dispatcher from mysql to redis and set status and locked_by, locked_time.
- dispatcher push to redis.
- workers pop the data from redis.
- workers get the data and process data.
- workers set status.
- workers waiting forever.

## essentials:

### database

May be added any columns. 

```sql
ALTER TABLE `wp_rent_listings`
  ADD COLUMN `locked_by` VARCHAR(64) NULL,
  ADD COLUMN `lock_time` DATETIME NULL,
  ALGORITHM=INSTANT;
```

### `status` meaning:

- 0: init.
- 1: processing (dispatcher load and push to redis).
- 2: success (worker inserted data to wp_posts).
- 3: failed (worker failed to parse and insert data to wp_posts).

### supervisor:

```bash
supervisorctl reread
supervisorctl update
```

about conf setting:

```ini
[program:jiwu_worker]
command=php /jiwu/plugins/worker.php
numprocs=3
process_name=%(program_name)s_%(process_num)02d
```

- numprocs: do you want to start `numprocs` workers programs at the same time? default=1
- process_name: do you want to rename the process name?

    ```bash
    jiwu_worker_00
    jiwu_worker_01
    jiwu_worker_02
    ```
  
    ```ini
    [program:jiwu_worker]
    command=php /path/to/worker.php
    numprocs=5
    process_name=%(program_name)s_%(process_num)02d
    autostart=true
    autorestart=true
    stdout_logfile=/var/log/jiwu_worker_%(process_num)02d.log
    stderr_logfile=/var/log/jiwu_worker_%(process_num)02d_error.log
    ```