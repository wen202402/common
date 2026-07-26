ab -n 5000 -c 50 "http://127.0.0.1:58000/login/login/test"

#ab：你要执行的压测工具名称（ApacheBench）。

#-n 5000：这次总HTTP 请求数5000（Number）

#-c 50：并发数（Concurrency）