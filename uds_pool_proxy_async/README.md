# uds_pool_proxy_async (clean-marker version)

Async Unix-domain socket proxy that pools persistent backends (e.g. `cat`), keeps stdin/stdout open, allows concurrent sessions, and **recycles a backend only if the client sent an explicit clean marker** during the session.

## Build
```bash
cargo build --release
```

## Run (CLI)
```
uds_pool_proxy_async <socket_path> <pool_size> <clean_marker> <backend> [backend_args...]
```

### Example
```bash
sudo ./target/release/uds_pool_proxy_async /run/uds-pool-proxy/proxy.sock 4 "#CLEAN" cat
```

## Client signaling (PHP)
Send the marker as a **standalone line** before closing:
```php
fwrite($sock, "#CLEAN\n");
fclose($sock);
```
The proxy will **not forward** this line to the backend and will recycle the backend for reuse.
