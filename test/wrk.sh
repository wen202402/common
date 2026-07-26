wrk -t4 -c50 -d5s "http://127.0.0.1:58000/login/login/test"

wrk：

-t4：线程数（Threads）。使用 4 个操作系统线程来发起请求。

-c50：连接数（Connections）。维持共 50 个 HTTP 长连接（在 4 个线程中分配）。

-d5s：压测持续时间（Duration）。测试总共持续 5 秒钟（s 代表秒）。

