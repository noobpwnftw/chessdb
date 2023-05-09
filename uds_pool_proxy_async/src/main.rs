use tokio::sync::oneshot;

#[tokio::main(flavor = "multi_thread")]
async fn main() -> std::io::Result<()> {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 5 {
        eprintln!("Usage: {} <socket_path> <pool_size> <clean_marker> <backend> [backend_args...]", args[0]);
        std::process::exit(1);
    }

    let socket_path = args[1].clone();
    let pool_size: usize = args[2].parse().unwrap_or_else(|_| {
        eprintln!("Invalid pool size: {}", args[2]);
        std::process::exit(1);
    });
    let clean_marker = args[3].clone();
    let backend_argv: Vec<String> = args[4..].to_vec();

    let (tx, rx) = oneshot::channel::<()>();
    tokio::spawn(async move {
        let _ = tokio::signal::ctrl_c().await;
        let _ = tx.send(());
    });

    uds_pool_proxy_async::run_server(socket_path, pool_size, backend_argv, clean_marker, rx).await
}
